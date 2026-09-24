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

use local_caregivertraining\local\outbox;

/**
 * Delivers signed adapter events with retry and backoff.
 *
 * @package    local_caregivertraining
 * @copyright  2026 CI Institute of Nursing
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class deliver_outbox extends \core\task\scheduled_task {
    /**
     * Task name.
     *
     * @return string
     */
    public function get_name() {
        return get_string('task_deliver_outbox', 'local_caregivertraining');
    }

    /**
     * Run the task.
     */
    public function execute() {
        $result = outbox::deliver_pending();
        mtrace("Outbox: {$result['delivered']} delivered, {$result['retrying']} retrying, {$result['failed']} failed, "
            . ($result['enabled'] ? 'delivery enabled' : 'delivery disabled; events remain queued'));
    }
}
