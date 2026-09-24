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

// NOTE: no MOODLE_INTERNAL test here, this file may be required by behat before including /config.php.

require_once(__DIR__ . '/../../../../lib/behat/behat_base.php');

use local_caregivertraining\local\binding_manager;
use local_caregivertraining\local\config;
use local_caregivertraining\local\cycle_manager;

/**
 * Behat steps for caregiver annual training.
 *
 * @package    local_caregivertraining
 * @copyright  2026 CI Institute of Nursing
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class behat_local_caregivertraining extends behat_base {
    /**
     * Create the synthetic annual course and configure the plugin to use it.
     *
     * @Given /^the synthetic caregiver annual course exists$/
     */
    public function the_synthetic_caregiver_annual_course_exists(): void {
        $generator = testing_util::get_data_generator()->get_plugin_generator('local_caregivertraining');
        $generator->create_annual_course();
    }

    /**
     * Provision a learner and give them a cycle.
     *
     * @Given /^caregiver "(?P<username>[^"]*)" has cycle "(?P<cycleid>[^"]*)" opening (?P<open>-?\d+) days from today and due (?P<due>-?\d+) days from today$/
     * @param string $username
     * @param string $cycleid
     * @param int $open
     * @param int $due
     */
    public function caregiver_has_cycle(string $username, string $cycleid, int $open, int $due): void {
        global $DB;
        $user = $DB->get_record('user', ['username' => $username]);
        $alayacareid = 'BEHAT-' . $username;
        if (!$user) {
            $result = binding_manager::provision(['alayacareid' => $alayacareid, 'email' => "{$username}@example.com",
                'firstname' => 'Synthetic', 'lastname' => $username, 'createifmissing' => 1]);
            $DB->set_field('user', 'username', $username, ['id' => $result['userid']]);
            $user = $DB->get_record('user', ['id' => $result['userid']], '*', MUST_EXIST);
            update_internal_user_password($user, $username);
        }
        $day = fn(int $d) => (new DateTimeImmutable('now', config::timezone()))->modify("{$d} days")->format('Y-m-d');
        cycle_manager::upsert(['cycleid' => $cycleid, 'userid' => $user->id, 'alayacareid' => $alayacareid,
            'opendate' => $day($open), 'duedate' => $day($due)]);
    }
}
