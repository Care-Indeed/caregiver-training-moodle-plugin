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
use local_caregivertraining\local\next_activity;
use local_caregivertraining\local\time_tracker;

/**
 * Adds the learner banner (due date, time), the Next activity button and the time tracker to annual course pages.
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
        global $OUTPUT, $PAGE;

        $cycle = self::current_cycle();
        if (!$cycle) {
            return;
        }
        $courseid = (int) $cycle->courseid;

        $tz = config::timezone()->getName();
        $fmt = get_string('strftimedue', config::COMPONENT);
        $datestr = fn(string $date) => userdate(cycle_manager::start_of_day($date) + 12 * HOURSECS, $fmt, $tz);
        $bold = fn(string $text) => \html_writer::tag('strong', s($text));
        $lines = [];
        $now = time();
        switch ($cycle->status) {
            case 'scheduled':
                $lines[] = get_string('banner_scheduled', config::COMPONENT,
                    $bold($datestr(cycle_manager::open_date($cycle->anniversarydate))));
                break;
            case 'completed':
                $lines[] = get_string('banner_complete', config::COMPONENT, $bold(userdate($cycle->timecompleted, $fmt, $tz)));
                break;
            default:
                $lines[] = get_string('banner_due', config::COMPONENT, $bold($datestr($cycle->anniversarydate)));
                if ($now <= $cycle->timedue) {
                    $days = (int) floor(($cycle->timedue - $now) / DAYSECS);
                    $key = $days === 1 ? 'banner_dayremaining' : 'banner_daysremaining';
                    $lines[] = $bold(get_string($key, config::COMPONENT, $days));
                } else {
                    $days = (int) ceil(($now - $cycle->timedue) / DAYSECS);
                    $lines[] = $bold(get_string('banner_overdue', config::COMPONENT, (object) ['days' => $days,
                        'accessend' => $datestr(cycle_manager::access_end_date($cycle->anniversarydate))]));
                }
        }
        $row = fn(string $icon, string $html) => \html_writer::div(
            $OUTPUT->pix_icon($icon, '') . $html,
            'd-flex align-items-center'
        );

        $html = $row('i/calendar', \html_writer::span(implode(' · ', $lines)));
        if ($cycle->status === 'open') {
            $time = \html_writer::span(time_tracker::timeline($cycle), '', ['data-region' => 'local-cgt-time']);
            if (config::time_policy() === config::POLICY_UNRESOLVED) {
                $html .= $row('i/calendareventtime', $time);
            } else {
                $percent = time_tracker::percent($cycle);
                $percenttext = get_string('banner_percent', config::COMPONENT, $percent);
                $pie = \html_writer::span('', 'local-cgt-pie', [
                    'style' => "--local-cgt-percent: {$percent}",
                    'role' => 'progressbar',
                    'aria-valuenow' => $percent,
                    'aria-valuemin' => 0,
                    'aria-valuemax' => 100,
                    'aria-label' => $percenttext,
                    'data-region' => 'local-cgt-progress',
                ]);
                $percentlabel = \html_writer::tag('strong', s($percenttext), ['data-region' => 'local-cgt-percent']);
                $html .= \html_writer::div(
                    $pie . \html_writer::span($time . ' · ' . $percentlabel),
                    'd-flex align-items-center'
                );
            }

            $cm = $PAGE->cm;
            if ($cm && has_capability('local/caregivertraining:recordtime', \context_course::instance($courseid))) {
                $PAGE->requires->js_call_amd('local_caregivertraining/tracker', 'init', [[
                    'cmid' => (int) $cm->id,
                    'interval' => config::heartbeat_seconds(),
                    'policy' => config::time_policy(),
                ]]);
            }
        }

        $hook->add_html(\html_writer::div($html, 'local-cgt-banner bg-light rounded p-3 mb-3', [
            'role' => 'status',
            'aria-label' => get_string('banner_title', config::COMPONENT),
        ]));
        $PAGE->requires->js_call_amd('local_caregivertraining/banner', 'init');
    }

    /**
     * Render the Next activity button below the page's main content.
     *
     * @param \core\hook\output\before_footer_html_generation $hook
     */
    public static function before_footer_html_generation(\core\hook\output\before_footer_html_generation $hook): void {
        global $PAGE, $USER;

        $cycle = self::current_cycle();
        if (!$cycle || !in_array($cycle->status, ['open', 'completed'], true)) {
            return;
        }
        if (!in_array($PAGE->pagelayout, ['course', 'incourse'], true)
                || in_array($PAGE->pagetype, ['mod-quiz-attempt', 'mod-quiz-summary'], true)) {
            return;
        }

        $courseid = (int) $cycle->courseid;
        $aftercmid = $PAGE->cm ? (int) $PAGE->cm->id : 0;
        if (next_activity::find(get_course($courseid), (int) $USER->id, $aftercmid)) {
            $params = ['courseid' => $courseid] + ($aftercmid ? ['cmid' => $aftercmid] : []);
            $content = \html_writer::link(
                new \moodle_url('/local/caregivertraining/next.php', $params),
                get_string('nextactivity', config::COMPONENT),
                ['class' => 'btn btn-primary']
            );
        } else {
            $content = \html_writer::span(
                get_string($aftercmid ? 'nextactivity_locked' : 'nonextactivity', config::COMPONENT),
                'text-muted'
            );
        }
        $hook->add_html(\html_writer::div($content, 'local-cgt-next d-flex justify-content-end mt-4'));
    }

    /**
     * The learner's current cycle when the page belongs to the configured annual course.
     *
     * @return \stdClass|null
     */
    private static function current_cycle(): ?\stdClass {
        global $DB, $PAGE, $USER;

        if (during_initial_install() || !isloggedin() || isguestuser()) {
            return null;
        }
        $courseid = config::course_id();
        if (!$courseid || empty($PAGE->course->id) || (int) $PAGE->course->id !== $courseid) {
            return null;
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
        return reset($cycles) ?: null;
    }
}
