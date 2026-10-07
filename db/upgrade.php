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
 * Upgrade steps.
 *
 * @package    local_caregivertraining
 * @copyright  2026 CI Institute of Nursing
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Upgrade the plugin.
 *
 * @param int $oldversion
 * @return bool
 */
function xmldb_local_caregivertraining_upgrade($oldversion) {
    global $DB;
    $dbman = $DB->get_manager();

    if ($oldversion < 2026092401) {
        // Idempotent: creates only missing protected profile fields.
        \local_caregivertraining\local\profile_fields::ensure();
        upgrade_plugin_savepoint(true, 2026092401, 'local', 'caregivertraining');
    }

    if ($oldversion < 2026100800) {
        // Binding: drop externalid, rename payrollid to payrollnumber.
        $table = new xmldb_table('local_cgt_binding');
        $index = new xmldb_index('externalid', XMLDB_INDEX_NOTUNIQUE, ['externalid']);
        if ($dbman->index_exists($table, $index)) {
            $dbman->drop_index($table, $index);
        }
        $field = new xmldb_field('externalid');
        if ($dbman->field_exists($table, $field)) {
            $dbman->drop_field($table, $field);
        }
        $index = new xmldb_index('payrollid', XMLDB_INDEX_NOTUNIQUE, ['payrollid']);
        if ($dbman->index_exists($table, $index)) {
            $dbman->drop_index($table, $index);
        }
        $field = new xmldb_field('payrollid', XMLDB_TYPE_CHAR, '100', null, null, null, null, 'alayacareid');
        if ($dbman->field_exists($table, $field)) {
            $dbman->rename_field($table, $field, 'payrollnumber');
        }
        $index = new xmldb_index('payrollnumber', XMLDB_INDEX_NOTUNIQUE, ['payrollnumber']);
        if (!$dbman->index_exists($table, $index)) {
            $dbman->add_index($table, $index);
        }

        // Cycle: the due date is the anniversary date; the open date is derived from it.
        $table = new xmldb_table('local_cgt_cycle');
        $field = new xmldb_field('duedate', XMLDB_TYPE_CHAR, '10', null, XMLDB_NOTNULL, null, null, 'hiredate');
        if ($dbman->field_exists($table, $field)) {
            $dbman->rename_field($table, $field, 'anniversarydate');
        }
        $field = new xmldb_field('opendate');
        if ($dbman->field_exists($table, $field)) {
            $dbman->drop_field($table, $field);
        }
        $field = new xmldb_field('timeaccessend', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0', 'timedue');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }
        $cycles = $DB->get_recordset('local_cgt_cycle', ['timeaccessend' => 0], '', 'id, anniversarydate');
        foreach ($cycles as $cycle) {
            $DB->set_field('local_cgt_cycle', 'timeaccessend',
                \local_caregivertraining\local\cycle_manager::access_end_time($cycle->anniversarydate), ['id' => $cycle->id]);
        }
        $cycles->close();

        // Profile fields: the external id field now holds the AlayaCare id; payroll id becomes payroll number.
        $renames = [
            'cgt_alayacare_externalid' => ['cgt_alayacareid', 'field_alayacareid'],
            'cgt_payrollid' => ['cgt_payrollnumber', 'field_payrollnumber'],
        ];
        foreach ($renames as $old => [$new, $name]) {
            $oldfield = $DB->get_record('user_info_field', ['shortname' => $old]);
            if ($oldfield && !$DB->record_exists('user_info_field', ['shortname' => $new])) {
                $DB->update_record('user_info_field', (object) ['id' => $oldfield->id, 'shortname' => $new,
                    'name' => get_string($name, 'local_caregivertraining')]);
            }
        }
        \local_caregivertraining\local\profile_fields::ensure();
        $fieldid = $DB->get_field('user_info_field', 'id', ['shortname' => 'cgt_alayacareid'], MUST_EXIST);
        $DB->delete_records('user_info_data', ['fieldid' => $fieldid]);
        $bindings = $DB->get_recordset('local_cgt_binding', null, '', 'userid, alayacareid');
        foreach ($bindings as $binding) {
            $DB->insert_record('user_info_data', (object) ['userid' => $binding->userid, 'fieldid' => $fieldid,
                'data' => $binding->alayacareid, 'dataformat' => 0]);
        }
        $bindings->close();

        upgrade_plugin_savepoint(true, 2026100800, 'local', 'caregivertraining');
    }
    return true;
}
