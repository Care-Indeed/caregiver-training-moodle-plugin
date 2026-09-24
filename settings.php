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

/**
 * Admin settings and the administrator view link.
 *
 * @package    local_caregivertraining
 * @copyright  2026 CI Institute of Nursing
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$component = 'local_caregivertraining';

$ADMIN->add('localplugins', new admin_category('local_caregivertraining_category', get_string('pluginname', $component)));
$ADMIN->add('local_caregivertraining_category', new admin_externalpage(
    'local_caregivertraining_view',
    get_string('adminview', $component),
    new moodle_url('/local/caregivertraining/index.php'),
    'local/caregivertraining:viewreports'
));

if ($hassiteconfig) {
    $settings = new admin_settingpage('local_caregivertraining', get_string('settings'));

    $settings->add(new admin_setting_heading(
        "{$component}/course",
        get_string('settings_course', $component),
        get_string('settings_course_desc', $component)
    ));
    $settings->add(new admin_setting_configtext(
        "{$component}/courseid",
        get_string('courseid', $component),
        get_string('courseid_desc', $component),
        0,
        PARAM_INT
    ));
    $settings->add(new admin_setting_configtext(
        "{$component}/roleid",
        get_string('roleid', $component),
        get_string('roleid_desc', $component),
        0,
        PARAM_INT
    ));
    $settings->add(new admin_setting_configtext(
        "{$component}/certificatecmid",
        get_string('certificatecmid', $component),
        get_string('certificatecmid_desc', $component),
        0,
        PARAM_INT
    ));
    $settings->add(new admin_setting_configtext(
        "{$component}/compliancetimezone",
        get_string('compliancetimezone', $component),
        get_string('compliancetimezone_desc', $component),
        'America/Los_Angeles',
        PARAM_TIMEZONE
    ));

    $settings->add(new admin_setting_heading(
        "{$component}/time",
        get_string('settings_time', $component),
        get_string('settings_time_desc', $component)
    ));
    $settings->add(new admin_setting_configselect(
        "{$component}/timepolicy",
        get_string('timepolicy', $component),
        get_string('timepolicy_desc', $component),
        'unresolved',
        [
            'unresolved' => get_string('timepolicy_unresolved', $component),
            'tracked' => get_string('timepolicy_tracked', $component),
            'nominal' => get_string('timepolicy_nominal', $component),
        ]
    ));
    $settings->add(new admin_setting_configtext(
        "{$component}/requiredseconds",
        get_string('requiredseconds', $component),
        get_string('requiredseconds_desc', $component),
        18000,
        PARAM_INT
    ));
    $settings->add(new admin_setting_configtext(
        "{$component}/countablecmids",
        get_string('countablecmids', $component),
        get_string('countablecmids_desc', $component),
        '',
        PARAM_SEQUENCE
    ));
    $settings->add(new admin_setting_configtextarea(
        "{$component}/nominaldurations",
        get_string('nominaldurations', $component),
        get_string('nominaldurations_desc', $component),
        '{}',
        PARAM_RAW
    ));
    $settings->add(new admin_setting_configtext(
        "{$component}/heartbeatseconds",
        get_string('heartbeatseconds', $component),
        get_string('heartbeatseconds_desc', $component),
        30,
        PARAM_INT
    ));
    $settings->add(new admin_setting_configtext(
        "{$component}/idleseconds",
        get_string('idleseconds', $component),
        get_string('idleseconds_desc', $component),
        120,
        PARAM_INT
    ));

    $settings->add(new admin_setting_heading(
        "{$component}/notifications",
        get_string('settings_notifications', $component),
        get_string('settings_notifications_desc', $component)
    ));
    $defaults = ['windowopen' => 1, 'completion' => 1, 'due' => 0, 'overdue' => 0];
    foreach ($defaults as $type => $default) {
        $settings->add(new admin_setting_configcheckbox(
            "{$component}/notify_{$type}",
            get_string("notify_{$type}", $component),
            '',
            $default
        ));
    }
    $settings->add(new admin_setting_configtext(
        "{$component}/reminderoffsets",
        get_string('reminderoffsets', $component),
        get_string('reminderoffsets_desc', $component),
        '',
        PARAM_SEQUENCE
    ));
    $settings->add(new admin_setting_configtext(
        "{$component}/hrccemails",
        get_string('hrccemails', $component),
        get_string('hrccemails_desc', $component),
        '',
        PARAM_RAW
    ));
    foreach (['windowopen', 'reminder', 'due', 'overdue', 'completion'] as $type) {
        $label = get_string("type_{$type}", $component);
        $settings->add(new admin_setting_configtext(
            "{$component}/template_subject_{$type}",
            get_string('template_subject', $component, $label),
            get_string('template_desc', $component),
            '',
            PARAM_TEXT
        ));
        $settings->add(new admin_setting_configtextarea(
            "{$component}/template_body_{$type}",
            get_string('template_body', $component, $label),
            get_string('template_desc', $component),
            '',
            PARAM_RAW
        ));
    }

    $settings->add(new admin_setting_heading(
        "{$component}/adapter",
        get_string('settings_adapter', $component),
        get_string('settings_adapter_desc', $component)
    ));
    $settings->add(new admin_setting_configcheckbox(
        "{$component}/adapterenabled",
        get_string('adapterenabled', $component),
        '',
        0
    ));
    $settings->add(new admin_setting_configtext(
        "{$component}/adapterurl",
        get_string('adapterurl', $component),
        '',
        '',
        PARAM_URL
    ));
    $settings->add(new admin_setting_configpasswordunmask(
        "{$component}/adaptersecret",
        get_string('adaptersecret', $component),
        '',
        ''
    ));
    $settings->add(new admin_setting_configtext(
        "{$component}/maxattempts",
        get_string('maxattempts', $component),
        '',
        20,
        PARAM_INT
    ));
    $settings->add(new admin_setting_configtext(
        "{$component}/retentionyears",
        get_string('retentionyears', $component),
        get_string('retentionyears_desc', $component),
        3,
        PARAM_INT
    ));

    $ADMIN->add('local_caregivertraining_category', $settings);
}
