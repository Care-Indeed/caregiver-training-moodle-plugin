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
 * CSV evidence export by employee and Cycle ID.
 *
 * @package    local_caregivertraining
 * @copyright  2026 CI Institute of Nursing
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class export {
    /**
     * Column headers.
     *
     * @return string[]
     */
    public static function columns(): array {
        return ['alayacareid', 'externalid', 'payrollid', 'hcanumber', 'registrationdate', 'moodleuserid', 'fullname',
            'cycleid', 'status', 'compliance', 'hiredate', 'opendate', 'duedate', 'timecompleted', 'approvedseconds',
            'timepolicy', 'certificatecode', 'resetstate', 'archived', 'snapshotid', 'snapshottype', 'snapshotverified',
            'snapshotsha256', 'snapshotcreated', 'snapshotretainuntil', 'snapshotcounts', 'certificatefiles'];
    }

    /**
     * Rows matching an employee identifier, Cycle ID or certificate code. One row per snapshot
     * (or one row for a cycle without snapshots).
     *
     * @param string $filter empty = all
     * @return array[]
     */
    public static function rows(string $filter): array {
        global $DB;
        require_capability('local/caregivertraining:export', \context_system::instance());
        $filter = trim($filter);
        $params = [];
        $where = '1 = 1';
        if ($filter !== '') {
            $where = '(b.alayacareid = :f1 OR b.externalid = :f2 OR b.payrollid = :f3 OR c.cycleid = :f4
                OR c.certificatecode = :f5)';
            $params = ['f1' => $filter, 'f2' => $filter, 'f3' => $filter, 'f4' => $filter, 'f5' => $filter];
        }
        $userfields = profile_fields::user_fields()->get_sql('u', true);
        if ($filter !== '') {
            $hcanumber = $userfields->mappings[\core_user\fields::PROFILE_FIELD_PREFIX . profile_fields::HCANUMBER];
            $where .= " OR {$hcanumber} = :f6";
            $params['f6'] = $filter;
        }
        $cycles = $DB->get_records_sql("SELECT c.*, b.alayacareid, b.externalid, b.payrollid {$userfields->selects}
            FROM {local_cgt_cycle} c
            JOIN {local_cgt_binding} b ON b.id = c.bindingid
            JOIN {user} u ON u.id = c.userid
            {$userfields->joins}
            WHERE {$where}
            ORDER BY b.alayacareid, c.timedue, c.id", $params + $userfields->params);

        $rows = [];
        $tz = config::timezone();
        foreach ($cycles as $cycle) {
            $employment = profile_fields::values_from_record($cycle);
            $base = [
                $cycle->alayacareid, (string) $cycle->externalid, (string) $cycle->payrollid,
                $employment['hcanumber'], $employment['registrationdate'], (int) $cycle->userid,
                fullname($cycle), $cycle->cycleid, $cycle->status, cycle_manager::compliance($cycle), (string) $cycle->hiredate,
                $cycle->opendate, $cycle->duedate,
                $cycle->timecompleted ? (new \DateTimeImmutable('@' . $cycle->timecompleted))->setTimezone($tz)->format(DATE_ATOM)
                    : '',
                (int) $cycle->approvedseconds, (string) $cycle->timepolicy, (string) $cycle->certificatecode, $cycle->resetstate,
                (int) $cycle->archived,
            ];
            $snapshots = $DB->get_records('local_cgt_snapshot', ['cycleid' => $cycle->id], 'id');
            if (!$snapshots) {
                $rows[] = array_merge($base, array_fill(0, 8, ''));
                continue;
            }
            foreach ($snapshots as $snapshot) {
                $payload = json_decode($snapshot->payload, true);
                $files = array_map(fn($c) => $c['code'] . ':' . $c['pdfsha256'], $payload['certificates'] ?? []);
                $rows[] = array_merge($base, [
                    (int) $snapshot->id, $snapshot->type, (int) $snapshot->verified, $snapshot->payloadhash,
                    (new \DateTimeImmutable('@' . $snapshot->timecreated))->setTimezone($tz)->format(DATE_ATOM),
                    (new \DateTimeImmutable('@' . $snapshot->retainuntil))->setTimezone($tz)->format('Y-m-d'),
                    $snapshot->counts, implode(' ', $files),
                ]);
            }
        }
        return $rows;
    }
}
