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

use local_caregivertraining\local\config;
use local_caregivertraining\local\cycle_manager;
use local_caregivertraining\local\time_tracker;

/**
 * Time accounting abuse cases. Server time is injected; client clocks are never read.
 *
 * @package    local_caregivertraining
 * @copyright  2026 CI Institute of Nursing
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(time_tracker::class)]
final class time_tracker_test extends \advanced_testcase {
    /** @var \stdClass */
    private $fixture;
    /** @var \stdClass */
    private $learner;
    /** @var int */
    private $cmid;
    /** @var int */
    private $t;

    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->setAdminUser();
        $gen = $this->getDataGenerator()->get_plugin_generator('local_caregivertraining');
        $this->fixture = $gen->create_annual_course();
        $this->learner = $gen->create_learner();
        $today = (new \DateTimeImmutable('now', config::timezone()))->format('Y-m-d');
        cycle_manager::upsert(['cycleid' => 'T-1', 'userid' => $this->learner->user->id,
            'alayacareid' => $this->learner->alayacareid, 'opendate' => $today, 'duedate' => '2099-12-31']);
        $this->cmid = (int) $this->fixture->lesson->cmid;
        set_config('heartbeatseconds', 30, 'local_caregivertraining');
        set_config('idleseconds', 120, 'local_caregivertraining');
        set_config('countablecmids', (string) $this->cmid, 'local_caregivertraining');
        $this->t = time();
    }

    /**
     * Send a beat at server time $this->t + $offset.
     *
     * @param int $offset
     * @param string $token
     * @param array $state
     * @return array
     */
    private function beat(int $offset, string $token = 'a', array $state = []): array {
        $state += ['visible' => true, 'playing' => false, 'interactedago' => 3, 'cmid' => $this->cmid,
            'userid' => (int) $this->learner->user->id];
        return time_tracker::heartbeat(
            $state['userid'],
            $state['cmid'],
            str_repeat($token, 32),
            $state['visible'],
            $state['playing'],
            $state['interactedago'],
            $this->t + $offset
        );
    }

    /**
     * Total credited seconds.
     *
     * @return int
     */
    private function credited(): int {
        return time_tracker::recorded_seconds(cycle_manager::get('T-1'));
    }

    public function test_normal_engagement_is_credited_up_to_elapsed_time(): void {
        $this->assertSame('session_started', $this->beat(0)['reason']);
        $this->assertSame(30, $this->beat(30)['creditseconds']);
        $this->assertSame(30, $this->beat(60)['creditseconds']);
        $this->assertSame(60, $this->credited());
    }

    public function test_rapid_beats_cannot_inflate_time(): void {
        $this->beat(0);
        $this->assertSame('too_frequent', $this->beat(2)['reason']);
        $this->assertSame('too_frequent', $this->beat(4)['reason']);
        $total = 0;
        for ($i = 1; $i <= 12; $i++) {
            $total += $this->beat($i * 5)['creditseconds'];
        }
        $this->assertSame(60, $total, 'credit never exceeds wall-clock time');
    }

    public function test_hidden_idle_and_gaps_earn_nothing(): void {
        $this->beat(0);
        $this->assertSame('hidden', $this->beat(30, 'a', ['visible' => false])['reason']);
        $this->assertSame('resumed', $this->beat(60)['reason'], 'interval after a hidden beat is not credited');
        $this->assertSame(30, $this->beat(90)['creditseconds']);
        $this->assertSame('idle', $this->beat(120, 'a', ['interactedago' => 500])['reason']);
        $this->assertSame('resumed', $this->beat(150)['reason']);
        $this->assertSame(
            30,
            $this->beat(180, 'a', ['interactedago' => 500, 'playing' => true])['creditseconds'],
            'watching media without input is engagement'
        );
        $this->assertSame('gap', $this->beat(180 + 600)['reason'], 'sleep/disconnect earns nothing');
        $this->assertSame(60, $this->credited());
    }

    public function test_moving_to_the_next_page_keeps_the_time_on_the_previous_page(): void {
        $this->beat(0, 'a');
        $this->assertSame(30, $this->beat(30, 'a')['creditseconds']);
        $this->assertSame(20, $this->beat(50, 'b')['creditseconds'], 'tail of the previous page is credited');
        $this->assertSame(15, $this->beat(65, 'c')['creditseconds'], 'short pages still count');
        $this->assertSame(30, $this->beat(95, 'c')['creditseconds']);
        $this->assertSame(95, $this->credited());
    }

    public function test_page_change_after_hidden_idle_or_gap_earns_nothing(): void {
        $this->beat(0, 'a');
        $this->beat(30, 'a', ['visible' => false]);
        $this->assertSame(0, $this->beat(40, 'b')['creditseconds'], 'previous page was hidden');
        $this->assertSame(0, $this->beat(200, 'c')['creditseconds'], 'gap since the previous beat');
        $this->assertSame(0, $this->beat(210, 'd', ['visible' => false])['creditseconds'], 'new page is hidden');
        $this->assertSame(0, $this->beat(220, 'e')['creditseconds'], 'previous page started hidden');
        $this->assertSame(0, $this->credited());
    }

    public function test_concurrent_tabs_do_not_double_count(): void {
        $this->beat(0, 'a');
        $total = $this->beat(1, 'b')['creditseconds'];
        for ($i = 1; $i <= 10; $i++) {
            $total += $this->beat($i * 30, 'a')['creditseconds'];
            $total += $this->beat($i * 30 + 1, 'b')['creditseconds'];
        }
        $this->assertLessThanOrEqual(301, $total);
        $this->assertSame($total, $this->credited());
    }

    public function test_token_cannot_be_reused_across_users_or_activities(): void {
        $this->beat(0, 'c');
        $this->assertSame('token_mismatch', $this->beat(30, 'c', ['cmid' => (int) $this->fixture->quiz->cmid])['reason']);
        $other = $this->getDataGenerator()->get_plugin_generator('local_caregivertraining')->create_learner();
        $today = (new \DateTimeImmutable('now', config::timezone()))->format('Y-m-d');
        cycle_manager::upsert(['cycleid' => 'T-2', 'userid' => $other->user->id, 'alayacareid' => $other->alayacareid,
            'opendate' => $today, 'duedate' => '2099-12-31']);
        $this->assertSame('token_mismatch', $this->beat(30, 'c', ['userid' => (int) $other->user->id])['reason']);
        $this->expectException(\invalid_parameter_exception::class);
        time_tracker::heartbeat((int) $this->learner->user->id, $this->cmid, 'not-hex', true, false, 0);
    }

    public function test_no_credit_without_open_cycle_or_for_hidden_activity(): void {
        $outsider = $this->getDataGenerator()->create_user();
        $this->assertSame('no_open_cycle', $this->beat(0, 'd', ['userid' => (int) $outsider->id])['reason']);
        set_coursemodule_visible($this->cmid, 0);
        $this->assertSame('activity_unavailable', $this->beat(0, 'e')['reason']);
    }

    public function test_banner_duration_and_percentage(): void {
        $this->assertSame('0 mins', time_tracker::short_duration(0));
        $this->assertSame('1 min', time_tracker::short_duration(119));
        $this->assertSame('1 hr 1 min', time_tracker::short_duration(3665));
        $this->assertSame('3 hrs 58 mins', time_tracker::short_duration(14335));
        $this->assertSame('2 hrs', time_tracker::short_duration(7200));

        set_config('timepolicy', config::POLICY_TRACKED, 'local_caregivertraining');
        set_config('requiredseconds', 18000, 'local_caregivertraining');
        $this->beat(0, 'a');
        $this->beat(30, 'a');
        $cycle = cycle_manager::get('T-1');
        $this->assertSame(0, time_tracker::percent($cycle));
        $this->assertSame(
            '<strong>0 mins</strong> completed of <strong>5 hours</strong> · <strong>4 hrs 59 mins</strong> remaining',
            time_tracker::timeline($cycle)
        );
    }

    public function test_policy_controls_what_is_approved(): void {
        global $DB;
        $quizcm = (int) $this->fixture->quiz->cmid;
        $this->beat(0, 'a');
        $this->beat(30, 'a');
        $this->beat(31, 'f', ['cmid' => $quizcm]);
        $this->beat(61, 'f', ['cmid' => $quizcm]);
        $cycle = cycle_manager::get('T-1');

        set_config('timepolicy', config::POLICY_UNRESOLVED, 'local_caregivertraining');
        $this->assertSame(0, time_tracker::approved_seconds($cycle));
        $this->assertSame(61, time_tracker::recorded_seconds($cycle));

        set_config('timepolicy', config::POLICY_TRACKED, 'local_caregivertraining');
        $this->assertSame(31, time_tracker::approved_seconds($cycle), 'only countable activities count (lesson incl. its tail)');

        set_config('countablecmids', '', 'local_caregivertraining');
        $this->assertSame(0, time_tracker::approved_seconds($cycle), 'nothing counts until HR approves activities');

        set_config('countallactivities', 1, 'local_caregivertraining');
        $this->assertSame(61, time_tracker::approved_seconds($cycle), 'every activity in the annual course counts');

        $quizmodule = (int) $DB->get_field('modules', 'id', ['name' => 'quiz'], MUST_EXIST);
        set_config('excludedmodules', (string) $quizmodule, 'local_caregivertraining');
        $this->assertSame(31, time_tracker::approved_seconds($cycle), 'excluded activity types do not count');
    }
}
