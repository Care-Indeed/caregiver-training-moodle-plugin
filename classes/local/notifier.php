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
 * Configurable cycle notifications. One row per (cycle, type, occurrence) guarantees each email is
 * sent at most once; a crash mid-send leaves the row in "sending" for review instead of resending.
 *
 * @package    local_caregivertraining
 * @copyright  2026 CI Institute of Nursing
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class notifier {
    /** @var int Retries for sends that reported failure. */
    const MAX_ATTEMPTS = 5;

    /**
     * Insert a notification row if it does not exist.
     *
     * @param \stdClass $cycle
     * @param string $type
     * @param string $occurrence
     * @return bool created
     */
    public static function queue(\stdClass $cycle, string $type, string $occurrence): bool {
        global $DB;
        if ($DB->record_exists('local_cgt_notification', ['cycleid' => $cycle->id, 'type' => $type, 'occurrence' => $occurrence])) {
            return false;
        }
        $now = time();
        try {
            $DB->insert_record('local_cgt_notification', (object) ['cycleid' => $cycle->id, 'type' => $type,
                'occurrence' => $occurrence, 'status' => 'queued', 'attempts' => 0, 'timecreated' => $now,
                'timemodified' => $now]);
            return true;
        } catch (\dml_write_exception $e) {
            return false;
        }
    }

    /**
     * Queue notifications that are due now for open cycles.
     *
     * @param int|null $now
     * @return int queued
     */
    public static function queue_due_notifications(?int $now = null): int {
        global $DB;
        $now = $now ?? time();
        $queued = 0;
        $cycles = $DB->get_records_select(
            'local_cgt_cycle',
            "status = 'open' AND archived = 0 AND remindersenabled = 1 AND employmentstatus <> 'terminated'",
            [],
            'id'
        );
        foreach ($cycles as $cycle) {
            if (config::notification_enabled('windowopen') && $now >= $cycle->timeopen) {
                $queued += (int) self::queue($cycle, 'windowopen', 'once');
            }
            $duestart = cycle_manager::start_of_day($cycle->anniversarydate);
            foreach (config::reminder_offsets() as $days) {
                $at = (new \DateTimeImmutable('@' . $duestart))->setTimezone(config::timezone())
                    ->modify("-{$days} days")->getTimestamp();
                // Only send reminders whose moment has arrived and that still precede the due date.
                if ($now >= $at && $now < $duestart && $at >= $cycle->timeopen) {
                    $queued += (int) self::queue($cycle, 'reminder', 'd' . $days);
                }
            }
            if (config::notification_enabled('due') && $now >= $duestart && $now <= $cycle->timedue) {
                $queued += (int) self::queue($cycle, 'due', 'once');
            }
            if (config::notification_enabled('overdue') && $now > $cycle->timedue) {
                $queued += (int) self::queue($cycle, 'overdue', 'once');
            }
        }
        return $queued;
    }

    /**
     * Send queued notifications.
     *
     * @return int sent
     */
    public static function send_queued(): int {
        global $DB;
        $sent = 0;
        $rows = $DB->get_records_select('local_cgt_notification', "status IN ('queued', 'retry')", [], 'id', '*', 0, 500);
        foreach ($rows as $row) {
            $lock = locks::try_acquire('notification:' . $row->id, 0);
            if (!$lock) {
                continue;
            }
            try {
                $row = $DB->get_record('local_cgt_notification', ['id' => $row->id]);
                if (!$row || !in_array($row->status, ['queued', 'retry'], true)) {
                    continue;
                }
                $DB->set_field('local_cgt_notification', 'status', 'sending', ['id' => $row->id]);
                $sent += (int) self::process($row);
            } finally {
                $lock->release();
            }
        }
        return $sent;
    }

    /**
     * Send one claimed notification row.
     *
     * @param \stdClass $row
     * @return bool sent
     */
    private static function process(\stdClass $row): bool {
        global $DB;
        $cycle = $DB->get_record('local_cgt_cycle', ['id' => $row->cycleid]);
        $skip = !$cycle || ($row->type !== 'completion' && ($cycle->status !== 'open' || !$cycle->remindersenabled
            || $cycle->employmentstatus === 'terminated'));
        if ($skip) {
            $DB->update_record('local_cgt_notification', (object) ['id' => $row->id, 'status' => 'skipped',
                'timemodified' => time()]);
            return false;
        }
        $row->attempts++;
        $ok = false;
        $error = null;
        try {
            $ok = self::deliver($cycle, $row->type);
        } catch (\Throwable $e) {
            $error = get_class($e) . ': ' . $e->getMessage();
        }
        $update = (object) ['id' => $row->id, 'attempts' => $row->attempts, 'timemodified' => time()];
        if ($ok) {
            $update->status = 'sent';
            $update->timesent = time();
        } else {
            $update->status = $row->attempts >= self::MAX_ATTEMPTS ? 'failed' : 'retry';
            $update->lasterror = \core_text::substr($error ?? 'message_send returned false', 0, 1000);
            if ($update->status === 'failed') {
                exceptions::raise(
                    'notification_failed',
                    ['key' => (string) $row->id, 'type' => $row->type],
                    (int) $cycle->userid,
                    null,
                    (int) $cycle->id
                );
            }
        }
        $DB->update_record('local_cgt_notification', $update);
        return $ok;
    }

    /**
     * Render placeholders.
     *
     * @param \stdClass $cycle
     * @param \stdClass $user
     * @param string $text
     * @return string
     */
    public static function render(\stdClass $cycle, \stdClass $user, string $text): string {
        $format = fn(string $date) => userdate(
            cycle_manager::start_of_day($date) + 12 * HOURSECS,
            get_string('strftimedatefullshort', 'langconfig'),
            config::timezone()->getName()
        );
        $certurl = '';
        if ($cmid = config::certificate_cmid()) {
            $certurl = (new \moodle_url('/mod/customcert/view.php', ['id' => $cmid, 'downloadown' => 1]))->out(false);
        }
        $replacements = [
            '{firstname}' => $user->firstname,
            '{lastname}' => $user->lastname,
            '{opendate}' => $format(cycle_manager::open_date($cycle->anniversarydate)),
            '{duedate}' => $format($cycle->anniversarydate),
            '{accessenddate}' => $format(cycle_manager::access_end_date($cycle->anniversarydate)),
            '{courseurl}' => (new \moodle_url('/course/view.php', ['id' => $cycle->courseid]))->out(false),
            '{cycleid}' => $cycle->cycleid,
            '{certificatecode}' => (string) $cycle->certificatecode,
            '{certificateurl}' => $certurl,
        ];
        return strtr($text, $replacements);
    }

    /**
     * Send the learner message and HR copies.
     *
     * @param \stdClass $cycle
     * @param string $type
     * @return bool
     */
    private static function deliver(\stdClass $cycle, string $type): bool {
        $user = \core_user::get_user($cycle->userid, '*', MUST_EXIST);
        if (!empty($user->deleted) || !empty($user->suspended)) {
            return false;
        }
        $subject = self::render($cycle, $user, config::template($type, 'subject'));
        $body = self::render($cycle, $user, config::template($type, 'body'));

        $message = new \core\message\message();
        $message->component = config::COMPONENT;
        $message->name = $type;
        $message->userfrom = \core_user::get_noreply_user();
        $message->userto = $user;
        $message->subject = $subject;
        $message->fullmessage = $body;
        $message->fullmessageformat = FORMAT_PLAIN;
        $message->fullmessagehtml = text_to_html(s($body), false, false, true);
        $message->smallmessage = $subject;
        $message->notification = 1;
        $message->contexturl = (new \moodle_url('/course/view.php', ['id' => $cycle->courseid]))->out(false);
        $message->contexturlname = get_string('banner_title', config::COMPONENT);
        $message->courseid = $cycle->courseid;
        if (!message_send($message)) {
            return false;
        }

        foreach (config::hr_cc_emails() as $email) {
            $recipient = \core_user::get_noreply_user();
            $recipient->email = $email;
            $recipient->firstname = 'HR';
            $recipient->lastname = '';
            $recipient->mailformat = 1;
            email_to_user(
                $recipient,
                \core_user::get_noreply_user(),
                '[Copy] ' . $subject . ' - ' . fullname($user),
                $body
            );
        }
        return true;
    }
}
