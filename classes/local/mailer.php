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
 * Sends a learner email with HR in BCC, which email_to_user() can't do.
 *
 * Follows email_to_user() for everything a notice from the no-reply user needs: the site's no-reply sender, bounce
 * handling, diversion, core's subject, sender name and body templates, extra headers, DKIM and the failure event.
 *
 * @package    local_caregivertraining
 * @copyright  2026 CI Institute of Nursing
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class mailer {
    /**
     * Send one email to the learner, blind-copied to the given addresses.
     *
     * @param \stdClass $user learner
     * @param string $subject
     * @param string $text plain-text body
     * @param string $html HTML body
     * @param string[] $bcc blind-copy addresses
     * @param \stored_file|null $attachment
     * @param string $attachname
     * @return bool sent (or deliberately skipped, as email_to_user() reports)
     */
    public static function send(
        \stdClass $user,
        string $subject,
        string $text,
        string $html,
        array $bcc,
        ?\stored_file $attachment = null,
        string $attachname = ''
    ): bool {
        global $CFG, $PAGE, $SITE;
        require_once($CFG->libdir . '/filelib.php');

        if (defined('BEHAT_SITE_RUNNING') && !defined('TEST_EMAILCATCHER_MAIL_SERVER') &&
                !defined('TEST_EMAILCATCHER_API_SERVER')) {
            return true;
        }
        if (!empty($CFG->noemailever)) {
            return true;
        }
        if (email_should_be_diverted($user->email)) {
            $subject = "[DIVERTED {$user->email}] $subject";
            $user = clone($user);
            $user->email = $CFG->divertallemailsto;
        }
        // Diverted copies already reach the diversion mailbox with the learner's email.
        $bcc = array_values(array_filter($bcc, fn($address) => !email_should_be_diverted($address)));
        if (!validate_email($user->email) || over_bounce_threshold($user)) {
            return false;
        }

        $mail = get_mailer();
        $noreplyaddress = empty($CFG->noreplyaddress) || !validate_email($CFG->noreplyaddress)
            ? 'noreply@' . get_host_from_url($CFG->wwwroot) : $CFG->noreplyaddress;
        if (!empty($CFG->handlebounces)) {
            $mail->Sender = generate_email_processing_address(0, 'B' . base64_encode(pack('V', $user->id)) .
                substr(md5($user->email), 0, 16));
        } else {
            $mail->Sender = $noreplyaddress;
        }
        $mail->WordWrap = 79;
        if (!empty($CFG->emailheaders)) {
            foreach (array_filter(array_map('trim', explode("\n", $CFG->emailheaders))) as $header) {
                $mail->addCustomHeader($header);
            }
        }

        $mail->From = $noreplyaddress;
        $from = \core_user::get_noreply_user();
        $fromdetails = (object) [
            'name' => fullname($from),
            'url' => preg_replace('#^https?://#', '', $CFG->wwwroot),
            'siteshortname' => format_string($SITE->shortname),
        ];
        $mail->FromName = $CFG->emailfromvia != EMAIL_VIA_NEVER ? get_string('emailvia', 'core', $fromdetails)
            : $fromdetails->name;
        $replyto = [$noreplyaddress, get_string('noreplyname')];

        $renderer = $PAGE->get_renderer('core');
        $context = [
            'sitefullname' => $SITE->fullname,
            'siteshortname' => $SITE->shortname,
            'sitewwwroot' => $CFG->wwwroot,
            'subject' => $subject,
            'prefix' => $CFG->emailsubjectprefix,
            'to' => $user->email,
            'toname' => fullname($user),
            'from' => $mail->From,
            'fromname' => $mail->FromName,
            'replyto' => $replyto[0],
            'replytoname' => $replyto[1],
            'touserid' => $user->id,
            'tousername' => $user->username,
        ];
        $context['body'] = $html;
        $html = $renderer->render_from_template('core/email_html', $context);
        $context['body'] = html_to_text(nl2br($text));
        $mail->Subject = $renderer->render_from_template('core/email_subject', $context);
        $mail->FromName = $renderer->render_from_template('core/email_fromname', $context);
        $text = $renderer->render_from_template('core/email_text', $context);
        $mail->MessageID = generate_email_messageid();

        // HR reads the same email, so it's always HTML; the learner's own mail format preference is ignored.
        $mail->isHTML(true);
        $mail->Encoding = 'quoted-printable';
        $mail->Body = $html;
        $mail->AltBody = "\n$text\n";
        if ($attachment && $attachname && !empty($CFG->allowattachments)) {
            $mail->addStringAttachment($attachment->get_content(), $attachname, 'base64', mimeinfo('type', $attachname));
        }

        $mail->addAddress($user->email, fullname($user));
        foreach ($bcc as $address) {
            $mail->addBCC($address);
        }
        $mail->addReplyTo($replyto[0], $replyto[1]);

        if (!empty($CFG->emaildkimselector)) {
            $domain = substr(strrchr($mail->From, '@'), 1);
            $pempath = "{$CFG->dataroot}/dkim/{$domain}/{$CFG->emaildkimselector}.private";
            if (file_exists($pempath)) {
                $mail->DKIM_domain = $domain;
                $mail->DKIM_private = $pempath;
                $mail->DKIM_selector = $CFG->emaildkimselector;
                $mail->DKIM_identity = $mail->From;
            }
        }

        if ($mail->send()) {
            set_send_count($user);
            return true;
        }
        \core\event\email_failed::create([
            'context' => \context_system::instance(),
            'userid' => $from->id,
            'relateduserid' => $user->id,
            'other' => ['subject' => $subject, 'message' => $text, 'errorinfo' => $mail->ErrorInfo],
        ])->trigger();
        if (CLI_SCRIPT) {
            mtrace('Error: local_caregivertraining mailer: ' . $mail->ErrorInfo);
        }
        return false;
    }
}
