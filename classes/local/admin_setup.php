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
 * Choice lists, setup warnings and environment notes for the settings pages.
 *
 * Choices are loaded lazily by the select settings, so none of this runs unless a settings page is shown or saved.
 *
 * @package    local_caregivertraining
 * @copyright  2026 CI Institute of Nursing
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class admin_setup {
    /** @var string[] Settings page names, in the order they appear. */
    const PAGES = ['local_caregivertraining', 'local_caregivertraining_time', 'local_caregivertraining_notifications',
        'local_caregivertraining_templates', 'local_caregivertraining_storage', 'local_caregivertraining_integration'];

    /**
     * Courses that can be the annual course.
     *
     * @return array<int,string>
     */
    public static function course_choices(): array {
        global $DB;
        $choices = [0 => get_string('notset', config::COMPONENT)];
        $courses = $DB->get_records_select('course', 'id <> :site', ['site' => SITEID], 'fullname', 'id, fullname, shortname');
        foreach ($courses as $course) {
            $choices[$course->id] = format_string($course->fullname) . ' (' . format_string($course->shortname) . ')';
        }
        return $choices;
    }

    /**
     * Roles that can be given in a course.
     *
     * @return array<int,string>
     */
    public static function role_choices(): array {
        $choices = [0 => get_string('roleid_default', config::COMPONENT)];
        $courseroles = get_roles_for_contextlevels(CONTEXT_COURSE);
        $names = role_fix_names(get_all_roles(), \context_system::instance(), ROLENAME_ORIGINAL, true);
        foreach ($names as $roleid => $name) {
            if (in_array($roleid, $courseroles)) {
                $choices[$roleid] = $name;
            }
        }
        return $choices;
    }

    /**
     * Custom Certificate activities in the annual course, keeping a saved value that is no longer in it.
     *
     * @return array<int,string>
     */
    public static function certificate_choices(): array {
        $choices = [0 => get_string('notset', config::COMPONENT)] + self::course_certificates();
        $current = config::certificate_cmid();
        if ($current && !isset($choices[$current])) {
            $choices[$current] = get_string('missingactivity', config::COMPONENT, $current);
        }
        return $choices;
    }

    /**
     * Custom Certificate activities in the annual course, or in another course.
     *
     * @param int|null $courseid course to look in; the saved annual course when null
     * @return array<int,string> cmid => name
     */
    public static function course_certificates(?int $courseid = null): array {
        $certificates = [];
        foreach (self::course_cms($courseid) as $cm) {
            if ($cm->modname === 'customcert') {
                $certificates[$cm->id] = $cm->get_formatted_name();
            }
        }
        return $certificates;
    }

    /**
     * Every activity in the annual course, labelled with its section and type, keeping saved ids that are gone.
     *
     * @return array<int,string>
     */
    public static function activity_choices(): array {
        $choices = [];
        foreach (self::course_cms() as $cm) {
            $choices[$cm->id] = self::activity_label($cm);
        }
        $saved = array_filter(array_map('intval', explode(',', (string) config::get('countablecmids', ''))));
        foreach ($saved as $cmid) {
            if (!isset($choices[$cmid])) {
                $choices[$cmid] = get_string('missingactivity', config::COMPONENT, $cmid);
            }
        }
        return $choices;
    }

    /**
     * The annual course's activities with their ids, for writing nominal durations.
     *
     * @return string HTML
     */
    public static function activity_id_list(): string {
        $items = [];
        foreach (self::course_cms() as $cm) {
            $items[] = \html_writer::tag('li', \html_writer::tag('code', $cm->id) . ' ' . s(self::activity_label($cm)));
        }
        if (!$items) {
            return \html_writer::tag('p', get_string('nocourseactivities', config::COMPONENT));
        }
        return \html_writer::tag('ul', implode('', $items), ['class' => 'small mb-0']);
    }

    /**
     * What still blocks completions, as a warning linking to the page that fixes each item. Empty when nothing does.
     *
     * @return string HTML
     */
    public static function setup_warnings(): string {
        global $OUTPUT, $DB;
        $courseid = config::course_id();
        $course = $courseid ? $DB->get_record('course', ['id' => $courseid]) : false;
        $cmid = config::certificate_cmid();
        $cm = $cmid ? get_coursemodule_from_id('customcert', $cmid) : false;
        $certok = $cm && $course && (int) $cm->course === (int) $course->id;
        $policy = config::time_policy();

        // Each problem: [string key, string argument, settings page].
        $problems = [];
        if (!$course) {
            $problems[] = ['setup_nocourse', null, 'local_caregivertraining'];
        }
        if (!$certok) {
            $problems[] = ['setup_nocertificate', null, 'local_caregivertraining'];
        } else if ($designproblems = certificate::design()['problems']) {
            $problems[] = ['setup_design', implode(', ', $designproblems), 'local_caregivertraining'];
        }
        if ($policy === config::POLICY_UNRESOLVED) {
            $problems[] = ['setup_nopolicy', null, 'local_caregivertraining_time'];
        } else if (config::required_seconds() > 0 && !config::countable_cmids()) {
            $problems[] = ['setup_noactivities', null, 'local_caregivertraining_time'];
        }
        if (!$problems) {
            return '';
        }

        $items = '';
        foreach ($problems as [$key, $a, $section]) {
            $text = s(get_string($key, config::COMPONENT, $a));
            if ($section !== 'local_caregivertraining') {
                $text = \html_writer::link(new \moodle_url('/admin/settings.php', ['section' => $section]), $text);
            }
            $items .= \html_writer::tag('li', $text);
        }
        return $OUTPUT->notification(
            get_string('setup_warnings', config::COMPONENT) . \html_writer::tag('ul', $items, ['class' => 'mb-0']),
            \core\output\notification::NOTIFY_WARNING,
            false
        );
    }

    /**
     * Note shown under a setting that an environment variable currently overrides.
     *
     * @param string $variable environment variable name
     * @return string HTML, empty when the variable is not set
     */
    public static function env_note(string $variable): string {
        $value = getenv($variable);
        if ($value === false || $value === '') {
            return '';
        }
        return \html_writer::div(
            get_string('envoverride', config::COMPONENT, $variable),
            'alert alert-warning py-1 px-2 mt-2 mb-0'
        );
    }

    /**
     * Activities of the annual course (or the given course), or none when it is not set or no longer exists.
     *
     * @param int|null $courseid the saved annual course when null
     * @return \cm_info[]
     */
    private static function course_cms(?int $courseid = null): array {
        global $CFG;
        // Upgrades load every setting's choices to save new defaults, and course module info cannot be built then.
        if (!empty($CFG->upgraderunning) || during_initial_install()) {
            return [];
        }
        $courseid = $courseid ?? config::course_id();
        if (!$courseid || $courseid == SITEID) {
            return [];
        }
        try {
            $modinfo = get_fast_modinfo($courseid);
        } catch (\dml_missing_record_exception $e) {
            return [];
        }
        return array_filter($modinfo->get_cms(), fn($cm) => !$cm->deletioninprogress);
    }

    /**
     * "Section name: Activity name (Activity type)".
     *
     * @param \cm_info $cm
     * @return string
     */
    private static function activity_label(\cm_info $cm): string {
        $section = get_section_name($cm->get_course(), $cm->sectionnum);
        return $section . ': ' . $cm->get_formatted_name() . ' (' . get_string('modulename', $cm->modname) . ')';
    }
}
