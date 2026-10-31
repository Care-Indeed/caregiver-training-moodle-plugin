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
 * CSV training history by employee and Cycle ID: one row per cycle, readable headers and values.
 *
 * @package    local_caregivertraining
 * @copyright  2026 CI Institute of Nursing
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class export {
    /**
     * Column keys mapped to their CSV headers. Rows use the same keys.
     *
     * @return array<string,string>
     */
    public static function columns(): array {
        $keys = ['employee', 'alayacareid', 'payrollnumber', 'hcanumber', 'registrationdate', 'trainingyear', 'cycleid',
            'status', 'hiredate', 'duedate', 'accessenddate', 'completedon', 'ontime', 'approvedhours', 'coursetotal',
            'certificatecode', 'certificateurl', 'evidence', 'archived'];
        $columns = [];
        foreach ($keys as $key) {
            $columns[$key] = get_string('csv_' . $key, config::COMPONENT);
        }
        return $columns;
    }

    /**
     * Full training history of the employees shown on the Training records page for the same search and
     * status (or of one employee): one row per cycle, newest first within each employee, with its evidence
     * snapshots summarised in one cell.
     *
     * @param string $search see {@see records::employees_sql()}; empty = everyone
     * @param string $status see {@see records::employees_sql()}; empty = any
     * @param int $bindingid only this employee, when set
     * @return array[] rows keyed like {@see columns()}
     */
    public static function rows(string $search = '', string $status = '', int $bindingid = 0): array {
        global $DB;
        require_capability('local/caregivertraining:export', \context_system::instance());
        if ($bindingid) {
            $bindingids = [$bindingid];
        } else {
            [, $from, $where, $params] = records::employees_sql($search, $status);
            $bindingids = $DB->get_fieldset_sql("SELECT b.id FROM {$from} WHERE {$where}", $params);
        }
        if (!$bindingids) {
            return [];
        }
        [$insql, $params] = $DB->get_in_or_equal($bindingids, SQL_PARAMS_NAMED, 'binding');
        $userfields = profile_fields::user_fields()->get_sql('u', true);
        $cycles = $DB->get_records_sql("SELECT c.*, b.alayacareid, b.payrollnumber {$userfields->selects}
            FROM {local_cgt_cycle} c
            JOIN {local_cgt_binding} b ON b.id = c.bindingid
            JOIN {user} u ON u.id = c.userid
            {$userfields->joins}
            WHERE c.status <> 'superseded' AND b.id {$insql}
            ORDER BY u.lastname, u.firstname, b.alayacareid, c.timedue DESC, c.id DESC", $params + $userfields->params);
        $snapshots = $cycles ? $DB->get_records_list('local_cgt_snapshot', 'cycleid', array_keys($cycles), 'timecreated')
            : [];
        $evidence = [];
        foreach ($snapshots as $snapshot) {
            $evidence[$snapshot->cycleid][] = self::snapshot_label($snapshot);
        }

        $rows = [];
        $tz = config::timezone();
        $yes = get_string('yes');
        $no = get_string('no');
        foreach ($cycles as $cycle) {
            $employment = profile_fields::values_from_record($cycle);
            $completed = $cycle->status === 'completed' && $cycle->timecompleted;
            $rows[] = [
                'employee' => fullname($cycle),
                'alayacareid' => $cycle->alayacareid,
                'payrollnumber' => $employment['payrollnumber'] !== '' ? $employment['payrollnumber']
                    : (string) $cycle->payrollnumber,
                'hcanumber' => $employment['hcanumber'],
                'registrationdate' => $employment['registrationdate'],
                'trainingyear' => substr($cycle->anniversarydate, 0, 4),
                'cycleid' => $cycle->cycleid,
                'status' => cycle_manager::status_label($cycle),
                'hiredate' => (string) $cycle->hiredate,
                'duedate' => $cycle->anniversarydate,
                'accessenddate' => cycle_manager::access_end_date($cycle->anniversarydate),
                'completedon' => $completed
                    ? (new \DateTimeImmutable('@' . $cycle->timecompleted))->setTimezone($tz)->format('Y-m-d H:i') : '',
                'ontime' => $completed ? ((int) $cycle->timecompleted <= (int) $cycle->timedue ? $yes : $no) : '',
                'approvedhours' => $completed ? number_format($cycle->approvedseconds / HOURSECS, 2, '.', '') : '',
                'coursetotal' => records::course_total($cycle, $snapshots),
                'certificatecode' => (string) $cycle->certificatecode,
                'certificateurl' => $cycle->certificatecode ? completion_manager::verify_url($cycle) : '',
                'evidence' => implode('; ', $evidence[$cycle->id] ?? []),
                'archived' => $cycle->archived ? $yes : $no,
            ];
        }
        return $rows;
    }

    /**
     * One-line description of an evidence snapshot, e.g. "At completion, 2026-03-05 (verified)".
     *
     * @param \stdClass $snapshot local_cgt_snapshot record
     * @return string
     */
    public static function snapshot_label(\stdClass $snapshot): string {
        $type = get_string_manager()->string_exists('snapshottype_' . $snapshot->type, config::COMPONENT)
            ? get_string('snapshottype_' . $snapshot->type, config::COMPONENT) : $snapshot->type;
        return get_string('snapshotlabel', config::COMPONENT, (object) [
            'type' => $type,
            'date' => (new \DateTimeImmutable('@' . $snapshot->timecreated))->setTimezone(config::timezone())->format('Y-m-d'),
            'verified' => get_string($snapshot->verified ? 'snapshotverified' : 'snapshotunverified', config::COMPONENT),
        ]);
    }
}
