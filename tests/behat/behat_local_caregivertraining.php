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
     * @Given /^caregiver "(?P<username>[^"]*)" has cycle "(?P<cycleid>[^"]*)" due (?P<due>-?\d+) days from today$/
     * @param string $username
     * @param string $cycleid
     * @param int $due anniversary as days from today
     */
    public function caregiver_has_cycle(string $username, string $cycleid, int $due): void {
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
            'anniversarydate' => $day($due)]);
    }

    /**
     * Mark annual course activities complete for a learner.
     *
     * @Given /^caregiver "(?P<username>[^"]*)" has completed (?P<which>every|the last) annual training activit(?:y|ies)$/
     * @param string $username
     * @param string $which every or the last
     */
    public function caregiver_has_completed_activities(string $username, string $which): void {
        global $CFG, $DB;
        require_once($CFG->libdir . '/completionlib.php');
        $userid = (int) $DB->get_field('user', 'id', ['username' => $username], MUST_EXIST);
        $course = get_course(config::course_id());
        $cms = array_values(get_fast_modinfo($course)->get_cms());
        $completion = new completion_info($course);
        foreach ($which === 'every' ? $cms : [end($cms)] as $cm) {
            $completion->update_state($cm, COMPLETION_COMPLETE, $userid);
        }
    }

    /**
     * Open the first or last activity of the annual course.
     *
     * @Given /^I am on the (?P<which>first|last) annual training activity$/
     * @param string $which first or last
     */
    public function i_am_on_an_annual_training_activity(string $which): void {
        $cms = array_values(get_fast_modinfo(config::course_id())->get_cms());
        $cm = $which === 'first' ? reset($cms) : end($cms);
        $this->execute('behat_general::i_visit', [$cm->url->out_as_local_url(false)]);
    }
}
