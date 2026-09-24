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
 * Adds the learner banner (due date, time, next activity) and the time tracker to annual course pages.
 *
 * @package    local_caregivertraining
 * @copyright  2026 CI Institute of Nursing
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class hook_callbacks {
    /**
     * Render the banner at the top of annual course and activity pages.
     *
     * @param \core\hook\output\before_standard_top_of_body_html_generation $hook
     */
    public static function before_standard_top_of_body_html_generation(
        \core\hook\output\before_standard_top_of_body_html_generation $hook
    ): void {
        global $DB, $PAGE, $USER;

        if (during_initial_install() || !isloggedin() || isguestuser()) {
            return;
        }
        $courseid = config::course_id();
        if (!$courseid || empty($PAGE->course->id) || (int) $PAGE->course->id !== $courseid) {
            return;
        }
        $cycles = $DB->get_records_select(
            'local_cgt_cycle',
            "userid = :u AND courseid = :c AND status IN ('scheduled', 'open', 'completed', 'blocked') AND archived = 0",
            ['u' => $USER->id, 'c' => $courseid],
            'timedue DESC',
            '*',
            0,
            1
        );
        $cycle = reset($cycles);
        if (!$cycle) {
            return;
        }

        $tz = config::timezone()->getName();
        $fmt = get_string('strftimedatefullshort', 'langconfig');
        $datestr = fn(string $date) => userdate(cycle_manager::start_of_day($date) + 12 * HOURSECS, $fmt, $tz);
        $lines = [];
        $now = time();
        switch ($cycle->status) {
            case 'scheduled':
                $lines[] = get_string('banner_scheduled', config::COMPONENT, $datestr($cycle->opendate));
                break;
            case 'completed':
                $lines[] = get_string('banner_complete', config::COMPONENT, userdate($cycle->timecompleted, $fmt, $tz));
                break;
            default:
                $lines[] = get_string('banner_due', config::COMPONENT, $datestr($cycle->duedate));
                if ($now <= $cycle->timedue) {
                    $lines[] = get_string(
                        'banner_daysremaining',
                        config::COMPONENT,
                        (int) floor(($cycle->timedue - $now) / DAYSECS)
                    );
                } else {
                    $lines[] = get_string('banner_overdue', config::COMPONENT, (int) ceil(($now - $cycle->timedue) / DAYSECS));
                }
        }

        $trackerhtml = '';
        if ($cycle->status === 'open') {
            $required = config::required_seconds();
            if (config::time_policy() === config::POLICY_UNRESOLVED) {
                $timeline = get_string(
                    'banner_time_unresolved',
                    config::COMPONENT,
                    format_time(time_tracker::recorded_seconds($cycle))
                );
            } else {
                $approved = time_tracker::approved_seconds($cycle);
                $timeline = get_string('banner_time', config::COMPONENT, (object) [
                    'approved' => format_time($approved) ?: '0',
                    'required' => format_time($required),
                    'remaining' => format_time(max(0, $required - $approved)) ?: '0',
                ]);
            }
            $trackerhtml = \html_writer::div(s($timeline), 'local-cgt-time', ['data-region' => 'local-cgt-time'])
                . \html_writer::div(get_string('banner_time_note', config::COMPONENT), 'small text-muted');

            $cm = $PAGE->cm;
            if ($cm && has_capability('local/caregivertraining:recordtime', \context_course::instance($courseid))) {
                $PAGE->requires->js_call_amd('local_caregivertraining/tracker', 'init', [[
                    'cmid' => (int) $cm->id,
                    'interval' => config::heartbeat_seconds(),
                    'policy' => config::time_policy(),
                ]]);
            }
        }

        $next = '';
        if (in_array($cycle->status, ['open', 'completed'], true)) {
            $next = \html_writer::link(
                new \moodle_url('/local/caregivertraining/next.php', ['courseid' => $courseid]),
                get_string('nextactivity', config::COMPONENT),
                ['class' => 'btn btn-primary btn-sm mt-2']
            );
        }

        $html = \html_writer::tag('strong', get_string('banner_title', config::COMPONENT)) . ' '
            . implode(' &middot; ', array_map('s', $lines)) . $trackerhtml . $next;
        $hook->add_html(\html_writer::div(
            \html_writer::div($html, 'container-fluid py-2'),
            'local-cgt-banner alert alert-info mb-0 rounded-0',
            ['role' => 'status']
        ));
    }
}
