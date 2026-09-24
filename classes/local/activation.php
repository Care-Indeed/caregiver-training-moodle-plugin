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
 * Account activation using Moodle's time-limited, single-use password-reset token.
 *
 * @package    local_caregivertraining
 * @copyright  2026 CI Institute of Nursing
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class activation {
    /**
     * Queue an activation email unless the learner has already logged in.
     *
     * @param int $userid
     * @return string queued|alreadyactive
     */
    public static function queue(int $userid): string {
        global $DB;
        $user = $DB->get_record('user', ['id' => $userid], 'id, lastlogin, firstaccess', MUST_EXIST);
        if (!empty($user->lastlogin) || !empty($user->firstaccess)) {
            return 'alreadyactive';
        }
        $task = new \local_caregivertraining\task\send_activation();
        $task->set_custom_data(['userid' => $userid]);
        $task->set_userid(get_admin()->id);
        \core\task\manager::queue_adhoc_task($task, true);
        return 'queued';
    }

    /**
     * Generate a reset token through the core login API and email the link.
     *
     * @param int $userid
     * @return bool sent
     */
    public static function send(int $userid): bool {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/login/lib.php');

        $user = $DB->get_record('user', ['id' => $userid, 'deleted' => 0, 'suspended' => 0]);
        if (!$user || !empty($user->lastlogin)) {
            return false;
        }
        $reset = core_login_generate_password_reset($user);
        $site = get_site();
        $expiry = format_time(isset($CFG->pwresettime) ? (int) $CFG->pwresettime : 1800);
        $a = (object) [
            'firstname' => $user->firstname,
            'sitename' => format_string($site->fullname),
            'username' => $user->username,
            'link' => $CFG->wwwroot . '/login/forgot_password.php?token=' . $reset->token,
            'loginurl' => $CFG->wwwroot . '/login/index.php',
            'expiry' => $expiry,
        ];
        $subject = get_string('activation_subject', config::COMPONENT, format_string($site->fullname));
        $body = get_string('activation_body', config::COMPONENT, $a);
        return (bool) email_to_user($user, \core_user::get_noreply_user(), $subject, $body);
    }
}
