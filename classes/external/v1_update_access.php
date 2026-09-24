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
use local_caregivertraining\local\cycle_manager;
use local_caregivertraining\local\idempotency;

/**
 * v1: apply adapter-decided employment, enrolment and reminder state.
 *
 * @package    local_caregivertraining
 * @copyright  2026 CI Institute of Nursing
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class v1_update_access extends adapter_api {
    /**
     * Parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'idempotencykey' => self::idempotency_param(),
            'cycleid' => new external_value(PARAM_RAW, 'Cycle ID'),
            'userid' => new external_value(PARAM_INT, 'Moodle user id'),
            'alayacareid' => new external_value(PARAM_RAW, 'Canonical AlayaCare employee id'),
            'employmentstatus' => new external_value(PARAM_ALPHA, 'active, inactive, terminated or leave'),
            'enrolmentstatus' => new external_value(
                PARAM_ALPHA,
                'active, suspended or empty to leave unchanged',
                VALUE_DEFAULT,
                ''
            ),
            'remindersenabled' => new external_value(PARAM_INT, '1, 0, or -1 to leave unchanged', VALUE_DEFAULT, -1),
        ]);
    }

    /**
     * Execute.
     *
     * @param string $idempotencykey
     * @param string $cycleid
     * @param int $userid
     * @param string $alayacareid
     * @param string $employmentstatus
     * @param string $enrolmentstatus
     * @param int $remindersenabled
     * @return array
     */
    public static function execute(
        string $idempotencykey,
        string $cycleid,
        int $userid,
        string $alayacareid,
        string $employmentstatus,
        string $enrolmentstatus = '',
        int $remindersenabled = -1
    ): array {
        $params = self::validate_parameters(self::execute_parameters(), compact(
            'idempotencykey',
            'cycleid',
            'userid',
            'alayacareid',
            'employmentstatus',
            'enrolmentstatus',
            'remindersenabled'
        ));
        self::require_adapter();
        $key = $params['idempotencykey'];
        unset($params['idempotencykey']);
        return idempotency::run('v1_update_access', $key, $params, fn() => cycle_manager::update_access($params));
    }

    /**
     * Returns.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'cycle' => self::cycle_structure(),
            'policygates' => new external_multiple_structure(new external_value(
                PARAM_ALPHANUMEXT,
                'HR policy that is not approved and was therefore not applied'
            )),
            'replayed' => new external_value(PARAM_BOOL, 'True when this response was replayed for a repeated key'),
        ]);
    }
}
