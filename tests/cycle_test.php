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

/**
 * Cycle lifecycle: dates, early access, overdue-but-open, repeats, supersede, rehire, access updates.
 *
 * @package    local_caregivertraining
 * @copyright  2026 CI Institute of Nursing
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(cycle_manager::class)]
final class cycle_test extends \advanced_testcase {
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
    }

    /**
     * Local date offset from today.
     *
     * @param int $days
     * @return string
     */
    private function day(int $days): string {
        return (new \DateTimeImmutable('now', config::timezone()))->modify("{$days} days")->format('Y-m-d');
    }

    /**
     * Upsert.
     *
     * @param \stdClass $learner
     * @param string $cycleid
     * @param int $due anniversary as days from today
     * @param array $extra
     * @return array
     */
    private function cycle(\stdClass $learner, string $cycleid, int $due, array $extra = []): array {
        return cycle_manager::upsert($extra + ['cycleid' => $cycleid, 'userid' => $learner->user->id,
            'alayacareid' => $learner->alayacareid, 'hiredate' => '2020-01-01', 'anniversarydate' => $this->day($due)]);
    }

    public function test_window_is_derived_from_the_anniversary(): void {
        $a = $this->gen->create_learner();
        $cycle = $this->cycle($a, 'A-1', 0, ['anniversarydate' => '2027-06-15'])['cycle'];
        $this->assertSame('2027-06-15', $cycle['anniversarydate']);
        $this->assertSame('2027-04-16', cycle_manager::open_date('2027-06-15'));
        $this->assertSame('2027-06-29', cycle_manager::access_end_date('2027-06-15'));
        $this->assertSame(cycle_manager::start_of_day('2027-04-16'), $cycle['timeopen']);
        $this->assertSame(cycle_manager::end_of_day('2027-06-15'), $cycle['timedue']);
        $this->assertSame(cycle_manager::end_of_day('2027-06-29'), $cycle['timeaccessend']);
        $this->assertSame('scheduled', $cycle['status']);
        $ue = $this->enrolment((int) $a->user->id);
        $this->assertEquals($cycle['timeopen'], $ue->timestart);
        $this->assertEquals($cycle['timeaccessend'], $ue->timeend);
    }

    public function test_date_boundaries_in_compliance_timezone(): void {
        $tz = config::timezone();
        $this->assertSame('America/Los_Angeles', $tz->getName());
        $fmt = fn(int $t) => (new \DateTimeImmutable('@' . $t))->setTimezone($tz)->format('Y-m-d H:i:s');

        $this->assertSame('2026-03-08 00:00:00', $fmt(cycle_manager::start_of_day('2026-03-08')));
        $this->assertSame('2026-03-08 23:59:59', $fmt(cycle_manager::end_of_day('2026-03-08')));
        // The spring-forward day has 23 hours; the fall-back day has 25.
        $this->assertSame(23 * HOURSECS - 1, cycle_manager::end_of_day('2026-03-08') - cycle_manager::start_of_day('2026-03-08'));
        $this->assertSame(25 * HOURSECS - 1, cycle_manager::end_of_day('2026-11-01') - cycle_manager::start_of_day('2026-11-01'));
        $this->assertSame('2028-02-29 23:59:59', $fmt(cycle_manager::end_of_day('2028-02-29')));

        foreach (['2026-02-30', '2026-13-01', '26-01-01', '2026-1-1', '2026-01-01T00:00', ''] as $bad) {
            try {
                cycle_manager::parse_date($bad);
                $this->fail("{$bad} accepted");
            } catch (\moodle_exception $e) {
                $this->assertSame('error:invaliddate', $e->errorcode);
            }
        }
    }

    public function test_due_date_is_inclusive_and_overdue_keeps_access_until_access_end(): void {
        $a = $this->gen->create_learner();
        $cycle = (object) $this->cycle($a, 'A-1', 0)['cycle'];
        $row = cycle_manager::get('A-1');
        $this->assertSame('open', cycle_manager::compliance($row, $row->timedue));
        $this->assertSame('overdue', cycle_manager::compliance($row, $row->timedue + 1));
        $this->assertSame('open', $cycle->status);

        $b = $this->gen->create_learner();
        $overdue = $this->cycle($b, 'B-1', -1)['cycle'];
        $this->assertSame('open', $overdue['status']);
        $this->assertSame('overdue', $overdue['compliance']);
        $context = \context_course::instance($this->fixture->course->id);
        $this->assertTrue(is_enrolled($context, $b->user->id, '', true), 'overdue learners keep access for 14 days');
        $ue = $this->enrolment((int) $b->user->id);
        $this->assertEquals($overdue['timeaccessend'], $ue->timeend, 'enrolment ends 14 days after the due date');

        $c = $this->gen->create_learner();
        $expired = $this->cycle($c, 'C-1', -20)['cycle'];
        $this->assertSame('overdue', $expired['compliance']);
        $this->assertFalse(is_enrolled($context, $c->user->id, '', true), 'access ends 14 days after the due date');
    }

    public function test_no_access_before_open_date(): void {
        $a = $this->gen->create_learner();
        $result = $this->cycle($a, 'A-1', 65);
        $this->assertSame('scheduled', $result['cycle']['status']);
        $context = \context_course::instance($this->fixture->course->id);
        $this->assertFalse(is_enrolled($context, $a->user->id, '', true));
        $ue = $this->enrolment((int) $a->user->id);
        $this->assertEquals($result['cycle']['timeopen'], $ue->timestart);
        $this->assertSame(0, cycle_manager::start_due_cycles());
        $this->assertSame('scheduled', cycle_manager::get('A-1')->status);
        $this->assertNull(local\time_tracker::open_cycle((int) $a->user->id));
    }

    public function test_repeat_upsert_is_unchanged_and_conflicts_are_rejected(): void {
        $a = $this->gen->create_learner();
        $this->assertSame('created', $this->cycle($a, 'A-1', 59)['action']);
        $this->assertSame('unchanged', $this->cycle($a, 'A-1', 59)['action']);
        $this->assertSame('updated', $this->cycle($a, 'A-1', 60)['action']);

        $this->assert_error('error:cycleconflict', fn() => $this->cycle($a, 'A-2', 60));
        $this->assert_error('error:cycleconflict', fn() => $this->cycle($a, 'A-3', 90));
        $b = $this->gen->create_learner();
        $this->assert_error('error:cycleconflict', fn() => $this->cycle($b, 'A-1', 60));
        $this->assert_error('error:bindingmismatch', fn() => cycle_manager::upsert(['cycleid' => 'X-1',
            'userid' => $b->user->id, 'alayacareid' => $a->alayacareid, 'anniversarydate' => $this->day(30)]));
        $this->assert_error('error:invaliddate', fn() => $this->cycle($b, 'B-1', 0, ['anniversarydate' => '2026-02-30']));
    }

    public function test_supersede_replaces_active_cycle(): void {
        $a = $this->gen->create_learner();
        $this->cycle($a, 'A-1', 65);
        $result = $this->cycle($a, 'A-1b', 63, ['supersedescycleid' => 'A-1']);
        $this->assertSame('created', $result['action']);
        $old = cycle_manager::get('A-1');
        $this->assertSame('superseded', $old->status);
        $this->assertEquals(cycle_manager::get('A-1b')->id, $old->supersededby);
        $this->assertSame('notapplicable', cycle_manager::compliance($old));
    }

    public function test_completed_cycle_is_immutable(): void {
        $a = $this->gen->create_learner();
        $this->cycle($a, 'A-1', 30);
        $this->complete($a, 'A-1');
        $this->assertSame('unchanged', $this->cycle($a, 'A-1', 30)['action']);
        $this->assert_error('error:cycleimmutable', fn() => $this->cycle($a, 'A-1', 31));
    }

    public function test_completed_anniversary_blocks_a_new_cycle(): void {
        global $DB;
        $a = $this->gen->create_learner();
        $this->cycle($a, 'A-1', 30);
        $this->complete($a, 'A-1');
        $snapshots = $DB->count_records('local_cgt_snapshot', ['userid' => $a->user->id]);

        $this->assert_error('error:alreadycompleted', fn() => $this->cycle($a, 'A-1-DUP', 30));
        $this->assert_error('error:alreadycompleted', fn() => $this->cycle($a, 'A-1-DUP', 30, ['supersedescycleid' => 'A-1']));
        $this->assertNull(cycle_manager::get('A-1-DUP'));
        $this->assertSame('completed', cycle_manager::get('A-1')->status);
        $this->assertEquals($snapshots, $DB->count_records('local_cgt_snapshot', ['userid' => $a->user->id]),
            'finished progress is not reset');

        $this->assertSame('created', $this->cycle($a, 'A-2', 395)['action'], 'the next anniversary is still allowed');
    }

    public function test_rehire_gets_a_new_cycle_and_prior_evidence_is_kept(): void {
        global $DB;
        $a = $this->gen->create_learner();
        $this->cycle($a, 'A-2025', -5);
        $this->complete($a, 'A-2025');

        $terminated = cycle_manager::update_access(['cycleid' => 'A-2025', 'userid' => $a->user->id,
            'alayacareid' => $a->alayacareid, 'employmentstatus' => 'terminated']);
        $this->assertContains('terminated_access_unresolved', $terminated['policygates']);
        $this->assertFalse($terminated['cycle']['remindersenabled']);

        // Rehire: the adapter sends a new Cycle ID; Moodle keeps the same binding.
        $rehire = $this->cycle($a, 'A-REHIRE-2026', 30, ['hiredate' => $this->day(0)]);
        $this->assertSame('open', $rehire['cycle']['status'], $rehire['cycle']['blockedreason']);
        $this->assertSame('done', $rehire['cycle']['resetstate']);
        $this->assertSame('completed', cycle_manager::get('A-2025')->status);
        $this->assertTrue($DB->record_exists('local_cgt_snapshot', ['cycleid' => cycle_manager::get('A-2025')->id,
            'type' => 'prereset', 'verified' => 1]));
    }

    public function test_access_update_suspends_and_records_unresolved_leave_policy(): void {
        $a = $this->gen->create_learner();
        $this->cycle($a, 'A-1', 59);
        $context = \context_course::instance($this->fixture->course->id);

        $leave = cycle_manager::update_access(['cycleid' => 'A-1', 'userid' => $a->user->id, 'alayacareid' => $a->alayacareid,
            'employmentstatus' => 'leave']);
        $this->assertContains('leave_access_unresolved', $leave['policygates']);
        $this->assertTrue(is_enrolled($context, $a->user->id, '', true), 'no access change is invented for leave');

        $suspended = cycle_manager::update_access(['cycleid' => 'A-1', 'userid' => $a->user->id,
            'alayacareid' => $a->alayacareid, 'employmentstatus' => 'leave', 'enrolmentstatus' => 'suspended',
            'remindersenabled' => 0]);
        $this->assertSame([], $suspended['policygates']);
        $this->assertFalse(is_enrolled($context, $a->user->id, '', true));

        cycle_manager::update_access(['cycleid' => 'A-1', 'userid' => $a->user->id, 'alayacareid' => $a->alayacareid,
            'employmentstatus' => 'active', 'enrolmentstatus' => 'active', 'remindersenabled' => 1]);
        $this->assertTrue(is_enrolled($context, $a->user->id, '', true));
    }

    /**
     * Complete a cycle through the real gates using the nominal policy.
     *
     * @param \stdClass $learner
     * @param string $cycleid
     */
    private function complete(\stdClass $learner, string $cycleid): void {
        set_config('timepolicy', config::POLICY_NOMINAL, 'local_caregivertraining');
        set_config('nominaldurations', json_encode(array_fill_keys($this->fixture->cms, 4500)), 'local_caregivertraining');
        $this->gen->create_evidence($this->fixture, (int) $learner->user->id);
        $this->assertTrue(completion_manager::evaluate((int) cycle_manager::get($cycleid)->id));
    }

    /**
     * Manual enrolment row.
     *
     * @param int $userid
     * @return \stdClass
     */
    private function enrolment(int $userid): \stdClass {
        global $DB;
        return $DB->get_record_sql(
            'SELECT ue.* FROM {user_enrolments} ue JOIN {enrol} e ON e.id = ue.enrolid
            WHERE e.courseid = :c AND e.enrol = :m AND ue.userid = :u',
            ['c' => $this->fixture->course->id, 'm' => 'manual', 'u' => $userid],
            MUST_EXIST
        );
    }

    /**
     * Assert a moodle_exception code.
     *
     * @param string $code
     * @param callable $fn
     */
    private function assert_error(string $code, callable $fn): void {
        try {
            $fn();
        } catch (\moodle_exception $e) {
            $this->assertSame($code, $e->errorcode, $e->getMessage());
            return;
        }
        $this->fail("Expected {$code}");
    }
}
