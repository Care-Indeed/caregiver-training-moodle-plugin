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
 * Training records: employees and their cycle history, exceptions, and blocked resets.
 *
 * @package    local_caregivertraining
 * @copyright  2026 CI Institute of Nursing
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/adminlib.php');
require_once($CFG->libdir . '/tablelib.php');

use local_caregivertraining\local\admin_actions;
use local_caregivertraining\local\completion_manager;
use local_caregivertraining\local\config;
use local_caregivertraining\local\cycle_manager;
use local_caregivertraining\local\export;
use local_caregivertraining\local\profile_fields;
use local_caregivertraining\local\records;

$view = optional_param('view', optional_param('tab', 'employees', PARAM_ALPHA), PARAM_ALPHA);
$employee = optional_param('employee', 0, PARAM_INT);
$search = optional_param('search', '', PARAM_RAW_TRIMMED);
$status = optional_param('status', '', PARAM_ALPHA);
$showresolved = optional_param('showresolved', optional_param('showarchived', 0, PARAM_BOOL), PARAM_BOOL);
$action = optional_param('action', '', PARAM_ALPHA);
$id = optional_param('id', 0, PARAM_INT);
$confirm = optional_param('confirm', 0, PARAM_BOOL);
// Old bookmarks: the cycles and snapshots tabs are now the employee history.
$view = in_array($view, ['exceptions', 'blocked'], true) ? $view : 'employees';
$status = in_array($status, records::STATUSES, true) ? $status : '';

admin_externalpage_setup('local_caregivertraining_view');
$context = context_system::instance();
require_capability('local/caregivertraining:viewreports', $context);
$component = 'local_caregivertraining';
$pageurl = new moodle_url('/local/caregivertraining/index.php');
$listurl = new moodle_url($pageurl, array_filter(['search' => $search, 'status' => $status]));
$baseurl = match (true) {
    $view === 'employees' && (bool) $employee => new moodle_url($listurl, ['employee' => $employee]),
    $view === 'employees' => $listurl,
    default => new moodle_url($pageurl, array_filter(['view' => $view, 'showresolved' => $showresolved])),
};
$PAGE->set_url($baseurl);

$canmanage = has_capability('local/caregivertraining:managecycles', $context);
$canexport = has_capability('local/caregivertraining:export', $context);
$tz = config::timezone();
$datetime = fn($time) => $time ? userdate($time, get_string('strftimedatetimeshort', 'langconfig'), $tz) : '-';
$date = fn(string $day) => userdate(
    cycle_manager::start_of_day($day) + 12 * HOURSECS,
    get_string('strftimedate', 'langconfig'),
    $tz
);
$actionmenu = function (array $items) use ($OUTPUT): string {
    if (!$items) {
        return '';
    }
    $menu = new action_menu();
    $menu->set_kebab_trigger(get_string('actions'));
    foreach ($items as [$url, $label]) {
        $menu->add(new action_menu_link_secondary($url, null, $label));
    }
    return $OUTPUT->render($menu);
};
$actionurl = fn(string $action, int $id) => new moodle_url($baseurl, ['action' => $action, 'id' => $id]);

// Row actions ask for confirmation first, like other Moodle admin actions.
if ($action !== '' && $id) {
    $cycle = in_array($action, ['archive', 'retry'], true) ? $DB->get_record('local_cgt_cycle', ['id' => $id], '*', MUST_EXIST)
        : null;
    if (!in_array($action, ['archive', 'retry', 'resolve'], true)) {
        redirect($baseurl);
    }
    if (!$confirm) {
        echo $OUTPUT->header();
        echo $OUTPUT->confirm(
            get_string('confirm_' . $action, $component, $cycle ? s($cycle->cycleid) : null),
            new moodle_url($actionurl($action, $id), ['confirm' => 1, 'sesskey' => sesskey()]),
            $baseurl
        );
        echo $OUTPUT->footer();
        exit;
    }
    require_sesskey();
    if ($action === 'retry') {
        $result = admin_actions::retry_cycle($id);
        $ok = $result->status === 'open';
        redirect(
            $baseurl,
            $ok ? get_string('done_retry', $component) : get_string('done_retryfailed', $component, s($result->blockedreason)),
            null,
            $ok ? \core\output\notification::NOTIFY_SUCCESS : \core\output\notification::NOTIFY_ERROR
        );
    }
    $action === 'archive' ? admin_actions::archive_cycle($id) : admin_actions::resolve_exception($id);
    redirect($baseurl, get_string('done_' . $action, $component), null, \core\output\notification::NOTIFY_SUCCESS);
}

// One employee's history.
if ($view === 'employees' && $employee) {
    $userfields = profile_fields::user_fields()->get_sql('u', true);
    $person = $DB->get_record_sql("SELECT b.*, u.id AS moodleuserid {$userfields->selects}
          FROM {local_cgt_binding} b
          JOIN {user} u ON u.id = b.userid
          {$userfields->joins}
         WHERE b.id = :bindingid", ['bindingid' => $employee] + $userfields->params, MUST_EXIST);
    $name = fullname($person);
    $PAGE->navbar->add($name);
    $PAGE->set_title($name);
    $employment = profile_fields::values_from_record($person);

    echo $OUTPUT->header();
    echo html_writer::div(
        html_writer::link($listurl, get_string('back'), ['class' => 'btn btn-secondary']),
        'tertiary-navigation d-flex mb-3'
    );
    echo $OUTPUT->heading($name);
    $details = [
        get_string('col_alayacareid', $component) => s($person->alayacareid),
        get_string('field_payrollnumber', $component) => s($employment['payrollnumber']),
        get_string('field_hcanumber', $component) => s($employment['hcanumber']),
        get_string('field_registrationdate', $component) => s($employment['registrationdate']),
        get_string('moodleaccount', $component) => html_writer::link(
            new moodle_url('/user/profile.php', ['id' => $person->moodleuserid]),
            get_string('viewprofile')
        ),
    ];
    echo html_writer::start_tag('dl', ['class' => 'row mb-4']);
    foreach ($details as $label => $value) {
        echo html_writer::tag('dt', s($label), ['class' => 'col-sm-3']);
        echo html_writer::tag('dd', $value === '' ? '-' : $value, ['class' => 'col-sm-9']);
    }
    echo html_writer::end_tag('dl');

    echo $OUTPUT->heading(get_string('traininghistory', $component), 3);
    $cycles = $DB->get_records_select(
        'local_cgt_cycle',
        "bindingid = :bindingid AND status <> 'superseded'",
        ['bindingid' => $employee],
        'timedue DESC, id DESC'
    );
    if (!$cycles) {
        echo $OUTPUT->notification(get_string('nocycles', $component), \core\output\notification::NOTIFY_INFO, false);
        echo $OUTPUT->footer();
        exit;
    }
    $snapshots = $DB->get_records_list('local_cgt_snapshot', 'cycleid', array_keys($cycles), 'timecreated');

    $table = new flexible_table('local_caregivertraining_history');
    $table->define_baseurl($baseurl);
    $columns = ['trainingyear', 'cycleid', 'status', 'due', 'accessend', 'completed', 'time', 'certificate', 'evidence',
        'actions'];
    $table->define_columns($columns);
    $table->define_headers(array_map(fn($column) => $column === 'actions'
        ? html_writer::span(get_string('actions'), 'visually-hidden')
        : get_string('col_' . $column, $component), $columns));
    $table->set_attribute('class', 'generaltable');
    $table->setup();
    foreach ($cycles as $c) {
        $evidence = [];
        foreach ($snapshots as $snapshot) {
            if ((int) $snapshot->cycleid !== (int) $c->id) {
                continue;
            }
            $links = [];
            foreach (json_decode($snapshot->payload, true)['certificates'] ?? [] as $cert) {
                $url = moodle_url::make_pluginfile_url(
                    $context->id,
                    $component,
                    'snapshotcert',
                    $snapshot->id,
                    '/',
                    $cert['filename'],
                    true
                );
                $links[] = html_writer::link($url, $OUTPUT->pix_icon('f/pdf', '') . get_string('certificatepdf', $component));
            }
            $evidence[] = html_writer::div(
                s(export::snapshot_label($snapshot)) . ($links ? '<br>' . implode(' ', $links) : ''),
                'mb-1'
            );
        }
        $statushtml = records::status_badge($c);
        if ($c->archived) {
            $statushtml .= ' ' . html_writer::span(get_string('archived', $component), 'badge bg-light text-dark border');
        }
        if ($c->status === 'blocked' && $c->blockedreason) {
            $statushtml .= html_writer::div(s($c->blockedreason), 'small text-muted');
        }
        $actions = [];
        if ($canmanage && $c->status === 'completed' && !$c->archived) {
            $actions[] = [$actionurl('archive', (int) $c->id), get_string('archivecycle', $component)];
        }
        if ($canmanage && $c->status === 'blocked') {
            $actions[] = [$actionurl('retry', (int) $c->id), get_string('retryreset', $component)];
        }
        $table->add_data([
            s(substr($c->anniversarydate, 0, 4)),
            s($c->cycleid),
            $statushtml,
            $date($c->anniversarydate),
            $date(cycle_manager::access_end_date($c->anniversarydate)),
            $datetime($c->timecompleted),
            $c->status === 'completed' ? format_time((int) $c->approvedseconds) : '-',
            $c->certificatecode ? html_writer::link(completion_manager::verify_url($c), s($c->certificatecode)) : '-',
            $evidence ? implode('', $evidence) : '-',
            $actionmenu($actions),
        ]);
    }
    $table->finish_output();
    if ($canexport) {
        echo $OUTPUT->download_dataformat_selector(
            get_string('downloadhistory', $component),
            new moodle_url('/local/caregivertraining/export.php'),
            'dataformat',
            ['employee' => $employee, 'sesskey' => sesskey()]
        );
    }
    echo $OUTPUT->footer();
    exit;
}

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('adminview', $component));

// Tertiary navigation, as on other Moodle report pages.
$openexceptions = $DB->count_records('local_cgt_exception', ['status' => 'open']);
$blocked = $DB->count_records('local_cgt_cycle', ['status' => 'blocked']);
$views = [
    'employees' => get_string('view_employees', $component),
    'exceptions' => get_string('view_exceptions', $component, $openexceptions),
    'blocked' => get_string('view_blocked', $component, $blocked),
];
$options = [];
foreach ($views as $name => $label) {
    $options[(new moodle_url($pageurl, $name === 'employees' ? [] : ['view' => $name]))->out(false)] = $label;
}
$selected = (new moodle_url($pageurl, $view === 'employees' ? [] : ['view' => $view]))->out(false);
$select = new url_select($options, $selected, null);
$select->set_label(get_string('view'), ['class' => 'visually-hidden']);
echo html_writer::div($OUTPUT->render($select), 'tertiary-navigation d-flex mb-3');

$userfields = profile_fields::user_fields()->get_sql('u', true);

if ($view === 'employees') {
    // Filters.
    echo html_writer::start_tag('form', ['method' => 'get', 'action' => $pageurl, 'class' => 'd-flex flex-wrap gap-2 mb-3',
        'role' => 'search']);
    echo html_writer::label(
        get_string('searchemployees', $component),
        'local-cgt-search',
        false,
        ['class' => 'visually-hidden']
    );
    echo html_writer::empty_tag('input', ['type' => 'search', 'name' => 'search', 'id' => 'local-cgt-search',
        'value' => $search, 'class' => 'form-control w-auto', 'size' => 45,
        'placeholder' => get_string('searchemployees', $component)]);
    $statusoptions = ['' => get_string('allstatuses', $component)];
    foreach (records::STATUSES as $name) {
        $statusoptions[$name] = get_string('compliance_' . $name, $component);
    }
    echo html_writer::label(get_string('col_status', $component), 'local-cgt-status', false, ['class' => 'visually-hidden']);
    echo html_writer::select(
        $statusoptions,
        'status',
        $status,
        false,
        ['id' => 'local-cgt-status', 'class' => 'form-select w-auto']
    );
    echo html_writer::empty_tag('input', ['type' => 'submit', 'class' => 'btn btn-primary',
        'value' => get_string('apply', $component)]);
    if ($search !== '' || $status !== '') {
        echo html_writer::link($pageurl, get_string('clearfilters', $component), ['class' => 'btn btn-link']);
    }
    echo html_writer::end_tag('form');

    [$selects, $from, $where, $params] = records::employees_sql($search, $status);
    $total = $DB->count_records_sql("SELECT COUNT(1) FROM {$from} WHERE {$where}", $params);
    if (!$total) {
        $message = $search !== '' || $status !== '' ? 'noemployeesmatch' : 'noemployees';
        echo $OUTPUT->notification(get_string($message, $component), \core\output\notification::NOTIFY_INFO, false);
        echo $OUTPUT->footer();
        exit;
    }

    $table = new flexible_table('local_caregivertraining_employees');
    $table->define_baseurl($listurl);
    $columns = ['fullname', 'alayacareid', 'payrollnumber', 'hcanumber', 'cycleid', 'status', 'timedue', 'timecompleted',
        'cycles'];
    $table->define_columns($columns);
    $table->define_headers([get_string('col_employee', $component), get_string('col_alayacareid', $component),
        get_string('field_payrollnumber', $component), get_string('field_hcanumber', $component),
        get_string('col_latestcycle', $component), get_string('col_status', $component), get_string('col_due', $component),
        get_string('col_completed', $component), get_string('col_cycles', $component)]);
    $table->sortable(true, 'lastname', SORT_ASC);
    $table->no_sorting('payrollnumber');
    $table->no_sorting('hcanumber');
    $table->no_sorting('status');
    $table->no_sorting('cycles');
    $table->pageable(true);
    $table->pagesize(50, $total);
    $table->set_attribute('class', 'generaltable');
    $table->setup();
    $sort = $table->get_sql_sort() ?: 'lastname ASC';
    $records = $DB->get_records_sql("SELECT b.id AS bindingid, b.alayacareid, c.cycleid, c.status, c.timeopen, c.timedue,
            c.anniversarydate, c.timecompleted,
            (SELECT COUNT(1) FROM {local_cgt_cycle} c3 WHERE c3.bindingid = b.id AND c3.status <> 'superseded') AS cycles
            {$selects}
        FROM {$from}
        WHERE {$where}
        ORDER BY {$sort}, b.id", $params, $table->get_page_start(), $table->get_page_size());
    foreach ($records as $r) {
        $employment = profile_fields::values_from_record($r);
        $table->add_data([
            html_writer::link(new moodle_url($listurl, ['employee' => $r->bindingid]), s(fullname($r))),
            s($r->alayacareid),
            s($employment['payrollnumber']) ?: '-',
            s($employment['hcanumber']) ?: '-',
            s($r->cycleid),
            records::status_badge($r),
            $date($r->anniversarydate),
            $datetime($r->timecompleted),
            (int) $r->cycles,
        ]);
    }
    $table->finish_output();
    if ($canexport) {
        echo $OUTPUT->download_dataformat_selector(
            get_string('downloadhistory', $component),
            new moodle_url('/local/caregivertraining/export.php'),
            'dataformat',
            ['search' => $search, 'status' => $status, 'sesskey' => sesskey()]
        );
    }
    echo $OUTPUT->footer();
    exit;
}

if ($view === 'blocked') {
    $records = $DB->get_records_sql("SELECT c.*, b.alayacareid {$userfields->selects}
          FROM {local_cgt_cycle} c
          JOIN {local_cgt_binding} b ON b.id = c.bindingid
          JOIN {user} u ON u.id = c.userid
          {$userfields->joins}
         WHERE c.status = 'blocked'
      ORDER BY c.timemodified DESC", $userfields->params);
    if (!$records) {
        echo $OUTPUT->notification(get_string('noblocked', $component), \core\output\notification::NOTIFY_SUCCESS, false);
        echo $OUTPUT->footer();
        exit;
    }
    $table = new flexible_table('local_caregivertraining_blocked');
    $table->define_baseurl($baseurl);
    $table->define_columns(['employee', 'alayacareid', 'cycleid', 'due', 'reason', 'actions']);
    $table->define_headers([get_string('col_employee', $component), get_string('col_alayacareid', $component),
        get_string('col_cycleid', $component), get_string('col_due', $component), get_string('col_reason', $component),
        html_writer::span(get_string('actions'), 'visually-hidden')]);
    $table->set_attribute('class', 'generaltable');
    $table->setup();
    foreach ($records as $r) {
        $table->add_data([
            html_writer::link(new moodle_url($pageurl, ['employee' => $r->bindingid]), s(fullname($r))),
            s($r->alayacareid),
            s($r->cycleid),
            $date($r->anniversarydate),
            s($r->blockedreason),
            $actionmenu($canmanage ? [[$actionurl('retry', (int) $r->id), get_string('retryreset', $component)]] : []),
        ]);
    }
    $table->finish_output();
    echo $OUTPUT->footer();
    exit;
}

// Exceptions.
$filterurl = new moodle_url($pageurl, ['view' => 'exceptions', 'showresolved' => $showresolved ? 0 : 1]);
echo html_writer::div(html_writer::link(
    $filterurl,
    get_string($showresolved ? 'showopenonly' : 'showresolved', $component),
    ['class' => 'btn btn-outline-secondary btn-sm']
), 'mb-3');
$records = $DB->get_records(
    'local_cgt_exception',
    $showresolved ? null : ['status' => 'open'],
    'timemodified DESC',
    '*',
    0,
    500
);
if (!$records) {
    echo $OUTPUT->notification(get_string('noexceptions', $component), \core\output\notification::NOTIFY_SUCCESS, false);
    echo $OUTPUT->footer();
    exit;
}
$table = new flexible_table('local_caregivertraining_exceptions');
$table->define_baseurl($baseurl);
$table->define_columns(['type', 'alayacareid', 'details', 'updated', 'status', 'actions']);
$table->define_headers([get_string('col_type', $component), get_string('col_alayacareid', $component),
    get_string('col_details', $component), get_string('col_updated', $component), get_string('col_status', $component),
    html_writer::span(get_string('actions'), 'visually-hidden')]);
$table->set_attribute('class', 'generaltable');
$table->setup();
foreach ($records as $r) {
    $open = $r->status === 'open';
    $table->add_data([
        s($r->type) . ($r->occurrences > 1 ? ' ' . html_writer::span('×' . (int) $r->occurrences, 'badge bg-secondary') : ''),
        s($r->alayacareid) ?: '-',
        html_writer::tag('code', s($r->details), ['class' => 'text-break']),
        $datetime($r->timemodified),
        html_writer::span(
            get_string('exception_' . ($open ? 'open' : 'resolved'), $component),
            'badge ' . ($open ? 'bg-warning text-dark' : 'bg-success')
        ),
        $actionmenu($canmanage && $open ? [[$actionurl('resolve', (int) $r->id), get_string('resolveexception', $component)]]
            : []),
    ]);
}
$table->finish_output();
echo $OUTPUT->footer();
