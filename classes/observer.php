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

namespace local_caregivertraining;

use local_caregivertraining\local\config;
use local_caregivertraining\local\exceptions;
use local_caregivertraining\local\profile_fields;

/**
 * Event observers. Heavy work is deferred to an adhoc task so core flows are never blocked;
 * the scheduled reconciliation catches anything missed.
 *
 * @package    local_caregivertraining
 * @copyright  2026 CI Institute of Nursing
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class observer {
    /**
     * Queue completion evaluation for the learner's open cycle.
     *
     * @param \core\event\course_completed $event
     */
    public static function course_completed(\core\event\course_completed $event): void {
        global $DB;
        if ((int) $event->courseid !== config::course_id()) {
            return;
        }
        $cycle = $DB->get_record('local_cgt_cycle', ['userid' => $event->relateduserid, 'courseid' => $event->courseid,
            'status' => 'open'], 'id', IGNORE_MULTIPLE);
        if (!$cycle) {
            return;
        }
        $task = new task\evaluate_cycle();
        $task->set_custom_data(['cycleid' => (int) $cycle->id]);
        \core\task\manager::queue_adhoc_task($task, true);
    }

    /**
     * Flag profile edits that diverge from the binding (e.g. an admin editing a protected field).
     *
     * @param \core\event\user_updated $event
     */
    public static function user_updated(\core\event\user_updated $event): void {
        global $DB;
        $binding = $DB->get_record('local_cgt_binding', ['userid' => $event->relateduserid]);
        if (!$binding) {
            return;
        }
        $values = profile_fields::load((int) $binding->userid);
        $diverged = [];
        if ((string) $values[profile_fields::ALAYACAREID] !== (string) $binding->alayacareid) {
            $diverged[] = 'alayacareid';
        }
        if ((string) $values[profile_fields::PAYROLLNUMBER] !== (string) $binding->payrollnumber) {
            $diverged[] = 'payrollnumber';
        }
        if ($diverged) {
            exceptions::raise('profile_binding_divergence', ['key' => implode(',', $diverged), 'fields' => $diverged,
                'editedby' => (int) $event->userid], (int) $binding->userid, $binding->alayacareid);
        }
    }
}
