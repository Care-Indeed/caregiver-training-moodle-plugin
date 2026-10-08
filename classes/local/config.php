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
 * Typed access to plugin configuration. Environment variables override adapter settings.
 *
 * @package    local_caregivertraining
 * @copyright  2026 CI Institute of Nursing
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class config {
    /** @var string Component name. */
    const COMPONENT = 'local_caregivertraining';

    /** @var string Contract version exposed to the adapter. */
    const CONTRACT_VERSION = 'v1';

    /** @var string Completion blocked until HR approves a rule. */
    const POLICY_UNRESOLVED = 'unresolved';
    /** @var string Server-accounted heartbeat time. */
    const POLICY_TRACKED = 'tracked';
    /** @var string Approved nominal duration per completed activity. */
    const POLICY_NOMINAL = 'nominal';

    /** @var string[] Notification types. */
    const NOTIFICATION_TYPES = ['windowopen', 'reminder', 'due', 'overdue', 'completion'];

    /**
     * Raw plugin setting.
     *
     * @param string $name
     * @param mixed $default
     * @return mixed
     */
    public static function get(string $name, $default = null) {
        $value = get_config(self::COMPONENT, $name);
        return ($value === false || $value === null) ? $default : $value;
    }

    /**
     * Annual course id, or 0 if not configured.
     *
     * @return int
     */
    public static function course_id(): int {
        return (int) self::get('courseid', 0);
    }

    /**
     * Learner role id; falls back to the student archetype role.
     *
     * @return int
     */
    public static function role_id(): int {
        global $DB;
        $roleid = (int) self::get('roleid', 0);
        if ($roleid) {
            return $roleid;
        }
        $role = $DB->get_record('role', ['archetype' => 'student'], 'id', IGNORE_MULTIPLE);
        return $role ? (int) $role->id : 0;
    }

    /**
     * Custom certificate course module id.
     *
     * @return int
     */
    public static function certificate_cmid(): int {
        return (int) self::get('certificatecmid', 0);
    }

    /**
     * Compliance timezone.
     *
     * @return \DateTimeZone
     */
    public static function timezone(): \DateTimeZone {
        $name = (string) self::get('compliancetimezone', 'America/Los_Angeles');
        try {
            return new \DateTimeZone($name);
        } catch (\Exception $e) {
            return new \DateTimeZone('America/Los_Angeles');
        }
    }

    /**
     * Active time policy.
     *
     * @return string
     */
    public static function time_policy(): string {
        $policy = (string) self::get('timepolicy', self::POLICY_UNRESOLVED);
        return in_array($policy, [self::POLICY_TRACKED, self::POLICY_NOMINAL], true) ? $policy : self::POLICY_UNRESOLVED;
    }

    /**
     * Required seconds.
     *
     * @return int
     */
    public static function required_seconds(): int {
        return max(0, (int) self::get('requiredseconds', 18000));
    }

    /**
     * Countable course module ids: every activity in the annual course (minus excluded activity types) when
     * countallactivities is on, otherwise the explicit countablecmids list.
     *
     * @return int[]
     */
    public static function countable_cmids(): array {
        if (self::get('countallactivities', 0)) {
            $courseid = self::course_id();
            if (!$courseid) {
                return [];
            }
            try {
                $modinfo = get_fast_modinfo($courseid);
            } catch (\dml_missing_record_exception $e) {
                return [];
            }
            $excluded = self::excluded_module_ids();
            $ids = [];
            foreach ($modinfo->get_cms() as $cm) {
                if (!$cm->deletioninprogress && !in_array((int) $cm->module, $excluded, true)) {
                    $ids[] = (int) $cm->id;
                }
            }
            return $ids;
        }
        $raw = (string) self::get('countablecmids', '');
        $ids = array_filter(array_map('intval', preg_split('/[\s,]+/', $raw, -1, PREG_SPLIT_NO_EMPTY)));
        return array_values(array_unique($ids));
    }

    /**
     * Activity types ({modules} ids) left out when every activity counts.
     *
     * @return int[]
     */
    public static function excluded_module_ids(): array {
        $raw = (string) self::get('excludedmodules', '');
        return array_values(array_filter(array_map('intval', explode(',', $raw))));
    }

    /**
     * Nominal durations keyed by cmid.
     *
     * @return array<int,int>
     */
    public static function nominal_durations(): array {
        $decoded = json_decode((string) self::get('nominaldurations', '{}'), true);
        if (!is_array($decoded)) {
            return [];
        }
        $result = [];
        foreach ($decoded as $cmid => $seconds) {
            if ((int) $cmid > 0 && (int) $seconds > 0) {
                $result[(int) $cmid] = (int) $seconds;
            }
        }
        return $result;
    }

    /**
     * Heartbeat interval.
     *
     * @return int
     */
    public static function heartbeat_seconds(): int {
        return min(300, max(10, (int) self::get('heartbeatseconds', 30)));
    }

    /**
     * Idle threshold.
     *
     * @return int
     */
    public static function idle_seconds(): int {
        return max(15, (int) self::get('idleseconds', 120));
    }

    /**
     * Whether a notification type is enabled. Only window-open and completion default on.
     *
     * @param string $type
     * @return bool
     */
    public static function notification_enabled(string $type): bool {
        $defaults = ['windowopen' => 1, 'completion' => 1, 'due' => 0, 'overdue' => 0];
        if ($type === 'reminder') {
            return !empty(self::reminder_offsets());
        }
        return (bool) self::get('notify_' . $type, $defaults[$type] ?? 0);
    }

    /**
     * Reminder offsets in whole days before due. Empty by default: no schedule is approved.
     *
     * @return int[]
     */
    public static function reminder_offsets(): array {
        $raw = (string) self::get('reminderoffsets', '');
        $days = array_filter(array_map('intval', preg_split('/[\s,]+/', $raw, -1, PREG_SPLIT_NO_EMPTY)), fn($d) => $d > 0);
        $days = array_values(array_unique($days));
        rsort($days);
        return $days;
    }

    /**
     * HR copy recipients.
     *
     * @return string[]
     */
    public static function hr_cc_emails(): array {
        $raw = (string) self::get('hrccemails', '');
        $emails = array_filter(array_map('trim', explode(',', $raw)), fn($e) => validate_email($e));
        return array_values(array_unique($emails));
    }

    /**
     * Template subject/body with language-string defaults.
     *
     * @param string $type
     * @param string $part subject or body
     * @return string
     */
    public static function template(string $type, string $part): string {
        $value = trim((string) self::get("template_{$part}_{$type}", ''));
        return $value !== '' ? $value : get_string("default_{$part}_{$type}", self::COMPONENT);
    }

    /**
     * Whether outbound adapter delivery is enabled (environment overrides config).
     *
     * @return bool
     */
    public static function adapter_enabled(): bool {
        $env = getenv('CAREGIVERTRAINING_ADAPTER_ENABLED');
        if ($env !== false && $env !== '') {
            return $env === '1';
        }
        return (bool) self::get('adapterenabled', 0);
    }

    /**
     * Adapter endpoint.
     *
     * @return string
     */
    public static function adapter_url(): string {
        $env = getenv('CAREGIVERTRAINING_ADAPTER_URL');
        return ($env !== false && $env !== '') ? $env : (string) self::get('adapterurl', '');
    }

    /**
     * Whether an adapter URL is safe to deliver to: HTTPS, or plain HTTP to a loopback host for local testing.
     *
     * @param string $url
     * @return bool
     */
    public static function adapter_url_valid(string $url): bool {
        $parts = parse_url($url);
        if (!$parts || empty($parts['host']) || !isset($parts['scheme'])) {
            return false;
        }
        $scheme = strtolower($parts['scheme']);
        $host = strtolower(trim($parts['host'], '[]'));
        $loopback = $host === 'localhost' || $host === '::1' || preg_match('/^127(\.\d{1,3}){3}$/', $host);
        return $scheme === 'https' || ($scheme === 'http' && $loopback);
    }

    /**
     * Adapter signing secret. Never log this value.
     *
     * @return string
     */
    public static function adapter_secret(): string {
        $env = getenv('CAREGIVERTRAINING_ADAPTER_SECRET');
        return ($env !== false && $env !== '') ? $env : (string) self::get('adaptersecret', '');
    }

    /**
     * Maximum outbox delivery attempts.
     *
     * @return int
     */
    public static function max_attempts(): int {
        return max(1, (int) self::get('maxattempts', 20));
    }

    /**
     * Snapshot retention in years (minimum three).
     *
     * @return int
     */
    public static function retention_years(): int {
        return max(3, (int) self::get('retentionyears', 3));
    }

    /**
     * Throw if the annual course is not configured.
     *
     * @return \stdClass course record
     */
    public static function require_course(): \stdClass {
        global $DB;
        $courseid = self::course_id();
        $course = $courseid ? $DB->get_record('course', ['id' => $courseid]) : false;
        if (!$course || $courseid == SITEID) {
            throw new \moodle_exception('error:notconfigured', self::COMPONENT, '', 'courseid');
        }
        return $course;
    }
}
