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

use local_caregivertraining\local\admin_actions;
use local_caregivertraining\local\completion_manager;
use local_caregivertraining\local\config;
use local_caregivertraining\local\cycle_manager;
use local_caregivertraining\local\export;
use local_caregivertraining\local\profile_fields;
use local_caregivertraining\local\outbox;

/**
 * CSV export access control, archive-not-delete, privacy retention and the signed outbox.
 *
 * @package    local_caregivertraining
 * @copyright  2026 CI Institute of Nursing
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(export::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(outbox::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(privacy\provider::class)]
final class export_outbox_test extends \advanced_testcase {
    /** @var \stdClass */
    private $fixture;
    /** @var \stdClass */
    private $a;
    /** @var \stdClass */
    private $b;
    /** @var string|false */
    private $envenabled;

    protected function setUp(): void {
        parent::setUp();
        // The local stack sets this to 0; config-driven delivery is what these tests exercise.
        $this->envenabled = getenv('CAREGIVERTRAINING_ADAPTER_ENABLED');
        putenv('CAREGIVERTRAINING_ADAPTER_ENABLED');
        $this->resetAfterTest();
        $this->setAdminUser();
        $gen = $this->getDataGenerator()->get_plugin_generator('local_caregivertraining');
        $this->fixture = $gen->create_annual_course();
        set_config('timepolicy', config::POLICY_NOMINAL, 'local_caregivertraining');
        set_config('nominaldurations', json_encode(array_fill_keys($this->fixture->cms, 4500)), 'local_caregivertraining');
        $today = (new \DateTimeImmutable('now', config::timezone()))->format('Y-m-d');
        foreach (['a', 'b'] as $name) {
            $this->$name = $gen->create_learner();
            cycle_manager::upsert(['cycleid' => strtoupper($name) . '-2026', 'userid' => $this->$name->user->id,
                'alayacareid' => $this->$name->alayacareid, 'opendate' => $today, 'duedate' => '2099-01-01']);
        }
        $gen->create_evidence($this->fixture, (int) $this->a->user->id);
        completion_manager::evaluate((int) cycle_manager::get('A-2026')->id);
        outbox::$transport = null;
    }

    protected function tearDown(): void {
        outbox::$transport = null;
        if ($this->envenabled !== false) {
            putenv('CAREGIVERTRAINING_ADAPTER_ENABLED=' . $this->envenabled);
        }
        parent::tearDown();
    }

    public function test_export_requires_capability_and_filters_by_employee_or_cycle(): void {
        $this->setUser($this->a->user);
        try {
            export::rows('');
            $this->fail('learners must not export');
        } catch (\required_capability_exception $e) {
            $this->assertStringContainsString(get_capability_string('local/caregivertraining:export'), $e->getMessage());
        }

        $manager = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->role_assign('manager', $manager->id, \context_system::instance()->id);
        $this->setUser($manager);
        $columns = export::columns();
        $byemployee = export::rows($this->a->binding->payrollid);
        $this->assertNotEmpty($byemployee);
        foreach ($byemployee as $row) {
            $this->assertCount(count($columns), $row);
            $this->assertSame($this->a->alayacareid, $row[array_search('alayacareid', $columns)]);
        }
        $completionrow = array_values(array_filter(
            $byemployee,
            fn($r) => $r[array_search('snapshottype', $columns)] === 'completion'
        ))[0];
        $this->assertSame('complete', $completionrow[array_search('compliance', $columns)]);
        $this->assertSame(1, $completionrow[array_search('snapshotverified', $columns)]);
        $this->assertNotSame('', $completionrow[array_search('certificatecode', $columns)]);
        $profile = profile_fields::load((int) $this->a->user->id);
        $this->assertSame($profile[profile_fields::HCANUMBER], $completionrow[array_search('hcanumber', $columns)]);
        $this->assertSame(
            $profile[profile_fields::REGISTRATIONDATE],
            $completionrow[array_search('registrationdate', $columns)]
        );
        $byhca = export::rows($profile[profile_fields::HCANUMBER]);
        $this->assertNotEmpty($byhca);
        $this->assertSame($this->a->alayacareid, $byhca[0][array_search('alayacareid', $columns)]);

        $bycycle = export::rows('B-2026');
        $this->assertCount(1, $bycycle);
        $this->assertSame('B-2026', $bycycle[0][array_search('cycleid', $columns)]);
        $this->assertSame([], export::rows('no-such-employee'));
    }

    public function test_archive_hides_but_never_deletes(): void {
        global $DB;
        $cycle = cycle_manager::get('A-2026');
        $this->setUser($this->a->user);
        try {
            admin_actions::archive_cycle((int) $cycle->id);
            $this->fail('learners cannot archive');
        } catch (\required_capability_exception $e) {
            $this->assertStringContainsString(get_capability_string('local/caregivertraining:managecycles'), $e->getMessage());
        }
        $this->setAdminUser();
        try {
            admin_actions::archive_cycle((int) cycle_manager::get('B-2026')->id);
            $this->fail('open cycles cannot be archived');
        } catch (\moodle_exception $e) {
            $this->assertSame('error:cycleconflict', $e->errorcode);
        }
        $snapshots = $DB->count_records('local_cgt_snapshot', ['cycleid' => $cycle->id]);
        admin_actions::archive_cycle((int) $cycle->id);
        $this->assertEquals(1, $DB->get_field('local_cgt_cycle', 'archived', ['id' => $cycle->id]));
        $this->assertEquals($snapshots, $DB->count_records('local_cgt_snapshot', ['cycleid' => $cycle->id]));
    }

    public function test_privacy_deletion_respects_retention(): void {
        global $DB;
        $userid = (int) $this->a->user->id;
        $contextlist = new \core_privacy\local\request\approved_contextlist(
            $this->a->user,
            'local_caregivertraining',
            [\context_system::instance()->id]
        );
        privacy\provider::delete_data_for_user($contextlist);
        $this->assertTrue($DB->record_exists('local_cgt_cycle', ['userid' => $userid]), 'records inside retention are kept');
        $this->assertTrue($DB->record_exists('local_cgt_snapshot', ['userid' => $userid]));

        $cycle = cycle_manager::get('A-2026');
        $past = time() - 4 * YEARSECS;
        $DB->set_field('local_cgt_cycle', 'timecompleted', $past, ['id' => $cycle->id]);
        $DB->set_field('local_cgt_snapshot', 'retainuntil', $past + 3 * YEARSECS, ['cycleid' => $cycle->id]);
        privacy\provider::delete_data_for_user($contextlist);
        $this->assertFalse($DB->record_exists('local_cgt_cycle', ['userid' => $userid]));
        $this->assertFalse($DB->record_exists('local_cgt_binding', ['userid' => $userid]));
        $this->assertTrue($DB->record_exists('local_cgt_cycle', ['userid' => $this->b->user->id]));
    }

    public function test_outbox_disabled_by_default_sends_nothing(): void {
        $calls = 0;
        outbox::$transport = function () use (&$calls) {
            $calls++;
            return 200;
        };
        $this->assertFalse(outbox::deliver_pending()['enabled']);

        set_config('adapterenabled', 1, 'local_caregivertraining');
        set_config('adapterurl', 'https://adapter.example.com/moodle/events', 'local_caregivertraining');
        set_config('adaptersecret', 'test-secret-not-real', 'local_caregivertraining');
        putenv('CAREGIVERTRAINING_ADAPTER_ENABLED=0');
        $this->assertFalse(outbox::deliver_pending()['enabled'], 'environment kill switch wins over config');
        putenv('CAREGIVERTRAINING_ADAPTER_ENABLED');
        $this->assertSame(0, $calls);
    }

    public function test_outbox_signs_retries_and_does_not_resend(): void {
        global $DB;
        set_config('adapterenabled', 1, 'local_caregivertraining');
        set_config('adapterurl', 'https://adapter.example.com/moodle/events', 'local_caregivertraining');
        set_config('adaptersecret', 'test-secret-not-real', 'local_caregivertraining');
        set_config('maxattempts', 3, 'local_caregivertraining');
        $sent = [];
        $status = 503;
        outbox::$transport = function (string $url, array $headers, string $body) use (&$sent, &$status) {
            $sent[] = compact('url', 'headers', 'body');
            return $status;
        };

        $now = time();
        $this->assertSame(1, outbox::deliver_pending($now)['retrying']);
        $row = $DB->get_record('local_cgt_outbox', []);
        $this->assertSame('pending', $row->status);
        $this->assertGreaterThan($now, (int) $row->nextattempt);
        $this->assertSame(0, outbox::deliver_pending($now)['retrying'], 'backoff respected');

        $status = 409;
        $this->assertSame(1, outbox::deliver_pending((int) $row->nextattempt)['delivered']);
        $this->assertSame(0, outbox::deliver_pending(time() + DAYSECS)['delivered'], 'delivered events are not resent');

        $this->assertCount(2, $sent);
        $this->assertSame($sent[0]['headers']['X-CGT-Event-Id'], $sent[1]['headers']['X-CGT-Event-Id'], 'stable event id');
        foreach ($sent as $request) {
            $this->assertStringNotContainsString('test-secret-not-real', $request['url'] . $request['body']);
            $expected = 'v1=' . hash_hmac(
                'sha256',
                $request['headers']['X-CGT-Timestamp'] . '.' . $request['body'],
                'test-secret-not-real'
            );
            $this->assertSame($expected, $request['headers']['X-CGT-Signature']);
        }
    }

    public function test_outbox_fails_after_max_attempts_and_can_be_requeued(): void {
        global $DB;
        set_config('adapterenabled', 1, 'local_caregivertraining');
        set_config('adapterurl', 'https://adapter.example.com/moodle/events', 'local_caregivertraining');
        set_config('adaptersecret', 'test-secret-not-real', 'local_caregivertraining');
        set_config('maxattempts', 2, 'local_caregivertraining');
        outbox::$transport = fn() => throw new \RuntimeException('connection refused');

        outbox::deliver_pending(time());
        $this->assertSame(1, outbox::deliver_pending(time() + DAYSECS)['failed']);
        $row = $DB->get_record('local_cgt_outbox', []);
        $this->assertSame('failed', $row->status);
        $this->assertSame('RuntimeException', $row->lasterror);
        $this->assertTrue($DB->record_exists('local_cgt_exception', ['type' => 'outbox_failed']));

        $this->assertSame(1, outbox::requeue_failed((int) $row->cycleid));
        $this->assertSame($row->eventid, $DB->get_field('local_cgt_outbox', 'eventid', ['id' => $row->id]));
    }
}
