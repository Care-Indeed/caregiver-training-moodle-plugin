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
            // Only the latest reminder whose moment has arrived before the due date, and only if that moment came after
            // the training opened and the cycle was created, so a cycle created close to its due date doesn't get
            // several reminders at once, or one straight after "Now Open".
            $latest = null;
            foreach (config::reminder_offsets() as $days) {
                $at = (new \DateTimeImmutable('@' . $duestart))->setTimezone(config::timezone())
                    ->modify("-{$days} days")->getTimestamp();
                if ($now >= $at && $now < $duestart && (!$latest || $at > $latest['at'])) {
                    $latest = ['days' => $days, 'at' => $at];
                }
            }
            if ($latest && $latest['at'] >= max((int) $cycle->timeopen, (int) $cycle->timecreated)) {
                $queued += (int) self::queue($cycle, 'reminder', 'd' . $latest['days']);
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
     * @param array|null $published public certificate URLs from {@see certificate::publish()}
     * @return string
     */
    public static function render(\stdClass $cycle, \stdClass $user, string $text, ?array $published = null): string {
        $replacements = [
            '{firstname}' => $user->firstname,
            '{lastname}' => $user->lastname,
            '{opendate}' => self::format_date(cycle_manager::open_date($cycle->anniversarydate)),
            '{duedate}' => self::format_date($cycle->anniversarydate),
            '{accessenddate}' => self::format_date(cycle_manager::access_end_date($cycle->anniversarydate)),
            '{courseurl}' => self::course_url($cycle),
            '{cycleid}' => $cycle->cycleid,
            '{certificatecode}' => (string) $cycle->certificatecode,
            '{certificateurl}' => self::certificate_link($published),
            '{loginurl}' => (new \moodle_url('/login/index.php'))->out(false),
            '{lostpasswordurl}' => (new \moodle_url('/login/forgot_password.php'))->out(false),
            '{quickstartguideurl}' => config::quick_start_guide_url(),
        ];
        return strtr($text, $replacements);
    }

    /**
     * Format a Y-m-d compliance date for an email, e.g. "November 12, 2026".
     *
     * @param string $date
     * @return string
     */
    private static function format_date(string $date): string {
        return email::date(cycle_manager::start_of_day($date) + 12 * HOURSECS);
    }

    /**
     * Annual course URL.
     *
     * @param \stdClass $cycle
     * @return string
     */
    private static function course_url(\stdClass $cycle): string {
        return (new \moodle_url('/course/view.php', ['id' => $cycle->courseid]))->out(false);
    }

    /**
     * The certificate link in emails: the public PDF when published, otherwise the certificate activity.
     *
     * @param array|null $published public certificate URLs from {@see certificate::publish()}
     * @return string
     */
    private static function certificate_link(?array $published): string {
        return $published['pdf'] ?? self::certificate_url();
    }

    /**
     * Certificate activity URL, or an empty string when no certificate is configured.
     *
     * @return string
     */
    private static function certificate_url(): string {
        $cmid = config::certificate_cmid();
        // The customcert module only serves downloads to a POST with a sesskey, so an emailed link must open the activity page.
        return $cmid ? (new \moodle_url('/mod/customcert/view.php', ['id' => $cmid]))->out(false) : '';
    }

    /**
     * Build the branded email for a notification type.
     *
     * @param \stdClass $cycle
     * @param string $type
     * @param string $subject rendered subject
     * @param string $body rendered body
     * @param array|null $published public certificate URLs
     * @return array{html: string, text: string}
     */
    private static function compose(
        \stdClass $cycle,
        string $type,
        string $subject,
        string $body,
        ?array $published
    ): array {
        $component = config::COMPONENT;
        $url = self::course_url($cycle);
        if ($type === 'completion') {
            $url = self::certificate_link($published) ?: $url;
        }
        $image = null;
        if ($type === 'completion' && !empty($published['png'])) {
            $image = ['url' => $published['png'], 'alt' => get_string('email_certificate_imagealt', $component)];
        }
        return email::render([
            'subject' => $subject,
            'preheader' => get_string("email_preheader_{$type}", $component, self::format_date($cycle->anniversarydate)),
            'label' => get_string("email_label_{$type}", $component),
            'tone' => match ($type) {
                'due', 'overdue' => 'alert',
                'completion' => 'success',
                default => 'info',
            },
            'body' => $body,
            'image' => $image,
            'cta' => ['url' => $url, 'label' => get_string("email_cta_{$type}", $component)],
        ]);
    }

    /**
     * Send the learner message, with HR in BCC when HR copies are set.
     *
     * Without HR copies it goes through the message API (email plus the Moodle notification). With them it is one
     * email to the learner blind-copied to HR, since the message API can't add BCC recipients.
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
        $certificate = null;
        $published = null;
        if ($type === 'completion') {
            try {
                $published = certificate::publish($cycle);
            } catch (\Throwable $e) {
                exceptions::raise(
                    'certificate_publish_failed',
                    ['key' => (string) $cycle->id, 'error' => $e->getMessage()],
                    (int) $cycle->userid,
                    null,
                    (int) $cycle->id
                );
            }
            // The email says the certificate PDF is attached; the image is only a fallback when no PDF exists.
            $certificate = certificate::pdf_file($cycle) ?? certificate::png_file($cycle);
        }
        $subject = self::render($cycle, $user, config::template($type, 'subject'), $published);
        $body = self::render($cycle, $user, config::template($type, 'body'), $published);
        $email = self::compose($cycle, $type, $subject, $body, $published);
        $attachname = $certificate ? 'certificate-' . $certificate->get_filename() : '';

        $hremails = config::hr_cc_emails();
        if ($hremails) {
            return mailer::send($user, $subject, $email['text'], $email['html'], $hremails, $certificate, $attachname);
        }

        $message = new \core\message\message();
        $message->component = config::COMPONENT;
        $message->name = $type;
        $message->userfrom = \core_user::get_noreply_user();
        $message->userto = $user;
        $message->subject = $subject;
        $message->fullmessage = $email['text'];
        $message->fullmessageformat = FORMAT_PLAIN;
        $message->fullmessagehtml = $email['html'];
        $message->smallmessage = $subject;
        $message->notification = 1;
        $message->contexturl = self::course_url($cycle);
        $message->contexturlname = get_string('banner_title', config::COMPONENT);
        $message->courseid = $cycle->courseid;
        if ($certificate) {
            $message->attachment = $certificate;
            $message->attachname = $attachname;
        }
        return (bool) message_send($message);
    }
}
