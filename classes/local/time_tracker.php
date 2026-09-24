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
 * Server-side time accounting. The browser only reports that a visible page is being used;
 * the server decides how many seconds to credit using its own clock.
 *
 * Rules:
 * - Credit only while the page is visible and media is playing or the learner interacted recently.
 * - Credit per heartbeat is capped by the real elapsed server time since the previous beat.
 * - A gap longer than one interval plus grace (disconnect, sleep, throttled tab) earns nothing.
 * - All sessions of a learner share one credit cursor, so concurrent tabs cannot double-count.
 * - Client timestamps are never read, so changing the device clock has no effect.
 *
 * This measures presence on an approved page, not proof of engagement.
 *
 * @package    local_caregivertraining
 * @copyright  2026 CI Institute of Nursing
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class time_tracker {
    /** @var int Seconds of network jitter tolerated per heartbeat. */
    const GRACE = 10;

    /** @var int Minimum spacing between accepted beats. */
    const MIN_SPACING = 5;

    /**
     * The learner's open cycle in the annual course.
     *
     * @param int $userid
     * @param int|null $now
     * @return \stdClass|null
     */
    public static function open_cycle(int $userid, ?int $now = null): ?\stdClass {
        global $DB;
        $now = $now ?? time();
        $courseid = config::course_id();
        if (!$courseid) {
            return null;
        }
        return $DB->get_record_select(
            'local_cgt_cycle',
            "userid = :u AND courseid = :c AND status = 'open' AND timeopen <= :now",
            ['u' => $userid, 'c' => $courseid, 'now' => $now],
            '*',
            IGNORE_MULTIPLE
        ) ?: null;
    }

    /**
     * Record a heartbeat.
     *
     * @param int $userid
     * @param int $cmid
     * @param string $token random per-page session token
     * @param bool $visible page visible
     * @param bool $playing media playing
     * @param int $interactedago seconds since the last interaction
     * @param int|null $now server time (tests only)
     * @return array
     */
    public static function heartbeat(
        int $userid,
        int $cmid,
        string $token,
        bool $visible,
        bool $playing,
        int $interactedago,
        ?int $now = null
    ): array {
        global $DB;
        $now = $now ?? time();
        if (!preg_match('/^[a-f0-9]{32,64}$/', $token)) {
            throw new \invalid_parameter_exception('sessiontoken must be 32-64 lowercase hex characters');
        }

        $cycle = self::open_cycle($userid, $now);
        if (!$cycle) {
            return self::result(null, $userid, false, 'no_open_cycle');
        }
        $modinfo = get_fast_modinfo($cycle->courseid, $userid);
        $cm = $modinfo->cms[$cmid] ?? null;
        if (!$cm || !$cm->uservisible) {
            return self::result($cycle, $userid, false, 'activity_unavailable');
        }

        $lock = locks::try_acquire('time:' . $userid, 2);
        if (!$lock) {
            return self::result($cycle, $userid, false, 'busy');
        }
        try {
            $cycle = $DB->get_record('local_cgt_cycle', ['id' => $cycle->id], '*', MUST_EXIST);
            $session = $DB->get_record('local_cgt_timesession', ['sessiontoken' => $token]);
            if ($session && ((int) $session->userid !== $userid || (int) $session->cycleid !== (int) $cycle->id)) {
                return self::result($cycle, $userid, false, 'token_mismatch');
            }
            if (!$session) {
                $session = (object) ['cycleid' => $cycle->id, 'userid' => $userid, 'cmid' => $cmid, 'sessiontoken' => $token,
                    'timestarted' => $now, 'timelastbeat' => $now, 'beats' => 1, 'rejectedbeats' => 0,
                    'lastrejectreason' => null, 'creditedseconds' => 0];
                $session->id = $DB->insert_record('local_cgt_timesession', $session);
                return self::result($cycle, $userid, false, 'session_started');
            }
            if ((int) $session->cmid !== $cmid) {
                return self::result($cycle, $userid, false, 'token_mismatch');
            }

            $elapsed = $now - (int) $session->timelastbeat;
            if ($elapsed < self::MIN_SPACING) {
                return self::result($cycle, $userid, false, 'too_frequent');
            }

            $limit = config::heartbeat_seconds() + self::GRACE;
            $reason = null;
            if (!$visible) {
                $reason = 'hidden';
            } else if (!$playing && $interactedago > config::idle_seconds()) {
                $reason = 'idle';
            } else if ($elapsed > $limit) {
                $reason = 'gap';
            } else if (in_array($session->lastrejectreason, ['hidden', 'idle'], true)) {
                // The interval since a hidden/idle beat was not fully engaged; restart the credit window.
                $reason = 'resumed';
            }

            $credit = 0;
            if ($reason === null) {
                // Shared cursor: never credit wall-clock time that another tab already claimed.
                $sincecursor = $now - max((int) $cycle->timelastcredit, (int) $session->timelastbeat);
                $credit = max(0, min($elapsed, $sincecursor, $limit));
                if ($credit === 0) {
                    $reason = 'concurrent';
                }
            }

            $session->timelastbeat = $now;
            $session->beats++;
            if ($credit > 0) {
                $session->creditedseconds += $credit;
                $cycle->timelastcredit = $now;
                $DB->set_field('local_cgt_cycle', 'timelastcredit', $now, ['id' => $cycle->id]);
            } else {
                $session->rejectedbeats++;
                $session->lastrejectreason = $reason;
            }
            $DB->update_record('local_cgt_timesession', $session);
            return self::result($cycle, $userid, $credit > 0, $reason ?? 'credited', $credit);
        } finally {
            $lock->release();
        }
    }

    /**
     * Seconds credited per course module for a cycle.
     *
     * @param int $cycleid local cycle id
     * @return array<int,int>
     */
    public static function seconds_by_cm(int $cycleid): array {
        global $DB;
        $rows = $DB->get_records_sql('SELECT cmid, SUM(creditedseconds) AS seconds FROM {local_cgt_timesession}
            WHERE cycleid = :c GROUP BY cmid', ['c' => $cycleid]);
        return array_map(fn($r) => (int) $r->seconds, $rows);
    }

    /**
     * Approved countable seconds under the active policy (0 when unresolved).
     *
     * @param \stdClass $cycle
     * @return int
     */
    public static function approved_seconds(\stdClass $cycle): int {
        global $CFG;
        switch (config::time_policy()) {
            case config::POLICY_TRACKED:
                $countable = array_flip(config::countable_cmids());
                return array_sum(array_intersect_key(self::seconds_by_cm((int) $cycle->id), $countable));
            case config::POLICY_NOMINAL:
                require_once($CFG->libdir . '/completionlib.php');
                $course = get_course($cycle->courseid);
                $completion = new \completion_info($course);
                $modinfo = get_fast_modinfo($course, (int) $cycle->userid);
                $total = 0;
                foreach (config::nominal_durations() as $cmid => $seconds) {
                    if (!isset($modinfo->cms[$cmid])) {
                        continue;
                    }
                    $data = $completion->get_data($modinfo->cms[$cmid], false, (int) $cycle->userid);
                    if (in_array((int) $data->completionstate, [COMPLETION_COMPLETE, COMPLETION_COMPLETE_PASS], true)) {
                        $total += $seconds;
                    }
                }
                return $total;
            default:
                return 0;
        }
    }

    /**
     * Recorded (not necessarily approved) seconds for display while the policy is unresolved.
     *
     * @param \stdClass $cycle
     * @return int
     */
    public static function recorded_seconds(\stdClass $cycle): int {
        return array_sum(self::seconds_by_cm((int) $cycle->id));
    }

    /**
     * Build a heartbeat response.
     *
     * @param \stdClass|null $cycle
     * @param int $userid
     * @param bool $credited
     * @param string $reason
     * @param int $credit
     * @return array
     */
    private static function result(?\stdClass $cycle, int $userid, bool $credited, string $reason, int $credit = 0): array {
        $policy = config::time_policy();
        $required = config::required_seconds();
        $approved = $cycle ? self::approved_seconds($cycle) : 0;
        $recorded = $cycle ? self::recorded_seconds($cycle) : 0;
        return [
            'credited' => $credited,
            'creditseconds' => $credit,
            'reason' => $reason,
            'policy' => $policy,
            'requiredseconds' => $required,
            'approvedseconds' => $approved,
            'recordedseconds' => $recorded,
            'remainingseconds' => max(0, $required - $approved),
            'intervalseconds' => config::heartbeat_seconds(),
        ];
    }
}
