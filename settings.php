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
 * Admin settings pages and the training records page, in one category like other Moodle plugins.
 *
 * Setting names are unchanged from the original single page, so saved values carry over.
 *
 * @package    local_caregivertraining
 * @copyright  2026 CI Institute of Nursing
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

use local_caregivertraining\local\admin_setup;

$component = 'local_caregivertraining';
$category = 'local_caregivertraining_category';

$ADMIN->add('localplugins', new admin_category($category, get_string('pluginname', $component)));
// The page HR uses most comes first; HR can open it without site configuration rights.
$ADMIN->add($category, new admin_externalpage(
    'local_caregivertraining_view',
    get_string('adminview', $component),
    new moodle_url('/local/caregivertraining/index.php'),
    'local/caregivertraining:viewreports'
));

if ($hassiteconfig) {
    // Live summaries (setup warnings, certificate design, activity lists) need course and module info, which Moodle
    // refuses to build while an upgrade saves new setting defaults.
    $fulltree = $ADMIN->fulltree && empty($CFG->upgraderunning) && !during_initial_install();
    $str = fn(string $key, $a = null) => get_string($key, $component, $a);
    $env = fn(string $variable) => $fulltree ? admin_setup::env_note($variable) : '';
    $pages = [];
    foreach (admin_setup::PAGES as $name) {
        $pages[$name] = new admin_settingpage($name, $str('page_' . $name));
    }

    // General settings.
    $page = $pages['local_caregivertraining'];
    if ($fulltree && ($warnings = admin_setup::setup_warnings())) {
        $page->add(new admin_setting_heading("{$component}/setupwarnings", '', $warnings));
    }
    $page->add(new admin_setting_configselect_autocomplete(
        "{$component}/courseid",
        $str('courseid'),
        $str('courseid_desc'),
        0,
        fn() => admin_setup::course_choices()
    ));
    $page->add(new admin_setting_configselect(
        "{$component}/roleid",
        $str('roleid'),
        $str('roleid_desc'),
        0,
        fn() => admin_setup::role_choices()
    ));
    // Defaults to this site's Home Care Aide role, so upgrading turns it on where that role exists.
    $page->add(new admin_setting_configselect(
        "{$component}/systemroleid",
        $str('systemroleid'),
        $str('systemroleid_desc'),
        (int) $DB->get_field('role', 'id', ['shortname' => 'hca']),
        fn() => admin_setup::system_role_choices()
    ));
    $page->add(new admin_setting_configselect(
        "{$component}/compliancetimezone",
        $str('compliancetimezone'),
        $str('compliancetimezone_desc'),
        'America/Los_Angeles',
        fn() => core_date::get_list_of_timezones(get_config($component, 'compliancetimezone') ?: null)
    ));
    $page->add(new admin_setting_configtext(
        "{$component}/retentionyears",
        $str('retentionyears'),
        $str('retentionyears_desc'),
        3,
        PARAM_INT
    ));
    $page->add(new admin_setting_heading("{$component}/certificate", $str('settings_certificate'), ''));
    $page->add(new \local_caregivertraining\admin\setting_certificate(
        "{$component}/certificatecmid",
        $str('certificatecmid'),
        $str('certificatecmid_desc'),
        0,
        fn() => admin_setup::certificate_choices()
    ));
    $design = $fulltree ? \local_caregivertraining\local\certificate::design() : null;
    $page->add(new admin_setting_configtext(
        "{$component}/certificatefingerprint",
        $str('certificatefingerprint'),
        $str('certificatefingerprint_desc', [
            'name' => $design['name'] ?? '',
            'fingerprint' => $design['fingerprint'] ?? '',
            'problems' => $design && $design['problems'] ? implode(', ', $design['problems']) : '-',
        ]) . $env('CAREGIVERTRAINING_CERTIFICATE_FINGERPRINT'),
        '',
        PARAM_ALPHANUM
    ));

    // Training time.
    $page = $pages['local_caregivertraining_time'];
    $page->add(new admin_setting_heading("{$component}/time", '', $str('settings_time_desc')));
    $page->add(new admin_setting_configselect(
        "{$component}/timepolicy",
        $str('timepolicy'),
        $str('timepolicy_desc'),
        'unresolved',
        [
            'unresolved' => $str('timepolicy_unresolved'),
            'tracked' => $str('timepolicy_tracked'),
            'nominal' => $str('timepolicy_nominal'),
        ]
    ));
    $page->add(new admin_setting_configduration(
        "{$component}/requiredseconds",
        $str('requiredseconds'),
        $str('requiredseconds_desc'),
        18000,
        HOURSECS
    ));
    $page->add(new admin_setting_configcheckbox(
        "{$component}/countallactivities",
        $str('countallactivities'),
        $str('countallactivities_desc'),
        0
    ));
    $page->add(new admin_setting_configmultiselect(
        "{$component}/countablecmids",
        $str('countablecmids'),
        $str('countablecmids_desc'),
        [],
        fn() => admin_setup::activity_choices()
    ));
    $page->add(new admin_setting_configmultiselect_modules(
        "{$component}/excludedmodules",
        $str('excludedmodules'),
        $str('excludedmodules_desc')
    ));
    $page->add(new admin_setting_configtextarea(
        "{$component}/nominaldurations",
        $str('nominaldurations'),
        $str('nominaldurations_desc') . ($fulltree ? admin_setup::activity_id_list() : ''),
        '{}',
        PARAM_RAW
    ));
    $page->add(new admin_setting_configduration(
        "{$component}/heartbeatseconds",
        $str('heartbeatseconds'),
        $str('heartbeatseconds_desc'),
        30,
        1
    ));
    $page->add(new admin_setting_configduration(
        "{$component}/idleseconds",
        $str('idleseconds'),
        $str('idleseconds_desc'),
        120,
        MINSECS
    ));
    foreach (['requiredseconds', 'countallactivities', 'countablecmids', 'excludedmodules', 'nominaldurations'] as $name) {
        $page->hide_if("{$component}/{$name}", "{$component}/timepolicy", 'eq', 'unresolved');
    }
    $page->hide_if("{$component}/nominaldurations", "{$component}/timepolicy", 'neq', 'nominal');
    // Check-ins only matter when time actually spent is counted.
    foreach (['heartbeatseconds', 'idleseconds'] as $name) {
        $page->hide_if("{$component}/{$name}", "{$component}/timepolicy", 'neq', 'tracked');
    }
    $page->hide_if("{$component}/countablecmids", "{$component}/countallactivities", 'checked');
    $page->hide_if("{$component}/excludedmodules", "{$component}/countallactivities", 'notchecked');

    // Notifications: which emails are sent, and to whom.
    $page = $pages['local_caregivertraining_notifications'];
    $defaults = ['windowopen' => 1, 'completion' => 1, 'due' => 0, 'overdue' => 0];
    foreach (['windowopen', 'due', 'overdue', 'completion'] as $type) {
        $page->add(new admin_setting_configcheckbox(
            "{$component}/notify_{$type}",
            $str("notify_{$type}"),
            $str("notify_{$type}_desc"),
            $defaults[$type]
        ));
    }
    $page->add(new admin_setting_configtext(
        "{$component}/reminderoffsets",
        $str('reminderoffsets'),
        $str('reminderoffsets_desc'),
        '',
        PARAM_SEQUENCE
    ));
    $page->add(new admin_setting_configtext(
        "{$component}/hrccemails",
        $str('hrccemails'),
        $str('hrccemails_desc'),
        '',
        PARAM_RAW
    ));
    // Contacts in the footer every email shares.
    $page->add(new admin_setting_heading(
        "{$component}/emailfooter",
        $str('settings_emailfooter'),
        $str('settings_emailfooter_desc')
    ));
    $page->add(new admin_setting_configtext(
        "{$component}/quickstartguideurl",
        $str('quickstartguideurl'),
        $str('quickstartguideurl_desc'),
        '',
        PARAM_URL
    ));
    $page->add(new admin_setting_configtext(
        "{$component}/hrphone",
        $str('hrphone'),
        $str('hrphone_desc'),
        '(650) 278-8013',
        PARAM_TEXT
    ));
    $page->add(new admin_setting_configtext(
        "{$component}/hremail",
        $str('hremail'),
        '',
        'HRCompliance@careindeed.com',
        PARAM_EMAIL
    ));
    $page->add(new admin_setting_configtext(
        "{$component}/supportemail",
        $str('supportemail'),
        '',
        'systemadmin@ci-ion.com',
        PARAM_EMAIL
    ));

    // Email templates: the wording of each email.
    $page = $pages['local_caregivertraining_templates'];
    $page->add(new admin_setting_heading("{$component}/templates", '', $str('template_desc')));
    foreach (['activation', 'windowopen', 'reminder', 'due', 'overdue', 'completion'] as $type) {
        $page->add(new admin_setting_heading("{$component}/email_{$type}", $str("type_{$type}"), ''));
        // The default wording is the setting default, so Moodle shows it and an emptied field still falls back to it.
        $page->add(new admin_setting_configtext(
            "{$component}/template_subject_{$type}",
            $str('template_subject'),
            '',
            $str("default_subject_{$type}"),
            PARAM_TEXT
        ));
        $page->add(new admin_setting_configtextarea(
            "{$component}/template_body_{$type}",
            $str('template_body'),
            '',
            $str("default_body_{$type}"),
            PARAM_RAW
        ));
    }

    // Certificate storage.
    $page = $pages['local_caregivertraining_storage'];
    $page->add(new admin_setting_heading("{$component}/storage", '', $str('settings_storage_desc')));
    $s3 = ['s3bucket' => ['BUCKET', ''], 's3region' => ['REGION', 'us-west-1'], 's3endpoint' => ['ENDPOINT', ''],
        's3publicurl' => ['PUBLIC_URL', ''], 's3prefix' => ['PREFIX', 'certificates/'], 's3accesskey' => ['ACCESS_KEY', '']];
    foreach ($s3 as $name => [$suffix, $default]) {
        $page->add(new admin_setting_configtext(
            "{$component}/{$name}",
            $str($name),
            $str("{$name}_desc") . $env('CAREGIVERTRAINING_S3_' . $suffix),
            $default,
            PARAM_RAW_TRIMMED
        ));
    }
    $page->add(new admin_setting_configpasswordunmask(
        "{$component}/s3secretkey",
        $str('s3secretkey'),
        $str('s3secretkey_desc') . $env('CAREGIVERTRAINING_S3_SECRET_KEY'),
        ''
    ));

    // AlayaCare integration.
    $page = $pages['local_caregivertraining_integration'];
    $page->add(new admin_setting_heading("{$component}/adapter", '', $str('settings_adapter_desc')));
    $page->add(new admin_setting_configcheckbox(
        "{$component}/adapterenabled",
        $str('adapterenabled'),
        $str('adapterenabled_desc') . $env('CAREGIVERTRAINING_ADAPTER_ENABLED'),
        0
    ));
    $page->add(new admin_setting_configtext(
        "{$component}/adapterurl",
        $str('adapterurl'),
        $str('adapterurl_desc') . $env('CAREGIVERTRAINING_ADAPTER_URL'),
        '',
        PARAM_URL
    ));
    $page->add(new admin_setting_configpasswordunmask(
        "{$component}/adaptersecret",
        $str('adaptersecret'),
        $str('adaptersecret_desc') . $env('CAREGIVERTRAINING_ADAPTER_SECRET'),
        ''
    ));
    $page->add(new admin_setting_configtext(
        "{$component}/maxattempts",
        $str('maxattempts'),
        $str('maxattempts_desc'),
        20,
        PARAM_INT
    ));

    foreach ($pages as $page) {
        $ADMIN->add($category, $page);
    }
}
