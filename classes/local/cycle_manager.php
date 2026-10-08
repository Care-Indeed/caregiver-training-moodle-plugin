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
 * Annual cycle lifecycle. The adapter sends the anniversary date (the due date); the window
 * opens OPEN_DAYS_BEFORE days earlier and enrolment ends ACCESS_DAYS_AFTER days later.
 *
 * @package    local_caregivertraining
 * @copyright  2026 CI Institute of Nursing
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class cycle_manager {
    /** @var int Days before the anniversary that the training window opens. */
    const OPEN_DAYS_BEFORE = 60;

    /** @var int Days after the anniversary that course access ends. */
    const ACCESS_DAYS_AFTER = 14;

    /** @var string[] Statuses that can no longer change. */
    const FINAL_STATUSES = ['completed', 'superseded'];

    /** @var string[] Statuses that represent the learner's current obligation. */
    const ACTIVE_STATUSES = ['scheduled', 'open', 'blocked'];

    /** @var string[] Employment statuses the adapter may report. */
    const EMPLOYMENT_STATUSES = ['active', 'inactive', 'terminated', 'leave'];

    /**
     * Validate a strict YYYY-MM-DD calendar date.
     *
     * @param string $date
     * @return string
     */
    public static function parse_date(string $date): string {
        $dt = \DateTimeImmutable::createFromFormat('!Y-m-d', $date, config::timezone());
        $errors = \DateTimeImmutable::getLastErrors();
        if (!$dt || ($errors && ($errors['warning_count'] || $errors['error_count'])) || $dt->format('Y-m-d') !== $date) {
            throw new \moodle_exception('error:invaliddate', config::COMPONENT, '', s($date));
        }
        return $date;
    }

    /**
     * First second of a date in the compliance timezone.
     *
     * @param string $date
     * @return int
     */
    public static function start_of_day(string $date): int {
        return \DateTimeImmutable::createFromFormat('!Y-m-d', self::parse_date($date), config::timezone())->getTimestamp();
    }

    /**
     * Last second of a date in the compliance timezone ("due on or before" the date).
     *
     * @param string $date
     * @return int
     */
    public static function end_of_day(string $date): int {
        $start = \DateTimeImmutable::createFromFormat('!Y-m-d', self::parse_date($date), config::timezone());
        return $start->modify('+1 day')->getTimestamp() - 1;
    }

    /**
     * Shift a date by whole calendar days.
     *
     * @param string $date YYYY-MM-DD
     * @param int $days
     * @return string YYYY-MM-DD
     */
    private static function shift_date(string $date, int $days): string {
        $dt = \DateTimeImmutable::createFromFormat('!Y-m-d', self::parse_date($date), config::timezone());
        return $dt->modify(sprintf('%+d days', $days))->format('Y-m-d');
    }

    /**
     * Date the training window opens for an anniversary.
     *
     * @param string $anniversarydate
     * @return string YYYY-MM-DD
     */
    public static function open_date(string $anniversarydate): string {
        return self::shift_date($anniversarydate, -self::OPEN_DAYS_BEFORE);
    }

    /**
     * Last date of course access for an anniversary.
     *
     * @param string $anniversarydate
     * @return string YYYY-MM-DD
     */
    public static function access_end_date(string $anniversarydate): string {
        return self::shift_date($anniversarydate, self::ACCESS_DAYS_AFTER);
    }

    /**
     * Last second of course access for an anniversary.
     *
     * @param string $anniversarydate
     * @return int
     */
    public static function access_end_time(string $anniversarydate): int {
        return self::end_of_day(self::access_end_date($anniversarydate));
    }

    /**
     * Calendar days from today (compliance timezone) to a date; negative once the date has passed.
     * Counted on dates rather than seconds so daylight-saving changes cannot shift the count.
     *
     * @param string $date YYYY-MM-DD
     * @param int|null $now
     * @return int
     */
    public static function days_until(string $date, ?int $now = null): int {
        $utc = new \DateTimeZone('UTC');
        $today = (new \DateTimeImmutable('@' . ($now ?? time())))->setTimezone(config::timezone())->format('Y-m-d');
        $from = \DateTimeImmutable::createFromFormat('!Y-m-d', $today, $utc);
        $to = \DateTimeImmutable::createFromFormat('!Y-m-d', self::parse_date($date), $utc);
        return (int) $from->diff($to)->format('%r%a');
    }

    /**
     * Get a cycle by Cycle ID.
     *
     * @param string $cycleid
     * @return \stdClass|null
     */
    public static function get(string $cycleid): ?\stdClass {
        global $DB;
        return $DB->get_record('local_cgt_cycle', ['cycleid' => $cycleid]) ?: null;
    }

    /**
     * Resolve and check the binding for a request that names both the user and the employee.
     *
     * @param int $userid
     * @param string $alayacareid
     * @return \stdClass binding
     */
    public static function require_binding(int $userid, string $alayacareid): \stdClass {
        global $DB;
        $alayacareid = binding_manager::normalise_id($alayacareid);
        $binding = binding_manager::get_by_alayacareid($alayacareid);
        if (!$binding || (int) $binding->userid !== $userid) {
            exceptions::raise(
                'cycle_binding_mismatch',
                ['key' => (string) $userid, 'requesteduserid' => $userid],
                $binding ? (int) $binding->userid : null,
                $alayacareid
            );
            throw new \moodle_exception('error:bindingmismatch', config::COMPONENT);
        }
        if (!$DB->record_exists('user', ['id' => $userid, 'deleted' => 0])) {
            throw new \moodle_exception('error:usernotfound', config::COMPONENT);
        }
        return $binding;
    }

    /**
     * Validate the Cycle ID format.
     *
     * @param string $cycleid
     * @return string
     */
    public static function normalise_cycleid(string $cycleid): string {
        $cycleid = trim($cycleid);
        if ($cycleid === '' || strlen($cycleid) > 100 || !preg_match('/^[A-Za-z0-9._:\-]+$/', $cycleid)) {
            throw new \invalid_parameter_exception('cycleid must be 1-100 characters of [A-Za-z0-9._:-]');
        }
        return $cycleid;
    }

    /**
     * Create, update or start a cycle.
     *
     * @param array $p cycleid, userid, alayacareid, hiredate, anniversarydate, supersedescycleid
     * @return array
     */
    public static function upsert(array $p): array {
        global $DB;
        $course = config::require_course();
        $cycleid = self::normalise_cycleid($p['cycleid']);
        $userid = (int) $p['userid'];
        $binding = self::require_binding($userid, $p['alayacareid']);

        $anniversarydate = self::parse_date($p['anniversarydate']);
        $hiredate = ($p['hiredate'] ?? '') !== '' ? self::parse_date($p['hiredate']) : null;
        $timeopen = self::start_of_day(self::open_date($anniversarydate));
        $timedue = self::end_of_day($anniversarydate);
        $timeaccessend = self::access_end_time($anniversarydate);

        $lock = locks::acquire('user:' . $userid);
        try {
            $now = time();
            $existing = self::get($cycleid);
            if ($existing) {
                if ((int) $existing->userid !== $userid || (int) $existing->courseid !== (int) $course->id) {
                    exceptions::raise('cycleid_reused', ['key' => $cycleid], $userid, $binding->alayacareid, (int) $existing->id);
                    throw new \moodle_exception('error:cycleconflict', config::COMPONENT, '', 'cycleid belongs to another learner');
                }
                $same = $existing->anniversarydate === $anniversarydate
                    && (string) $existing->hiredate === (string) $hiredate;
                if (in_array($existing->status, self::FINAL_STATUSES, true)) {
                    if (!$same) {
                        throw new \moodle_exception('error:cycleimmutable', config::COMPONENT, '', s($cycleid));
                    }
                    return ['action' => 'unchanged', 'cycle' => self::export($existing)];
                }
                $action = 'unchanged';
                if (!$same) {
                    $existing->anniversarydate = $anniversarydate;
                    $existing->hiredate = $hiredate;
                    $existing->timeopen = $timeopen;
                    $existing->timedue = $timedue;
                    $existing->timeaccessend = $timeaccessend;
                    $existing->timemodified = $now;
                    $DB->update_record('local_cgt_cycle', $existing);
                    $action = 'updated';
                }
                $cycle = $existing;
            } else {
                // Opening another cycle for an anniversary that is already complete would reset the finished progress.
                $done = $DB->get_records('local_cgt_cycle', ['userid' => $userid, 'courseid' => $course->id,
                    'status' => 'completed', 'anniversarydate' => $anniversarydate], 'id ASC', 'id, cycleid', 0, 1);
                if ($done = reset($done)) {
                    throw new \moodle_exception(
                        'error:alreadycompleted',
                        config::COMPONENT,
                        '',
                        (object) ['cycleid' => $done->cycleid, 'anniversarydate' => $anniversarydate]
                    );
                }
                $supersedes = trim((string) ($p['supersedescycleid'] ?? ''));
                [$insql, $inparams] = $DB->get_in_or_equal(self::ACTIVE_STATUSES, SQL_PARAMS_NAMED);
                $active = $DB->get_records_select(
                    'local_cgt_cycle',
                    "userid = :u AND courseid = :c AND status {$insql}",
                    ['u' => $userid, 'c' => $course->id] + $inparams
                );
                $superseded = null;
                foreach ($active as $other) {
                    if ($other->anniversarydate === $anniversarydate) {
                        throw new \moodle_exception(
                            'error:cycleconflict',
                            config::COMPONENT,
                            '',
                            "cycle {$other->cycleid} already covers anniversary date {$anniversarydate}"
                        );
                    }
                    if ($supersedes !== '' && $other->cycleid === $supersedes) {
                        $superseded = $other;
                        continue;
                    }
                    throw new \moodle_exception(
                        'error:cycleconflict',
                        config::COMPONENT,
                        '',
                        "learner has active cycle {$other->cycleid}; send supersedescycleid to replace it"
                    );
                }
                if ($supersedes !== '' && !$superseded) {
                    throw new \moodle_exception(
                        'error:cycleconflict',
                        config::COMPONENT,
                        '',
                        "supersedescycleid {$supersedes} is not an active cycle for this learner"
                    );
                }

                $cycle = (object) [
                    'cycleid' => $cycleid,
                    'bindingid' => $binding->id,
                    'userid' => $userid,
                    'courseid' => $course->id,
                    'hiredate' => $hiredate,
                    'anniversarydate' => $anniversarydate,
                    'timeopen' => $timeopen,
                    'timedue' => $timedue,
                    'timeaccessend' => $timeaccessend,
                    'status' => 'scheduled',
                    'employmentstatus' => 'active',
                    'enrolmentstatus' => 'active',
                    'remindersenabled' => 1,
                    'approvedseconds' => 0,
                    'timelastcredit' => 0,
                    'resetstate' => 'pending',
                    'archived' => 0,
                    'timecreated' => $now,
                    'timemodified' => $now,
                ];
                try {
                    $cycle->id = $DB->insert_record('local_cgt_cycle', $cycle);
                } catch (\dml_write_exception $e) {
                    throw new \moodle_exception('error:cycleconflict', config::COMPONENT, '', 'concurrent cycle creation');
                }
                if ($superseded) {
                    $DB->update_record('local_cgt_cycle', (object) ['id' => $superseded->id, 'status' => 'superseded',
                        'supersededby' => $cycle->id, 'timemodified' => $now]);
                }
                $action = 'created';
            }

            if ($cycle->status === 'scheduled') {
                self::ensure_future_enrolment($cycle, $course);
                if ($cycle->timeopen <= time()) {
                    $cycle = self::start($cycle);
                }
            } else if ($cycle->status === 'open') {
                self::apply_enrolment($cycle, $course);
            }

            \local_caregivertraining\event\cycle_upserted::create([
                'context' => \context_course::instance($course->id),
                'relateduserid' => $userid,
                'objectid' => (int) $cycle->id,
                'other' => ['cycleid' => $cycleid, 'action' => $action],
            ])->trigger();

            return ['action' => $action, 'cycle' => self::export(self::get($cycleid))];
        } finally {
            $lock->release();
        }
    }

    /**
     * Before the window opens, create an enrolment that only starts on the open date. An existing
     * enrolment from a prior cycle is left as it is until the reset runs at open time.
     *
     * @param \stdClass $cycle
     * @param \stdClass $course
     */
    private static function ensure_future_enrolment(\stdClass $cycle, \stdClass $course): void {
        global $DB;
        $instance = self::manual_instance($course);
        if (!$DB->record_exists('user_enrolments', ['enrolid' => $instance->id, 'userid' => $cycle->userid])) {
            enrol_get_plugin('manual')->enrol_user(
                $instance,
                (int) $cycle->userid,
                config::role_id(),
                (int) $cycle->timeopen,
                (int) $cycle->timeaccessend,
                ENROL_USER_ACTIVE
            );
        }
    }

    /**
     * Apply the cycle's enrolment: starts at the open date and ends ACCESS_DAYS_AFTER days after the due date.
     *
     * @param \stdClass $cycle
     * @param \stdClass $course
     */
    public static function apply_enrolment(\stdClass $cycle, \stdClass $course): void {
        $instance = self::manual_instance($course);
        $status = $cycle->enrolmentstatus === 'suspended' ? ENROL_USER_SUSPENDED : ENROL_USER_ACTIVE;
        enrol_get_plugin('manual')->enrol_user(
            $instance,
            (int) $cycle->userid,
            config::role_id(),
            (int) $cycle->timeopen,
            (int) $cycle->timeaccessend,
            $status
        );
    }

    /**
     * The course's manual enrolment instance, created if missing.
     *
     * @param \stdClass $course
     * @return \stdClass
     */
    private static function manual_instance(\stdClass $course): \stdClass {
        global $DB;
        $plugin = enrol_get_plugin('manual');
        if (!$plugin) {
            throw new \moodle_exception('error:notconfigured', config::COMPONENT, '', 'enrol_manual disabled');
        }
        $instance = $DB->get_record('enrol', ['courseid' => $course->id, 'enrol' => 'manual'], '*', IGNORE_MULTIPLE);
        if (!$instance) {
            $id = $plugin->add_default_instance($course);
            $instance = $DB->get_record('enrol', ['id' => $id], '*', MUST_EXIST);
        }
        if ((int) $instance->status !== ENROL_INSTANCE_ENABLED) {
            throw new \moodle_exception('error:notconfigured', config::COMPONENT, '', 'manual enrolment instance disabled');
        }
        return $instance;
    }

    /**
     * Start a scheduled (or retried blocked) cycle: snapshot and reset prior evidence, then open access.
     * On any snapshot/reset failure the learner's progress is untouched and the cycle is blocked.
     *
     * @param \stdClass $cycle
     * @return \stdClass updated cycle
     */
    public static function start(\stdClass $cycle): \stdClass {
        global $DB;
        $lock = locks::acquire('user:' . $cycle->userid);
        try {
            $cycle = $DB->get_record('local_cgt_cycle', ['id' => $cycle->id], '*', MUST_EXIST);
            if (!in_array($cycle->status, ['scheduled', 'blocked'], true) || $cycle->timeopen > time()) {
                return $cycle;
            }
            $course = get_course($cycle->courseid);
            $counts = evidence::live_counts((int) $cycle->userid, (int) $course->id);
            $resetdone = false;

            if (evidence::has_evidence($counts)) {
                $prior = $DB->get_records_select(
                    'local_cgt_cycle',
                    'userid = :u AND courseid = :c AND id <> :id AND timeopen <= :t',
                    ['u' => $cycle->userid,
                    'c' => $course->id, 'id' => $cycle->id, 't' => $cycle->timeopen],
                    'timedue DESC, id DESC',
                    '*',
                    0,
                    1
                );
                $owner = $prior ? reset($prior) : $cycle;
                $type = $prior ? 'prereset' : 'preexisting';
                try {
                    $snapshot = evidence::create_snapshot($owner, $type, $course);
                    evidence::reset_learner((int) $cycle->userid, $course, $snapshot);
                } catch (\Throwable $e) {
                    return self::block($cycle, $e);
                }
                if ($prior) {
                    $DB->set_field('local_cgt_cycle', 'resetstate', 'done', ['id' => $owner->id]);
                }
                $resetdone = true;
            }

            $cycle->status = 'open';
            $cycle->resetstate = $resetdone ? 'done' : 'notrequired';
            $cycle->blockedreason = null;
            $cycle->timelastcredit = 0;
            $cycle->timemodified = time();
            $DB->update_record('local_cgt_cycle', $cycle);
            self::apply_enrolment($cycle, $course);

            $context = \context_course::instance($course->id);
            if ($resetdone) {
                \local_caregivertraining\event\learner_reset::create(['context' => $context,
                    'relateduserid' => (int) $cycle->userid, 'objectid' => (int) $cycle->id,
                    'other' => ['cycleid' => $cycle->cycleid]])->trigger();
            }
            \local_caregivertraining\event\cycle_started::create(['context' => $context,
                'relateduserid' => (int) $cycle->userid, 'objectid' => (int) $cycle->id,
                'other' => ['cycleid' => $cycle->cycleid]])->trigger();
            return $cycle;
        } finally {
            $lock->release();
        }
    }

    /**
     * Mark a cycle blocked for Engineering review without touching learner progress.
     *
     * @param \stdClass $cycle
     * @param \Throwable $error
     * @return \stdClass
     */
    private static function block(\stdClass $cycle, \Throwable $error): \stdClass {
        global $DB;
        $reason = $error->getMessage();
        if (!($error instanceof \moodle_exception && in_array($error->errorcode, ['snapshotfailed', 'resetfailed'], true))) {
            $reason = get_class($error) . ': ' . $reason;
            if ($error instanceof \moodle_exception && $error->debuginfo) {
                $reason .= ' (' . $error->debuginfo . ')';
            }
        }
        $cycle->status = 'blocked';
        $cycle->resetstate = 'blocked';
        $cycle->blockedreason = \core_text::substr($reason, 0, 1000);
        $cycle->timemodified = time();
        $DB->update_record('local_cgt_cycle', $cycle);
        exceptions::raise(
            'reset_blocked',
            ['key' => (string) $cycle->id, 'reason' => $cycle->blockedreason],
            (int) $cycle->userid,
            null,
            (int) $cycle->id
        );
        \local_caregivertraining\event\cycle_blocked::create(['context' => \context_course::instance($cycle->courseid),
            'relateduserid' => (int) $cycle->userid, 'objectid' => (int) $cycle->id,
            'other' => ['cycleid' => $cycle->cycleid, 'reason' => $cycle->blockedreason]])->trigger();
        return $cycle;
    }

    /**
     * Start every scheduled cycle whose open time has passed.
     *
     * @return int started count
     */
    public static function start_due_cycles(): int {
        global $DB;
        $started = 0;
        $due = $DB->get_records_select(
            'local_cgt_cycle',
            "status = 'scheduled' AND timeopen <= :now",
            ['now' => time()],
            'timeopen ASC',
            '*',
            0,
            500
        );
        foreach ($due as $cycle) {
            $result = self::start($cycle);
            if ($result->status === 'open') {
                $started++;
            }
        }
        return $started;
    }

    /**
     * Retry a blocked cycle after Engineering review.
     *
     * @param int $id local cycle id
     * @return \stdClass
     */
    public static function retry_blocked(int $id): \stdClass {
        global $DB;
        $cycle = $DB->get_record('local_cgt_cycle', ['id' => $id, 'status' => 'blocked'], '*', MUST_EXIST);
        $result = self::start($cycle);
        \local_caregivertraining\event\cycle_retried::create(['context' => \context_system::instance(),
            'relateduserid' => (int) $cycle->userid, 'objectid' => (int) $cycle->id,
            'other' => ['cycleid' => $cycle->cycleid, 'result' => $result->status]])->trigger();
        return $result;
    }

    /**
     * Apply adapter-decided access state. Policy decisions stay in the adapter; termination always
     * stops reminders; leave access is an unresolved HR policy and is only recorded.
     *
     * @param array $p
     * @return array
     */
    public static function update_access(array $p): array {
        global $DB;
        $cycleid = self::normalise_cycleid($p['cycleid']);
        $userid = (int) $p['userid'];
        self::require_binding($userid, $p['alayacareid']);
        $employment = $p['employmentstatus'];
        if (!in_array($employment, self::EMPLOYMENT_STATUSES, true)) {
            throw new \invalid_parameter_exception('employmentstatus must be one of ' . implode(', ', self::EMPLOYMENT_STATUSES));
        }
        $enrolment = (string) ($p['enrolmentstatus'] ?? '');
        if (!in_array($enrolment, ['', 'active', 'suspended'], true)) {
            throw new \invalid_parameter_exception('enrolmentstatus must be active, suspended or empty');
        }
        $reminders = (int) ($p['remindersenabled'] ?? -1);

        $lock = locks::acquire('user:' . $userid);
        try {
            $cycle = self::get($cycleid);
            if (!$cycle || (int) $cycle->userid !== $userid) {
                throw new \moodle_exception('error:cyclenotfound', config::COMPONENT);
            }
            $policygates = [];
            $cycle->employmentstatus = $employment;
            if ($enrolment !== '') {
                $cycle->enrolmentstatus = $enrolment;
            }
            if ($reminders >= 0) {
                $cycle->remindersenabled = $reminders;
            }
            if ($employment === 'terminated') {
                $cycle->remindersenabled = 0;
            }
            if ($employment === 'leave' && ($enrolment === '' || $reminders < 0)) {
                $policygates[] = 'leave_access_unresolved';
            }
            if ($employment === 'terminated' && $enrolment === '') {
                $policygates[] = 'terminated_access_unresolved';
            }
            $cycle->timemodified = time();
            $DB->update_record('local_cgt_cycle', $cycle);

            $course = get_course($cycle->courseid);
            if ($cycle->status === 'open' || ($cycle->status === 'completed' && $enrolment !== '')) {
                self::apply_enrolment($cycle, $course);
            } else if ($cycle->status === 'scheduled' && $enrolment === 'suspended') {
                $instance = self::manual_instance($course);
                enrol_get_plugin('manual')->update_user_enrol($instance, $userid, ENROL_USER_SUSPENDED);
            }

            \local_caregivertraining\event\access_updated::create(['context' => \context_course::instance($course->id),
                'relateduserid' => $userid, 'objectid' => (int) $cycle->id,
                'other' => ['cycleid' => $cycleid, 'employmentstatus' => $employment,
                    'enrolmentstatus' => $cycle->enrolmentstatus,
                    'remindersenabled' => (int) $cycle->remindersenabled]])->trigger();

            return ['cycle' => self::export(self::get($cycleid)), 'policygates' => $policygates];
        } finally {
            $lock->release();
        }
    }

    /**
     * Derived compliance label. Overdue cycles stay accessible until the access end.
     *
     * @param \stdClass $cycle
     * @param int|null $now
     * @return string
     */
    public static function compliance(\stdClass $cycle, ?int $now = null): string {
        $now = $now ?? time();
        if ($cycle->status === 'completed') {
            return 'complete';
        }
        if ($cycle->status === 'superseded') {
            return 'notapplicable';
        }
        if ($now < $cycle->timeopen) {
            return 'upcoming';
        }
        return $now <= $cycle->timedue ? 'open' : 'overdue';
    }

    /**
     * Export for API responses and CSV.
     *
     * @param \stdClass $cycle
     * @return array
     */
    public static function export(\stdClass $cycle): array {
        global $DB;
        $binding = $DB->get_record('local_cgt_binding', ['id' => $cycle->bindingid]);
        $events = $DB->get_records('local_cgt_outbox', ['cycleid' => $cycle->id], 'id', 'id, eventtype, eventid, status, attempts');
        return [
            'cycleid' => $cycle->cycleid,
            'userid' => (int) $cycle->userid,
            'alayacareid' => $binding ? $binding->alayacareid : '',
            'status' => $cycle->status,
            'compliance' => self::compliance($cycle),
            'hiredate' => (string) $cycle->hiredate,
            'anniversarydate' => $cycle->anniversarydate,
            'timeopen' => (int) $cycle->timeopen,
            'timedue' => (int) $cycle->timedue,
            'timeaccessend' => (int) $cycle->timeaccessend,
            'employmentstatus' => $cycle->employmentstatus,
            'enrolmentstatus' => $cycle->enrolmentstatus,
            'remindersenabled' => (bool) $cycle->remindersenabled,
            'resetstate' => $cycle->resetstate,
            'blockedreason' => (string) $cycle->blockedreason,
            'timecompleted' => (int) $cycle->timecompleted,
            'approvedseconds' => (int) $cycle->approvedseconds,
            'timepolicy' => (string) $cycle->timepolicy,
            'certificatecode' => (string) $cycle->certificatecode,
            'events' => array_values(array_map(fn($e) => ['eventtype' => $e->eventtype, 'eventid' => $e->eventid,
                'status' => $e->status, 'attempts' => (int) $e->attempts], $events)),
        ];
    }
}
