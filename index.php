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
 * Administrator view: exceptions, blocked resets, current cycles and prior-cycle snapshots.
 *
 * @package    local_caregivertraining
 * @copyright  2026 CI Institute of Nursing
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/adminlib.php');

use local_caregivertraining\local\admin_actions;
use local_caregivertraining\local\cycle_manager;

$tab = optional_param('tab', 'exceptions', PARAM_ALPHA);
$showarchived = optional_param('showarchived', 0, PARAM_BOOL);
$action = optional_param('action', '', PARAM_ALPHA);
$id = optional_param('id', 0, PARAM_INT);

admin_externalpage_setup('local_caregivertraining_view');
$context = context_system::instance();
require_capability('local/caregivertraining:viewreports', $context);
$component = 'local_caregivertraining';
$baseurl = new moodle_url('/local/caregivertraining/index.php', ['tab' => $tab, 'showarchived' => $showarchived]);
$PAGE->set_url($baseurl);

if ($action !== '' && $id) {
    require_sesskey();
    if ($action === 'retry') {
        $result = admin_actions::retry_cycle($id);
        $type = $result->status === 'open' ? \core\output\notification::NOTIFY_SUCCESS : \core\output\notification::NOTIFY_ERROR;
        redirect($baseurl, $result->status . ($result->blockedreason ? ': ' . s($result->blockedreason) : ''), null, $type);
    } else if ($action === 'archive') {
        admin_actions::archive_cycle($id);
    } else if ($action === 'resolve') {
        admin_actions::resolve_exception($id);
    }
    redirect($baseurl);
}

$canmanage = has_capability('local/caregivertraining:managecycles', $context);
$canexport = has_capability('local/caregivertraining:export', $context);
$tz = \local_caregivertraining\local\config::timezone();
$fmt = fn($t) => $t ? (new DateTimeImmutable('@' . $t))->setTimezone($tz)->format('Y-m-d H:i') : '';
$actionbutton = function (string $action, int $id, string $label) use ($baseurl, $OUTPUT) {
    return $OUTPUT->single_button(
        new moodle_url($baseurl, ['action' => $action, 'id' => $id, 'sesskey' => sesskey()]),
        $label,
        'post'
    );
};

$tabs = [];
foreach (['exceptions', 'blocked', 'cycles', 'snapshots'] as $name) {
    $tabs[] = new tabobject($name, new moodle_url($baseurl, ['tab' => $name]), get_string('tab_' . $name, $component));
}

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('adminview', $component));
echo $OUTPUT->tabtree($tabs, $tab);

if ($canexport) {
    echo html_writer::start_tag('form', ['method' => 'post', 'action' => new moodle_url('/local/caregivertraining/export.php'),
        'class' => 'form-inline mb-3']);
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
    echo html_writer::empty_tag('input', ['type' => 'text', 'name' => 'filter', 'class' => 'form-control mr-2',
        'size' => 40, 'placeholder' => get_string('exportfilter', $component),
        'aria-label' => get_string('exportfilter', $component)]);
    echo html_writer::empty_tag('input', ['type' => 'submit', 'class' => 'btn btn-secondary',
        'value' => get_string('export', $component)]);
    echo html_writer::end_tag('form');
}

$archivedsql = $showarchived ? '' : ' AND c.archived = 0';
$table = new html_table();
$table->attributes['class'] = 'generaltable';
$userfields = \core_user\fields::for_name()->get_sql('u', true);
$cyclesql = "SELECT c.*, b.alayacareid, b.payrollid {$userfields->selects}
               FROM {local_cgt_cycle} c
               JOIN {local_cgt_binding} b ON b.id = c.bindingid
               JOIN {user} u ON u.id = c.userid";

switch ($tab) {
    case 'blocked':
        $table->head = [get_string('col_employee', $component), get_string('col_alayacareid', $component),
            get_string('col_cycleid', $component), get_string('col_due', $component), get_string('col_reason', $component),
            get_string('col_actions', $component)];
        $records = $DB->get_records_sql(
            "{$cyclesql} WHERE c.status = 'blocked' ORDER BY c.timemodified DESC",
            $userfields->params,
            0,
            500
        );
        foreach ($records as $r) {
            $table->data[] = [fullname($r), s($r->alayacareid), s($r->cycleid), s($r->duedate), s($r->blockedreason),
                $canmanage ? $actionbutton('retry', (int) $r->id, get_string('retryreset', $component)) : ''];
        }
        break;

    case 'cycles':
        $table->head = [get_string('col_employee', $component), get_string('col_alayacareid', $component),
            get_string('col_payrollid', $component), get_string('col_cycleid', $component), get_string('col_status', $component),
            get_string('col_compliance', $component), get_string('col_open', $component), get_string('col_due', $component),
            get_string('col_completed', $component), get_string('col_time', $component),
            get_string('col_certificate', $component), get_string('col_actions', $component)];
        $records = $DB->get_records_sql("{$cyclesql} WHERE c.status <> 'superseded' {$archivedsql}
            ORDER BY c.timedue ASC", $userfields->params, 0, 500);
        foreach ($records as $r) {
            $compliance = cycle_manager::compliance($r);
            $actions = ($canmanage && $r->status === 'completed' && !$r->archived)
                ? $actionbutton('archive', (int) $r->id, get_string('archivecycle', $component)) : '';
            $table->data[] = [fullname($r), s($r->alayacareid), s($r->payrollid), s($r->cycleid), s($r->status),
                get_string('compliance_' . $compliance, $component), s($r->opendate), s($r->duedate), $fmt($r->timecompleted),
                format_time((int) $r->approvedseconds), s($r->certificatecode), $actions];
        }
        break;

    case 'snapshots':
        $table->head = [get_string('col_employee', $component), get_string('col_cycleid', $component),
            get_string('col_type', $component), get_string('col_created', $component), get_string('col_verified', $component),
            get_string('col_hash', $component), get_string('col_certificate', $component)];
        $records = $DB->get_records_sql("SELECT s.id AS snapshotid, s.type, s.timecreated AS snapcreated, s.verified,
                s.payloadhash, s.payload, c.cycleid, c.archived {$userfields->selects}
              FROM {local_cgt_snapshot} s
              JOIN {local_cgt_cycle} c ON c.id = s.cycleid
              JOIN {user} u ON u.id = s.userid
             WHERE 1 = 1 {$archivedsql}
          ORDER BY s.timecreated DESC", $userfields->params, 0, 500);
        foreach ($records as $r) {
            $payload = json_decode($r->payload, true);
            $links = [];
            foreach ($payload['certificates'] ?? [] as $cert) {
                $url = moodle_url::make_pluginfile_url(
                    $context->id,
                    $component,
                    'snapshotcert',
                    $r->snapshotid,
                    '/',
                    $cert['filename'],
                    true
                );
                $links[] = html_writer::link($url, s($cert['code']));
            }
            $table->data[] = [fullname($r), s($r->cycleid), s($r->type), $fmt($r->snapcreated),
                $r->verified ? get_string('yes') : get_string('no'), html_writer::tag('code', s(substr($r->payloadhash, 0, 16))),
                implode(', ', $links)];
        }
        break;

    default:
        $table->head = [get_string('col_type', $component), get_string('col_alayacareid', $component),
            get_string('col_details', $component), get_string('col_created', $component), get_string('col_status', $component),
            get_string('col_actions', $component)];
        $records = $DB->get_records(
            'local_cgt_exception',
            $showarchived ? null : ['status' => 'open'],
            'timemodified DESC',
            '*',
            0,
            500
        );
        foreach ($records as $r) {
            $table->data[] = [s($r->type) . ($r->occurrences > 1 ? " ({$r->occurrences})" : ''), s($r->alayacareid),
                html_writer::tag('code', s($r->details)), $fmt($r->timemodified), s($r->status),
                ($canmanage && $r->status === 'open') ? $actionbutton(
                    'resolve',
                    (int) $r->id,
                    get_string('resolveexception', $component)
                ) : ''];
        }
}

if (empty($table->data)) {
    echo $OUTPUT->notification(get_string('nothingtodisplay', $component), 'info');
} else {
    echo html_writer::table($table);
}

$toggle = new moodle_url($baseurl, ['showarchived' => $showarchived ? 0 : 1]);
echo html_writer::link($toggle, get_string('showarchived', $component) . ($showarchived ? ' ✓' : ''));
echo $OUTPUT->footer();
