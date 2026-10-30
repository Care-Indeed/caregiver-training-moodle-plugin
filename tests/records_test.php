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
use local_caregivertraining\local\records;

/**
 * Training records: the course total shown in an employee's history.
 *
 * @package    local_caregivertraining
 * @copyright  2026 CI Institute of Nursing
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(records::class)]
final class records_test extends \advanced_testcase {
    public function test_course_total_uses_kept_grades_for_past_years_and_the_gradebook_for_this_one(): void {
        global $CFG, $DB;
        require_once($CFG->libdir . '/gradelib.php');
        $this->resetAfterTest();
        $this->setAdminUser();
        $gen = $this->getDataGenerator()->get_plugin_generator('local_caregivertraining');
        $fixture = $gen->create_annual_course();
        $learner = $gen->create_learner();
        $due = (new \DateTimeImmutable('now', config::timezone()))->modify('+30 days')->format('Y-m-d');
        cycle_manager::upsert(['cycleid' => 'A-1', 'userid' => $learner->user->id, 'alayacareid' => $learner->alayacareid,
            'hiredate' => '2020-01-01', 'anniversarydate' => $due]);
        $cycle = cycle_manager::get('A-1');
        $this->assertSame('', records::course_total($cycle, []), 'no grade yet');

        // This year's cycle reads the gradebook.
        $courseitem = \grade_item::fetch_course_item($fixture->course->id);
        $DB->insert_record('grade_grades', ['itemid' => $courseitem->id, 'userid' => $learner->user->id, 'finalgrade' => 80,
            'rawgrademax' => 100, 'timecreated' => time(), 'timemodified' => time()]);
        $this->assertSame(
            format_float(80, 2) . ' / ' . format_float($courseitem->grademax, 2),
            records::course_total($cycle, [])
        );

        // Kept grades win, at completion over before the reset; evidence from before the cycle is ignored.
        $snapshot = fn(string $type, float $grade) => (object) ['id' => 0, 'cycleid' => $cycle->id, 'type' => $type,
            'payload' => json_encode(['grades' => [
                ['itemtype' => 'mod', 'finalgrade' => 5, 'grademax' => 10],
                ['itemtype' => 'course', 'finalgrade' => $grade, 'grademax' => 100],
            ]])];
        $this->assertSame(
            format_float(10, 2) . ' / ' . format_float(100, 2),
            records::course_total($cycle, [$snapshot('prereset', 10)])
        );
        $this->assertSame(
            format_float(92.5, 2) . ' / ' . format_float(100, 2),
            records::course_total($cycle, [$snapshot('completion', 92.5), $snapshot('prereset', 10)])
        );
        $this->assertSame(
            format_float(80, 2) . ' / ' . format_float($courseitem->grademax, 2),
            records::course_total($cycle, [$snapshot('preexisting', 10)])
        );

        // A finished cycle without kept grades has no course total.
        $cycle->status = 'completed';
        $this->assertSame('', records::course_total($cycle, []));
    }
}
