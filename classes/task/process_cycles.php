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

use local_caregivertraining\local\completion_manager;
use local_caregivertraining\local\cycle_manager;

/**
 * Starts due cycles (snapshot + per-learner reset) and reconciles completion for open cycles.
 *
 * @package    local_caregivertraining
 * @copyright  2026 CI Institute of Nursing
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class process_cycles extends \core\task\scheduled_task {
    /**
     * Task name.
     *
     * @return string
     */
    public function get_name() {
        return get_string('task_process_cycles', 'local_caregivertraining');
    }

    /**
     * Run the task.
     */
    public function execute() {
        $started = cycle_manager::start_due_cycles();
        mtrace("Started {$started} due cycle(s).");
        $completed = completion_manager::reconcile_all();
        mtrace("Reconciled completion; {$completed} cycle(s) newly completed.");
    }
}
