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

use local_caregivertraining\local\completion_manager;
use local_caregivertraining\local\config;
use local_caregivertraining\local\cycle_manager;
use local_caregivertraining\local\evidence;

/**
 * Proof of concept: two learners in the same annual course; snapshot and reset only learner A.
 *
 * @package    local_caregivertraining
 * @copyright  2026 CI Institute of Nursing
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(evidence::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(cycle_manager::class)]
final class reset_isolation_test extends \advanced_testcase {
    /** @var \stdClass */
    private $fixture;
    /** @var \local_caregivertraining_generator */
    private $gen;

    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->setAdminUser();
        $this->gen = $this->getDataGenerator()->get_plugin_generator('local_caregivertraining');
        $this->fixture = $this->gen->create_annual_course();
        set_config('timepolicy', config::POLICY_NOMINAL, 'local_caregivertraining');
        set_config('nominaldurations', json_encode(array_fill_keys($this->fixture->cms, 4500)), 'local_caregivertraining');
    }

    /**
     * Local date offset from today in the compliance timezone.
     *
     * @param int $days
     * @return string
     */
    private function day(int $days): string {
        return (new \DateTimeImmutable('now', config::timezone()))->modify("{$days} days")->format('Y-m-d');
    }

    /**
     * Upsert a cycle.
     *
     * @param \stdClass $learner
     * @param string $cycleid
     * @param int $open days from today
     * @param int $due days from today
     * @param array $extra
     * @return array
     */
    private function cycle(\stdClass $learner, string $cycleid, int $open, int $due, array $extra = []): array {
        return cycle_manager::upsert($extra + ['cycleid' => $cycleid, 'userid' => $learner->user->id,
            'alayacareid' => $learner->alayacareid, 'hiredate' => '2020-01-01', 'opendate' => $this->day($open),
            'duedate' => $this->day($due)]);
    }

    /**
     * Full evidence for a learner with volatile fields removed.
     *
     * @param int $userid
     * @return array
     */
    private function evidence_of(int $userid): array {
        $captured = evidence::capture($userid, $this->fixture->course, null);
        return ['data' => $captured['payload']['data'], 'certificates' => array_map(
            fn($c) => [$c['issueid'], $c['code']],
            $captured['payload']['certificates']
        )];
    }

    public function test_reset_only_learner_a_and_keep_prior_evidence(): void {
        global $DB;
        $a = $this->gen->create_learner();
        $b = $this->gen->create_learner();
        $courseid = (int) $this->fixture->course->id;

        $this->assertSame('open', $this->cycle($a, 'A-2025', -300, -10)['cycle']['status']);
        $this->assertSame('open', $this->cycle($b, 'B-2026', -20, 300)['cycle']['status']);
        $this->gen->create_evidence($this->fixture, (int) $a->user->id);
        $this->gen->create_evidence($this->fixture, (int) $b->user->id);

        $a1 = cycle_manager::get('A-2025');
        $this->assertTrue(completion_manager::evaluate((int) $a1->id), json_encode(completion_manager::check_gates($a1)));
        $a1 = cycle_manager::get('A-2025');
        $this->assertSame('completed', $a1->status);
        $this->assertNotEmpty($a1->certificatecode);

        $acountsbefore = evidence::live_counts((int) $a->user->id, $courseid);
        foreach (
            ['coursecompletion', 'cmcompletion', 'lessonattempts', 'lessongrades', 'quizattempts', 'quizgrades',
                'feedbackcompleted', 'feedbackvalues', 'modgrades', 'certissues'] as $source
        ) {
            $this->assertGreaterThan(0, $acountsbefore[$source], "learner A has {$source} evidence before reset: "
                . json_encode($acountsbefore));
        }
        $aevidencebefore = $this->evidence_of((int) $a->user->id);
        $bevidencebefore = $this->evidence_of((int) $b->user->id);
        $bfingerprint = evidence::others_fingerprint((int) $a->user->id, $courseid);
        $completionsnapshot = $DB->get_record(
            'local_cgt_snapshot',
            ['cycleid' => $a1->id, 'type' => 'completion'],
            '*',
            MUST_EXIST
        );

        // New annual cycle for A opens today: snapshot A's 2025 evidence, then reset only A.
        $result = $this->cycle($a, 'A-2026', 0, 355);
        $this->assertSame('open', $result['cycle']['status'], $result['cycle']['blockedreason']);
        $this->assertSame('done', $result['cycle']['resetstate']);

        // Learner A: no live progress, still enrolled and able to start again.
        $this->assertFalse(
            evidence::has_evidence(evidence::live_counts((int) $a->user->id, $courseid)),
            json_encode(array_filter(evidence::live_counts((int) $a->user->id, $courseid)))
        );
        $this->assertTrue(is_enrolled(\context_course::instance($courseid), $a->user->id, '', true));

        // Learner B: byte-for-byte identical evidence and fingerprint.
        $this->assertSame($bevidencebefore, $this->evidence_of((int) $b->user->id));
        $this->assertSame($bfingerprint, evidence::others_fingerprint((int) $a->user->id, $courseid));
        $this->assertSame('open', cycle_manager::get('B-2026')->status);

        // Learner A's prior evidence is preserved in a verified pre-reset snapshot with the certificate PDF.
        $prereset = $DB->get_record('local_cgt_snapshot', ['cycleid' => $a1->id, 'type' => 'prereset'], '*', MUST_EXIST);
        $this->assertEquals(1, $prereset->verified);
        $this->assertTrue(evidence::verify_snapshot($prereset));
        $payload = json_decode($prereset->payload, true);
        $this->assertSame($aevidencebefore['data'], $payload['data']);
        $this->assertSame($acountsbefore, $payload['counts']);
        $this->assertSame('A-2025', $payload['cycle']['cycleid']);
        $this->assertCount(1, $payload['certificates']);
        $this->assertSame($a1->certificatecode, $payload['certificates'][0]['code']);
        $file = get_file_storage()->get_file(
            \context_system::instance()->id,
            'local_caregivertraining',
            'snapshotcert',
            $prereset->id,
            '/',
            $payload['certificates'][0]['filename']
        );
        $this->assertNotFalse($file);
        $this->assertStringStartsWith('%PDF', $file->get_content());

        // The completion snapshot and the completed 2025 cycle record are untouched.
        $this->assertTrue(evidence::verify_snapshot($completionsnapshot));
        $a1after = cycle_manager::get('A-2025');
        $this->assertSame('completed', $a1after->status);
        $this->assertSame($a1->certificatecode, $a1after->certificatecode);
        $this->assertSame('done', $a1after->resetstate);
        $this->assertEquals(1, $DB->count_records('local_cgt_outbox', ['cycleid' => $a1->id]));
    }

    public function test_unsupported_evidence_blocks_and_leaves_progress_untouched(): void {
        global $DB;
        $a = $this->gen->create_learner();
        $courseid = (int) $this->fixture->course->id;
        $this->cycle($a, 'A-2025', -300, -10);
        $this->gen->create_evidence($this->fixture, (int) $a->user->id);
        $this->assertTrue(completion_manager::evaluate((int) cycle_manager::get('A-2025')->id));

        // A forum post is per-user data this plugin does not know how to snapshot.
        $forum = $this->getDataGenerator()->create_module('forum', ['course' => $courseid]);
        $forumgen = $this->getDataGenerator()->get_plugin_generator('mod_forum');
        $forumgen->create_discussion(['course' => $courseid, 'forum' => $forum->id, 'userid' => $a->user->id]);

        $before = evidence::live_counts((int) $a->user->id, $courseid);
        $beforedata = $this->evidence_of((int) $a->user->id);
        $result = $this->cycle($a, 'A-2026', 0, 355);

        $this->assertSame('blocked', $result['cycle']['status']);
        $this->assertStringContainsString('mod_forum', $result['cycle']['blockedreason']);
        $this->assertSame($before, evidence::live_counts((int) $a->user->id, $courseid));
        $this->assertSame($beforedata, $this->evidence_of((int) $a->user->id));
        $this->assertTrue($DB->record_exists('local_cgt_exception', ['type' => 'reset_blocked', 'status' => 'open']));

        // Engineering removes the unsupported activity and retries.
        course_delete_module($forum->cmid);
        $retried = cycle_manager::retry_blocked((int) cycle_manager::get('A-2026')->id);
        $this->assertSame('open', $retried->status, (string) $retried->blockedreason);
        $this->assertFalse(evidence::has_evidence(evidence::live_counts((int) $a->user->id, $courseid)));
    }

    public function test_failed_snapshot_blocks_cycle_without_reset(): void {
        global $DB;
        $a = $this->gen->create_learner();
        $courseid = (int) $this->fixture->course->id;
        $this->cycle($a, 'A-2025', -300, -10);
        $this->gen->create_evidence($this->fixture, (int) $a->user->id);
        $this->assertTrue(completion_manager::evaluate((int) cycle_manager::get('A-2025')->id));
        $before = evidence::live_counts((int) $a->user->id, $courseid);

        // Break certificate rendering so the snapshot cannot be captured.
        $DB->set_field('customcert', 'templateid', -1, ['id' => $this->fixture->customcert->id]);
        $result = $this->cycle($a, 'A-2026', 0, 355);

        $this->assertSame('blocked', $result['cycle']['status']);
        $this->assertSame('blocked', $result['cycle']['resetstate']);
        $this->assertSame($before, evidence::live_counts((int) $a->user->id, $courseid));
        $this->assertFalse($DB->record_exists('local_cgt_snapshot', ['type' => 'prereset']));
    }

    public function test_reset_refuses_unverified_snapshot(): void {
        global $DB;
        $a = $this->gen->create_learner();
        $courseid = (int) $this->fixture->course->id;
        $this->cycle($a, 'A-2025', -300, -10);
        $this->gen->create_evidence($this->fixture, (int) $a->user->id);
        $cycle = cycle_manager::get('A-2025');
        $snapshot = evidence::create_snapshot($cycle, 'prereset', $this->fixture->course);
        $before = evidence::live_counts((int) $a->user->id, $courseid);

        $DB->set_field('local_cgt_snapshot', 'payload', '{"tampered":true}', ['id' => $snapshot->id]);
        try {
            evidence::reset_learner((int) $a->user->id, $this->fixture->course, $snapshot);
            $this->fail('Reset must refuse a snapshot that fails verification');
        } catch (\moodle_exception $e) {
            $this->assertSame('resetfailed', $e->errorcode);
        }
        $this->assertSame($before, evidence::live_counts((int) $a->user->id, $courseid));
    }
}
