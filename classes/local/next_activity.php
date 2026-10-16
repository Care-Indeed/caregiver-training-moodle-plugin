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
     * @param int $aftercmid Only consider activities after this one in course order (0 = from the start)
     * @return \cm_info|null
     */
    public static function find(\stdClass $course, int $userid, int $aftercmid = 0): ?\cm_info {
        global $CFG;
        require_once($CFG->libdir . '/completionlib.php');
        $modinfo = get_fast_modinfo($course, $userid);
        $completion = new \completion_info($course);
        $skipping = $aftercmid && isset($modinfo->cms[$aftercmid]);
        foreach ($modinfo->get_section_info_all() as $section) {
            foreach ($modinfo->sections[$section->section] ?? [] as $cmid) {
                if ($skipping) {
                    $skipping = ($cmid != $aftercmid);
                    continue;
                }
                if (!$section->uservisible) {
                    continue;
                }
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

    /**
     * Whether no completion-tracked activity the learner can see on the course page, locked or not,
     * follows this one in course order.
     *
     * @param \stdClass $course
     * @param int $userid
     * @param int $cmid
     * @return bool
     */
    public static function is_last(\stdClass $course, int $userid, int $cmid): bool {
        global $CFG;
        require_once($CFG->libdir . '/completionlib.php');
        $modinfo = get_fast_modinfo($course, $userid);
        $completion = new \completion_info($course);
        $after = false;
        foreach ($modinfo->get_section_info_all() as $section) {
            foreach ($modinfo->sections[$section->section] ?? [] as $id) {
                if (!$after) {
                    $after = ($id == $cmid);
                    continue;
                }
                $cm = $modinfo->cms[$id];
                if (
                    $section->visible && $cm->is_visible_on_course_page() && $cm->has_view() && !$cm->deletioninprogress
                        && $completion->is_enabled($cm) != COMPLETION_TRACKING_NONE
                ) {
                    return false;
                }
            }
        }
        return true;
    }
}
