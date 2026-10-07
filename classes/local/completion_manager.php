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
 * Evaluates completion gates and, once they pass, issues/locates the certificate and records
 * exactly one completion event and one completion email per cycle.
 *
 * @package    local_caregivertraining
 * @copyright  2026 CI Institute of Nursing
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class completion_manager {
    /** @var string Outbound completion event type. */
    const EVENT_COMPLETED = 'cycle.completed';

    /**
     * Gate check without side effects.
     *
     * @param \stdClass $cycle
     * @return array{passed: bool, reasons: string[], timecompleted: int, approvedseconds: int}
     */
    public static function check_gates(\stdClass $cycle): array {
        global $DB;
        $reasons = [];
        if ($cycle->status !== 'open' || time() < $cycle->timeopen) {
            $reasons[] = 'cycle_not_open';
        }
        $completion = $DB->get_record('course_completions', ['userid' => $cycle->userid, 'course' => $cycle->courseid]);
        $timecompleted = $completion && $completion->timecompleted ? (int) $completion->timecompleted : 0;
        if (!$timecompleted) {
            $reasons[] = 'course_not_complete';
        } else if ($timecompleted < $cycle->timeopen) {
            $reasons[] = 'completion_predates_cycle';
        }

        $policy = config::time_policy();
        $approved = $policy === config::POLICY_UNRESOLVED ? 0 : time_tracker::approved_seconds($cycle);
        if ($policy === config::POLICY_UNRESOLVED) {
            $reasons[] = 'time_policy_unresolved';
        } else if ($approved < config::required_seconds()) {
            $reasons[] = 'time_requirement_not_met';
        }

        $cmid = config::certificate_cmid();
        $cm = $cmid ? get_coursemodule_from_id('customcert', $cmid) : false;
        if (!$cm || (int) $cm->course !== (int) $cycle->courseid) {
            $reasons[] = 'certificate_not_configured';
        }
        return ['passed' => empty($reasons), 'reasons' => $reasons, 'timecompleted' => $timecompleted,
            'approvedseconds' => $approved];
    }

    /**
     * Evaluate a cycle and complete it if every gate passes. Safe to call repeatedly.
     *
     * @param int $cycleid local cycle id
     * @return bool newly completed
     */
    public static function evaluate(int $cycleid): bool {
        global $DB;
        $cycle = $DB->get_record('local_cgt_cycle', ['id' => $cycleid]);
        if (!$cycle || $cycle->status !== 'open') {
            return false;
        }
        $lock = locks::acquire('user:' . $cycle->userid);
        try {
            $cycle = $DB->get_record('local_cgt_cycle', ['id' => $cycleid], '*', MUST_EXIST);
            if ($cycle->status !== 'open') {
                return false;
            }
            $gates = self::check_gates($cycle);
            if (!$gates['passed']) {
                return false;
            }

            $transaction = $DB->start_delegated_transaction();
            try {
                [$issueid, $code] = self::issue_or_locate_certificate($cycle);
                $cycle->status = 'completed';
                $cycle->timecompleted = $gates['timecompleted'];
                $cycle->approvedseconds = $gates['approvedseconds'];
                $cycle->timepolicy = config::time_policy();
                $cycle->certificateissueid = $issueid;
                $cycle->certificatecode = $code;
                $cycle->timemodified = time();
                $DB->update_record('local_cgt_cycle', $cycle);
                self::record_side_effects($cycle);
                $transaction->allow_commit();
            } catch (\Throwable $e) {
                $transaction->rollback($e);
            }

            try {
                evidence::create_snapshot($cycle, 'completion', get_course($cycle->courseid));
            } catch (\Throwable $e) {
                // Completion stands; the verified pre-reset snapshot remains the gate for any later reset.
                exceptions::raise(
                    'completion_snapshot_failed',
                    ['key' => (string) $cycle->id, 'error' => $e->getMessage()],
                    (int) $cycle->userid,
                    null,
                    (int) $cycle->id
                );
            }

            \local_caregivertraining\event\cycle_completed::create([
                'context' => \context_course::instance($cycle->courseid),
                'relateduserid' => (int) $cycle->userid,
                'objectid' => (int) $cycle->id,
                'other' => ['cycleid' => $cycle->cycleid],
            ])->trigger();
            return true;
        } finally {
            $lock->release();
        }
    }

    /**
     * Find the certificate issued in this cycle or issue one through the Custom Certificate API.
     *
     * @param \stdClass $cycle
     * @return array{0:int,1:string}
     */
    private static function issue_or_locate_certificate(\stdClass $cycle): array {
        global $DB;
        $cm = get_coursemodule_from_id('customcert', config::certificate_cmid(), $cycle->courseid, false, MUST_EXIST);
        $issues = $DB->get_records_select(
            'customcert_issues',
            'customcertid = :cc AND userid = :u AND timecreated >= :t',
            ['cc' => $cm->instance, 'u' => $cycle->userid, 't' => $cycle->timeopen],
            'id ASC',
            'id, code',
            0,
            1
        );
        if ($issue = reset($issues)) {
            return [(int) $issue->id, $issue->code];
        }
        $issueid = \mod_customcert\certificate::issue_certificate($cm->instance, (int) $cycle->userid);
        return [(int) $issueid, $DB->get_field('customcert_issues', 'code', ['id' => $issueid], MUST_EXIST)];
    }

    /**
     * Create the outbox event and completion notification rows if missing. Unique indexes make
     * this idempotent across retries and reconciliation.
     *
     * @param \stdClass $cycle completed cycle
     */
    public static function record_side_effects(\stdClass $cycle): void {
        global $DB;
        if (!$DB->record_exists('local_cgt_outbox', ['cycleid' => $cycle->id, 'eventtype' => self::EVENT_COMPLETED])) {
            outbox::enqueue($cycle, self::EVENT_COMPLETED, self::completion_payload($cycle));
        }
        if (config::notification_enabled('completion')) {
            notifier::queue($cycle, 'completion', 'once');
        }
    }

    /**
     * Completion event body (contract v1).
     *
     * @param \stdClass $cycle
     * @return array
     */
    public static function completion_payload(\stdClass $cycle): array {
        global $DB;
        $binding = $DB->get_record('local_cgt_binding', ['id' => $cycle->bindingid], '*', MUST_EXIST);
        $tz = config::timezone();
        $completed = (new \DateTimeImmutable('@' . $cycle->timecompleted))->setTimezone($tz);
        return [
            'cycleid' => $cycle->cycleid,
            'alayacareid' => $binding->alayacareid,
            'payrollnumber' => (string) $binding->payrollnumber,
            'moodleuserid' => (int) $cycle->userid,
            'hiredate' => (string) $cycle->hiredate,
            'anniversarydate' => $cycle->anniversarydate,
            'dueyear' => (int) substr($cycle->anniversarydate, 0, 4),
            'completedat' => $completed->format(DATE_ATOM),
            'completeddate' => $completed->format('Y-m-d'),
            'ontime' => (int) $cycle->timecompleted <= (int) $cycle->timedue,
            'approvedseconds' => (int) $cycle->approvedseconds,
            'timepolicy' => $cycle->timepolicy,
            'certificate' => [
                'code' => $cycle->certificatecode,
                'issueid' => (int) $cycle->certificateissueid,
                'verifyurl' => (new \moodle_url(
                    '/mod/customcert/verify_certificate.php',
                    ['code' => $cycle->certificatecode]
                ))->out(false),
            ],
        ];
    }

    /**
     * Reconcile: evaluate open cycles and repair missing side effects for completed cycles.
     *
     * @return int newly completed count
     */
    public static function reconcile_all(): int {
        global $DB;
        $completed = 0;
        $open = $DB->get_records_select('local_cgt_cycle', "status = 'open'", [], 'id', 'id', 0, 2000);
        foreach ($open as $row) {
            if (self::evaluate((int) $row->id)) {
                $completed++;
            }
        }
        $missing = $DB->get_records_sql("SELECT c.* FROM {local_cgt_cycle} c
            LEFT JOIN {local_cgt_outbox} o ON o.cycleid = c.id AND o.eventtype = :type
            WHERE c.status = 'completed' AND o.id IS NULL", ['type' => self::EVENT_COMPLETED], 0, 500);
        foreach ($missing as $cycle) {
            self::record_side_effects($cycle);
        }
        return $completed;
    }
}
