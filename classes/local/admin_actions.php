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
 * Administrator actions. Archiving hides a cycle from default views; nothing is deleted.
 *
 * @package    local_caregivertraining
 * @copyright  2026 CI Institute of Nursing
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class admin_actions {
    /**
     * Archive a final cycle.
     *
     * @param int $id local cycle id
     */
    public static function archive_cycle(int $id): void {
        global $DB;
        require_capability('local/caregivertraining:managecycles', \context_system::instance());
        $cycle = $DB->get_record('local_cgt_cycle', ['id' => $id], '*', MUST_EXIST);
        if (!in_array($cycle->status, ['completed', 'superseded'], true)) {
            throw new \moodle_exception(
                'error:cycleconflict',
                config::COMPONENT,
                '',
                'only completed or superseded cycles can be archived'
            );
        }
        $DB->update_record('local_cgt_cycle', (object) ['id' => $id, 'archived' => 1, 'timemodified' => time()]);
        \local_caregivertraining\event\cycle_archived::create(['context' => \context_system::instance(),
            'relateduserid' => (int) $cycle->userid, 'objectid' => $id,
            'other' => ['cycleid' => $cycle->cycleid]])->trigger();
    }

    /**
     * Retry a blocked reset after review.
     *
     * @param int $id local cycle id
     * @return \stdClass
     */
    public static function retry_cycle(int $id): \stdClass {
        require_capability('local/caregivertraining:managecycles', \context_system::instance());
        return cycle_manager::retry_blocked($id);
    }

    /**
     * Mark an exception resolved.
     *
     * @param int $id
     */
    public static function resolve_exception(int $id): void {
        global $DB;
        require_capability('local/caregivertraining:managecycles', \context_system::instance());
        $exception = $DB->get_record('local_cgt_exception', ['id' => $id], '*', MUST_EXIST);
        $DB->update_record('local_cgt_exception', (object) ['id' => $id, 'status' => 'resolved', 'timemodified' => time()]);
        \local_caregivertraining\event\exception_resolved::create(['context' => \context_system::instance(),
            'objectid' => $id, 'relateduserid' => $exception->userid ?: null,
            'other' => ['type' => $exception->type]])->trigger();
    }
}
