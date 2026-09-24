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

namespace local_caregivertraining\task;

use local_caregivertraining\local\activation;

/**
 * Sends one account activation email.
 *
 * @package    local_caregivertraining
 * @copyright  2026 CI Institute of Nursing
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class send_activation extends \core\task\adhoc_task {
    /**
     * Run the task.
     */
    public function execute() {
        $data = $this->get_custom_data();
        $sent = activation::send((int) $data->userid);
        mtrace($sent ? 'Activation email sent.' : 'Activation not sent (user active, suspended or deleted).');
    }
}
