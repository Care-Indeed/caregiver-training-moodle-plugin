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

namespace local_caregivertraining\external;

use core_external\external_function_parameters;
use core_external\external_multiple_structure;
use core_external\external_single_structure;
use core_external\external_value;
use local_caregivertraining\local\completion_manager;
use local_caregivertraining\local\cycle_manager;
use local_caregivertraining\local\outbox;

/**
 * v1: authoritative cycle state for reconciliation. Optionally re-evaluates completion and
 * re-queues failed events (same event ids, so the adapter can de-duplicate).
 *
 * @package    local_caregivertraining
 * @copyright  2026 CI Institute of Nursing
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class v1_reconcile extends adapter_api {
    /**
     * Parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'cycleids' => new external_multiple_structure(
                new external_value(PARAM_RAW, 'Cycle ID'),
                'Cycle IDs',
                VALUE_DEFAULT,
                []
            ),
            'modifiedsince' => new external_value(PARAM_INT, 'Return cycles modified at or after this time', VALUE_DEFAULT, 0),
            'limit' => new external_value(PARAM_INT, 'Maximum cycles (1-500)', VALUE_DEFAULT, 100),
            'repair' => new external_value(PARAM_BOOL, 'Re-evaluate completion and re-queue failed events', VALUE_DEFAULT, false),
        ]);
    }

    /**
     * Execute.
     *
     * @param array $cycleids
     * @param int $modifiedsince
     * @param int $limit
     * @param bool $repair
     * @return array
     */
    public static function execute(array $cycleids = [], int $modifiedsince = 0, int $limit = 100, bool $repair = false): array {
        global $DB;
        $params = self::validate_parameters(self::execute_parameters(), compact('cycleids', 'modifiedsince', 'limit', 'repair'));
        self::require_adapter();
        $limit = min(500, max(1, $params['limit']));

        if ($params['cycleids']) {
            $ids = array_map([cycle_manager::class, 'normalise_cycleid'], $params['cycleids']);
            [$insql, $inparams] = $DB->get_in_or_equal($ids, SQL_PARAMS_NAMED);
            $cycles = $DB->get_records_select('local_cgt_cycle', "cycleid {$insql}", $inparams, 'id', '*', 0, $limit);
        } else {
            $cycles = $DB->get_records_select(
                'local_cgt_cycle',
                'timemodified >= :since',
                ['since' => $params['modifiedsince']],
                'timemodified ASC, id ASC',
                '*',
                0,
                $limit
            );
        }

        $result = [];
        foreach ($cycles as $cycle) {
            if ($params['repair']) {
                if ($cycle->status === 'open') {
                    completion_manager::evaluate((int) $cycle->id);
                } else if ($cycle->status === 'completed') {
                    completion_manager::record_side_effects($cycle);
                    outbox::requeue_failed((int) $cycle->id);
                }
                $cycle = $DB->get_record('local_cgt_cycle', ['id' => $cycle->id]);
            }
            $export = cycle_manager::export($cycle);
            $export['gates'] = $cycle->status === 'open' ? completion_manager::check_gates($cycle)['reasons'] : [];
            $result[] = $export;
        }
        return ['cycles' => $result, 'servertime' => time()];
    }

    /**
     * Returns.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        $cycle = self::cycle_structure();
        $cycle->keys['gates'] = new external_multiple_structure(new external_value(
            PARAM_ALPHANUMEXT,
            'Unmet completion gate'
        ));
        return new external_single_structure([
            'cycles' => new external_multiple_structure($cycle),
            'servertime' => new external_value(PARAM_INT, 'Server time; use as the next modifiedsince'),
        ]);
    }
}
