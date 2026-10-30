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
 * Employee filters and status badges shared by the Training records page and its download.
 *
 * @package    local_caregivertraining
 * @copyright  2026 CI Institute of Nursing
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class records {
    /** @var string[] Statuses the employee list can be filtered by, as returned by cycle_manager::compliance(). */
    const STATUSES = ['open', 'overdue', 'upcoming', 'complete'];

    /** @var array<string,string> Badge colour for each status. */
    const BADGES = ['complete' => 'bg-success', 'open' => 'bg-primary', 'upcoming' => 'bg-secondary',
        'overdue' => 'bg-danger', 'blocked' => 'bg-warning text-dark', 'notapplicable' => 'bg-secondary'];

    /**
     * SQL for employees with at least one cycle, joined to their latest cycle as "c".
     *
     * The search is a partial, case-insensitive match on name, AlayaCare id, payroll number, HCA number,
     * or any of the employee's Cycle IDs or certificate codes. The status filter applies to the latest cycle.
     *
     * @param string $search
     * @param string $status one of STATUSES, or empty for all
     * @return array{0: string, 1: string, 2: string, 3: array} selects (with a leading comma), from, where, params
     */
    public static function employees_sql(string $search = '', string $status = ''): array {
        global $DB;
        $userfields = profile_fields::user_fields()->get_sql('u', true);
        $latest = "SELECT MAX(c2.id) FROM {local_cgt_cycle} c2 WHERE c2.bindingid = b.id AND c2.status <> 'superseded'";
        $from = "{local_cgt_binding} b
                 JOIN {user} u ON u.id = b.userid
                 JOIN {local_cgt_cycle} c ON c.id = ({$latest})
                 {$userfields->joins}";
        $where = ['1 = 1'];
        $params = $userfields->params;
        $search = trim($search);
        if ($search !== '') {
            $hcanumber = $userfields->mappings[\core_user\fields::PROFILE_FIELD_PREFIX . profile_fields::HCANUMBER];
            $like = fn(string $field, string $param) => $DB->sql_like($field, ':' . $param, false);
            $where[] = '(' . implode(' OR ', [
                $like($DB->sql_fullname('u.firstname', 'u.lastname'), 'search1'),
                $like('b.alayacareid', 'search2'),
                $like('b.payrollnumber', 'search3'),
                $like($hcanumber, 'search4'),
                "EXISTS (SELECT 1 FROM {local_cgt_cycle} cs WHERE cs.bindingid = b.id
                    AND (" . $like('cs.cycleid', 'search5') . ' OR ' . $like('cs.certificatecode', 'search6') . '))',
            ]) . ')';
            $value = '%' . $DB->sql_like_escape($search) . '%';
            for ($i = 1; $i <= 6; $i++) {
                $params['search' . $i] = $value;
            }
        }
        if (in_array($status, self::STATUSES, true)) {
            // Mirrors cycle_manager::compliance() so the filter matches the status shown.
            $where[] = "(CASE WHEN c.status = 'completed' THEN 'complete'
                WHEN c.timeopen > :now1 THEN 'upcoming'
                WHEN c.timedue >= :now2 THEN 'open' ELSE 'overdue' END) = :status";
            $params += ['now1' => time(), 'now2' => time(), 'status' => $status];
        }
        return [$userfields->selects, $from, implode(' AND ', $where), $params];
    }

    /**
     * Status badge for a cycle: its compliance state, or "Blocked" while a reset is blocked.
     *
     * @param \stdClass $cycle needs status, timeopen and timedue
     * @return string HTML
     */
    public static function status_badge(\stdClass $cycle): string {
        $key = $cycle->status === 'blocked' ? 'blocked' : cycle_manager::compliance($cycle);
        return \html_writer::span(s(cycle_manager::status_label($cycle)), 'badge ' . self::BADGES[$key]);
    }

    /**
     * The cycle's course total grade, e.g. "92.50 / 100.00". A past cycle uses the grades kept in its evidence (at
     * completion, else before the next year's reset), because the reset clears the gradebook; a current cycle uses
     * the gradebook.
     *
     * @param \stdClass $cycle local_cgt_cycle record
     * @param \stdClass[] $snapshots local_cgt_snapshot records; those of other cycles are ignored
     * @return string empty when there is no grade
     */
    public static function course_total(\stdClass $cycle, array $snapshots): string {
        global $DB;
        $kept = [];
        foreach ($snapshots as $snapshot) {
            // A "preexisting" snapshot holds evidence from before this cycle, so it isn't this cycle's grade.
            if ((int) $snapshot->cycleid === (int) $cycle->id && in_array($snapshot->type, ['completion', 'prereset'], true)) {
                $kept[$snapshot->type] = $snapshot;
            }
        }
        $grade = null;
        if ($snapshot = $kept['completion'] ?? $kept['prereset'] ?? null) {
            foreach (json_decode($snapshot->payload, true)['grades'] ?? [] as $row) {
                if ($row['itemtype'] === 'course') {
                    $grade = [$row['finalgrade'], $row['grademax']];
                }
            }
        } else if (in_array($cycle->status, cycle_manager::ACTIVE_STATUSES, true)) {
            $row = $DB->get_record_sql(
                "SELECT gg.finalgrade, gi.grademax
                   FROM {grade_items} gi
                   JOIN {grade_grades} gg ON gg.itemid = gi.id AND gg.userid = :userid
                  WHERE gi.courseid = :courseid AND gi.itemtype = 'course'",
                ['userid' => $cycle->userid, 'courseid' => $cycle->courseid]
            );
            $grade = $row ? [$row->finalgrade, $row->grademax] : null;
        }
        if (!$grade || $grade[0] === null) {
            return '';
        }
        return format_float((float) $grade[0], 2) . ' / ' . format_float((float) $grade[1], 2);
    }
}
