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

use local_caregivertraining\local\next_activity;

/**
 * Next activity selection, from the course start and from the current activity.
 *
 * @package    local_caregivertraining
 * @copyright  2026 CI Institute of Nursing
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(next_activity::class)]
final class next_activity_test extends \advanced_testcase {
    public function test_find_from_start_and_after_current_activity(): void {
        global $CFG;
        require_once($CFG->libdir . '/completionlib.php');
        $this->resetAfterTest();
        $CFG->enablecompletion = 1;

        $gen = $this->getDataGenerator();
        $course = $gen->create_course(['enablecompletion' => 1, 'numsections' => 2]);
        $user = $gen->create_and_enrol($course, 'student');
        $manual = ['completion' => COMPLETION_TRACKING_MANUAL];
        $first = $gen->create_module('page', ['course' => $course->id, 'section' => 1] + $manual);
        $second = $gen->create_module('page', ['course' => $course->id, 'section' => 1] + $manual);
        $third = $gen->create_module('page', ['course' => $course->id, 'section' => 2] + $manual);

        $this->assertSame((int) $first->cmid, (int) next_activity::find($course, (int) $user->id)->id);
        $this->assertSame((int) $second->cmid, (int) next_activity::find($course, (int) $user->id, (int) $first->cmid)->id);

        $completion = new \completion_info($course);
        $completion->update_state(get_fast_modinfo($course)->get_cm($second->cmid), COMPLETION_COMPLETE, $user->id);

        $this->assertSame((int) $first->cmid, (int) next_activity::find($course, (int) $user->id)->id);
        $this->assertSame((int) $third->cmid, (int) next_activity::find($course, (int) $user->id, (int) $first->cmid)->id);
        $this->assertNull(next_activity::find($course, (int) $user->id, (int) $third->cmid));
    }
}
