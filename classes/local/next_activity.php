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

namespace local_caregivertraining\local;

/**
 * Finds the next activity the learner can actually open, following course order, availability
 * restrictions and completion. It only links; the activity page still enforces its own access.
 *
 * @package    local_caregivertraining
 * @copyright  2026 CI Institute of Nursing
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class next_activity {
    /**
     * First visible, available, incomplete activity with completion tracking.
     *
     * @param \stdClass $course
     * @param int $userid
     * @return \cm_info|null
     */
    public static function find(\stdClass $course, int $userid): ?\cm_info {
        global $CFG;
        require_once($CFG->libdir . '/completionlib.php');
        $modinfo = get_fast_modinfo($course, $userid);
        $completion = new \completion_info($course);
        foreach ($modinfo->get_section_info_all() as $section) {
            if (!$section->uservisible) {
                continue;
            }
            foreach ($modinfo->sections[$section->section] ?? [] as $cmid) {
                $cm = $modinfo->cms[$cmid];
                if (!$cm->uservisible || !$cm->available || !$cm->has_view() || $cm->deletioninprogress) {
                    continue;
                }
                if ($completion->is_enabled($cm) == COMPLETION_TRACKING_NONE) {
                    continue;
                }
                $state = (int) $completion->get_data($cm, false, $userid)->completionstate;
                if (!in_array($state, [COMPLETION_COMPLETE, COMPLETION_COMPLETE_PASS], true)) {
                    return $cm;
                }
            }
        }
        return null;
    }
}
