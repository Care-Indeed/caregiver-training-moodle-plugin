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
 * Review queue for identity and cycle exceptions. Repeated occurrences increment a counter.
 *
 * @package    local_caregivertraining
 * @copyright  2026 CI Institute of Nursing
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class exceptions {
    /**
     * Record or re-open an exception.
     *
     * @param string $type
     * @param array $details non-secret details
     * @param int|null $userid
     * @param string|null $alayacareid
     * @param int|null $cycleid local cycle id
     */
    public static function raise(
        string $type,
        array $details,
        ?int $userid = null,
        ?string $alayacareid = null,
        ?int $cycleid = null
    ): void {
        global $DB;
        $dedupe = hash('sha256', implode('|', [$type, $userid, $alayacareid, $cycleid, $details['key'] ?? '']));
        $now = time();
        $existing = $DB->get_record('local_cgt_exception', ['dedupekey' => $dedupe]);
        if ($existing) {
            $existing->status = 'open';
            $existing->occurrences++;
            $existing->details = json_encode($details);
            $existing->timemodified = $now;
            $DB->update_record('local_cgt_exception', $existing);
            return;
        }
        try {
            $DB->insert_record('local_cgt_exception', (object) [
                'type' => $type,
                'dedupekey' => $dedupe,
                'userid' => $userid,
                'alayacareid' => $alayacareid,
                'cycleid' => $cycleid,
                'details' => json_encode($details),
                'status' => 'open',
                'occurrences' => 1,
                'timecreated' => $now,
                'timemodified' => $now,
            ]);
        } catch (\dml_write_exception $e) {
            // A concurrent request recorded the same exception.
            return;
        }
    }
}
