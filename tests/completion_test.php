<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

namespace local_caregivertraining;

use local_caregivertraining\local\certificate;
use local_caregivertraining\local\completion_manager;
use local_caregivertraining\local\config;
use local_caregivertraining\local\cycle_manager;
use local_caregivertraining\local\notifier;

/**
 * Completion gates and exactly-once side effects (certificate, event, email).
 *
 * @package    local_caregivertraining
 * @copyright  2026 CI Institute of Nursing
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(completion_manager::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(notifier::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(certificate::class)]
final class completion_test extends \advanced_testcase {
    /** @var \stdClass */
    private $fixture;
    /** @var \stdClass */
    private $learner;
    /** @var \local_caregivertraining_generator */
    private $gen;
    /** @var array<string, string|false> S3 environment variables to restore */
    private $s3env = [];

    protected function setUp(): void {
        parent::setUp();
        // The local stack points these at MinIO; tests configure storage through settings.
        foreach (['BUCKET', 'REGION', 'ENDPOINT', 'PUBLIC_URL', 'PREFIX', 'ACCESS_KEY', 'SECRET_KEY'] as $name) {
            $this->s3env[$name] = getenv("CAREGIVERTRAINING_S3_{$name}");
            putenv("CAREGIVERTRAINING_S3_{$name}");
        }
        certificate::$handler = null;
        $this->resetAfterTest();
        $this->setAdminUser();
        $this->gen = $this->getDataGenerator()->get_plugin_generator('local_caregivertraining');
        $this->fixture = $this->gen->create_annual_course();
        $this->learner = $this->gen->create_learner();
        $anniversary = (new \DateTimeImmutable('now', config::timezone()))->modify('+30 days')->format('Y-m-d');
        cycle_manager::upsert(['cycleid' => 'C-1', 'userid' => $this->learner->user->id,
            'alayacareid' => $this->learner->alayacareid, 'anniversarydate' => $anniversary]);
        set_config('nominaldurations', json_encode(array_fill_keys($this->fixture->cms, 4500)), 'local_caregivertraining');
        set_config('hrccemails', 'hr@example.com', 'local_caregivertraining');
    }

    protected function tearDown(): void {
        certificate::$handler = null;
        foreach ($this->s3env as $name => $value) {
            if ($value !== false) {
                putenv("CAREGIVERTRAINING_S3_{$name}={$value}");
            }
        }
        parent::tearDown();
    }

    /**
     * Complete the learner's cycle and send the queued completion email.
     *
     * @return array{0: \stdClass, 1: \phpunit_mailer_sink} the completed cycle and the captured emails
     */
    private function complete_and_email(): array {
        set_config('timepolicy', config::POLICY_NOMINAL, 'local_caregivertraining');
        $this->gen->create_evidence($this->fixture, (int) $this->learner->user->id);
        $this->assertTrue(completion_manager::evaluate((int) cycle_manager::get('C-1')->id));
        completion_manager::record_side_effects(cycle_manager::get('C-1'));
        $this->preventResetByRollback();
        $sink = $this->redirectEmails();
        $this->assertSame(1, notifier::send_queued());
        return [cycle_manager::get('C-1'), $sink];
    }

    public function test_completion_publishes_certificate_to_s3_and_links_it(): void {
        set_config('s3bucket', 'cgt-test', 'local_caregivertraining');
        set_config('s3publicurl', 'https://certs.example.com/', 'local_caregivertraining');
        set_config('s3accesskey', 'test-key-not-real', 'local_caregivertraining');
        set_config('s3secretkey', 'test-secret-not-real', 'local_caregivertraining');
        $uploads = [];
        certificate::$handler = function (\Aws\CommandInterface $command) use (&$uploads) {
            $uploads[] = [$command->getName(), $command['Bucket'], $command['Key'], $command['ContentType'],
                strlen((string) $command['Body'])];
            return \GuzzleHttp\Promise\Create::promiseFor(new \Aws\Result([]));
        };

        [$cycle, $sink] = $this->complete_and_email();

        $code = $cycle->certificatecode;
        $this->assertNotEmpty($uploads);
        foreach ($uploads as [$name, $bucket, $key, $type, $size]) {
            $this->assertSame('PutObject', $name);
            $this->assertSame('cgt-test', $bucket);
            $this->assertContains([$key, $type], [["certificates/{$code}.pdf", 'application/pdf'],
                ["certificates/{$code}.png", 'image/png']]);
            $this->assertGreaterThan(0, $size);
        }
        $this->assertEqualsCanonicalizing(["certificates/{$code}.pdf", "certificates/{$code}.png"],
            array_unique(array_column($uploads, 2)), 'both files are uploaded on completion');

        foreach ($sink->get_messages() as $message) {
            $body = quoted_printable_decode($message->body);
            $this->assertStringContainsString("https://certs.example.com/certificates/{$code}.pdf", $body, 'public PDF link');
            $this->assertStringContainsString("https://certs.example.com/certificates/{$code}.png", $body, 'inline preview');
            $this->assertStringNotContainsString('/mod/customcert/view.php', $body);
        }
    }

    public function test_failed_upload_falls_back_to_the_certificate_activity(): void {
        global $DB;
        set_config('s3bucket', 'cgt-test', 'local_caregivertraining');
        set_config('s3accesskey', 'test-key-not-real', 'local_caregivertraining');
        set_config('s3secretkey', 'test-secret-not-real', 'local_caregivertraining');
        certificate::$handler = fn(\Aws\CommandInterface $command) => \GuzzleHttp\Promise\Create::rejectionFor(
            new \Aws\S3\Exception\S3Exception('Access Denied', $command)
        );

        [$cycle, $sink] = $this->complete_and_email();

        $this->assertSame('completed', $cycle->status, 'storage problems never undo a completion');
        $this->assertTrue($DB->record_exists('local_cgt_exception', ['cycleid' => $cycle->id,
            'type' => 'certificate_publish_failed']));
        foreach ($sink->get_messages() as $message) {
            $body = quoted_printable_decode($message->body);
            $this->assertStringContainsString('/mod/customcert/view.php', $body);
            $this->assertStringContainsString('filename=certificate-' . $cycle->certificatecode . '.png', $message->body,
                'still attached');
        }
    }

    public function test_unresolved_time_policy_blocks_completion(): void {
        $this->gen->create_evidence($this->fixture, (int) $this->learner->user->id);
        $cycle = cycle_manager::get('C-1');
        $this->assertSame(config::POLICY_UNRESOLVED, config::time_policy());
        $gates = completion_manager::check_gates($cycle);
        $this->assertFalse($gates['passed']);
        $this->assertSame(['time_policy_unresolved'], $gates['reasons']);
        $this->assertFalse(completion_manager::evaluate((int) $cycle->id));
        $this->assertSame('open', cycle_manager::get('C-1')->status);
    }

    public function test_time_requirement_and_course_completion_are_required(): void {
        set_config('timepolicy', config::POLICY_TRACKED, 'local_caregivertraining');
        $cycle = cycle_manager::get('C-1');
        $gates = completion_manager::check_gates($cycle);
        $this->assertContains('course_not_complete', $gates['reasons']);
        $this->assertContains('time_requirement_not_met', $gates['reasons']);
    }

    public function test_completion_issues_exactly_one_certificate_event_and_email(): void {
        global $DB;
        set_config('timepolicy', config::POLICY_NOMINAL, 'local_caregivertraining');
        $this->gen->create_evidence($this->fixture, (int) $this->learner->user->id);
        $issuesbefore = $DB->count_records('customcert_issues', ['userid' => $this->learner->user->id]);
        $cycle = cycle_manager::get('C-1');

        $this->assertTrue(completion_manager::evaluate((int) $cycle->id));
        $this->assertFalse(completion_manager::evaluate((int) $cycle->id), 'repeat evaluation is a no-op');
        completion_manager::reconcile_all();
        completion_manager::record_side_effects(cycle_manager::get('C-1'));

        $cycle = cycle_manager::get('C-1');
        $this->assertSame('completed', $cycle->status);
        $this->assertSame(config::POLICY_NOMINAL, $cycle->timepolicy);
        $this->assertEquals(18000, $cycle->approvedseconds);
        $this->assertSame(
            $issuesbefore,
            $DB->count_records('customcert_issues', ['userid' => $this->learner->user->id]),
            'the certificate earned in this cycle is reused, not duplicated'
        );
        $this->assertEquals(1, $DB->count_records('local_cgt_outbox', ['cycleid' => $cycle->id]));
        $this->assertEquals(1, $DB->count_records('local_cgt_notification', ['cycleid' => $cycle->id, 'type' => 'completion']));
        $this->assertEquals(1, $DB->count_records('local_cgt_snapshot', ['cycleid' => $cycle->id, 'type' => 'completion',
            'verified' => 1]));

        $event = json_decode($DB->get_field('local_cgt_outbox', 'payload', ['cycleid' => $cycle->id]), true);
        $this->assertSame('caregivertraining.v1', $event['contract']);
        $this->assertSame('cycle.completed', $event['type']);
        $this->assertSame('C-1', $event['data']['cycleid']);
        $this->assertSame($this->learner->alayacareid, $event['data']['alayacareid']);
        $this->assertSame($this->learner->binding->payrollnumber, $event['data']['payrollnumber']);
        $this->assertSame((int) $this->learner->user->id, $event['data']['moodleuserid']);
        $this->assertSame($cycle->certificatecode, $event['data']['certificate']['code']);
        $this->assertSame((int) $cycle->certificateissueid, $event['data']['certificate']['issueid']);
        $this->assertSame(
            (new \moodle_url('/mod/customcert/verify_certificate.php', [
                'contextid' => \context_module::instance($this->fixture->customcert->cmid)->id,
                'code' => $cycle->certificatecode,
            ]))->out(false),
            $event['data']['certificate']['verifyurl']
        );
        $this->assertTrue($event['data']['ontime']);

        $this->preventResetByRollback();
        $sink = $this->redirectEmails();
        $this->assertSame(1, notifier::send_queued());
        $this->assertSame(0, notifier::send_queued(), 'no duplicate completion email');
        $recipients = array_map(fn($m) => $m->to, $sink->get_messages());
        sort($recipients);
        $this->assertSame(['hr@example.com', $this->learner->user->email], $recipients);
        foreach ($sink->get_messages() as $message) {
            $body = quoted_printable_decode($message->body);
            $this->assertStringContainsString('filename=certificate-' . $cycle->certificatecode . '.png', $message->body,
                'certificate PNG attached');
            $this->assertStringContainsString('Content-Type: image/png', $message->body);
            $this->assertStringNotContainsString('downloadown', $body, 'emailed links cannot download directly');
            $this->assertStringContainsString(get_string('email_heading_completion', 'local_caregivertraining'), $body);
            $this->assertSame(
                $message->to === 'hr@example.com',
                str_contains($body, 'HR copy of the email sent to'),
                'only the HR copy carries the HR banner'
            );
        }
    }

    public function test_certificate_is_issued_when_missing(): void {
        global $DB;
        set_config('timepolicy', config::POLICY_NOMINAL, 'local_caregivertraining');
        $this->gen->create_evidence($this->fixture, (int) $this->learner->user->id);
        $DB->delete_records('customcert_issues', ['userid' => $this->learner->user->id]);
        $this->assertTrue(completion_manager::evaluate((int) cycle_manager::get('C-1')->id));
        $this->assertEquals(1, $DB->count_records('customcert_issues', ['userid' => $this->learner->user->id]));
    }

    public function test_course_completed_event_queues_evaluation(): void {
        set_config('timepolicy', config::POLICY_NOMINAL, 'local_caregivertraining');
        $this->gen->create_evidence($this->fixture, (int) $this->learner->user->id);
        $this->expectOutputRegex('/.*/');
        $this->runAdhocTasks(\local_caregivertraining\task\evaluate_cycle::class);
        $this->assertSame('completed', cycle_manager::get('C-1')->status);
    }

    public function test_reminders_follow_configuration_and_stop_when_terminated(): void {
        global $DB;
        $cycle = cycle_manager::get('C-1');
        $duestart = cycle_manager::start_of_day($cycle->anniversarydate);
        $this->assertSame(1, notifier::queue_due_notifications(), 'window-open only; no default reminder schedule');
        $this->assertSame(0, notifier::queue_due_notifications($duestart - 10 * DAYSECS));

        set_config('reminderoffsets', '14,7', 'local_caregivertraining');
        $this->assertSame(1, notifier::queue_due_notifications($duestart - 10 * DAYSECS));
        $this->assertSame(0, notifier::queue_due_notifications($duestart - 10 * DAYSECS));
        $this->assertTrue($DB->record_exists('local_cgt_notification', ['cycleid' => $cycle->id, 'occurrence' => 'd14']));

        cycle_manager::update_access(['cycleid' => 'C-1', 'userid' => $this->learner->user->id,
            'alayacareid' => $this->learner->alayacareid, 'employmentstatus' => 'terminated', 'enrolmentstatus' => 'suspended']);
        $this->assertSame(0, notifier::queue_due_notifications($duestart - 3 * DAYSECS));
    }
}
