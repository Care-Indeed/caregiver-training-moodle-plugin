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
use core_external\external_single_structure;
use core_external\external_value;
use local_caregivertraining\local\cycle_manager;
use local_caregivertraining\local\idempotency;

/**
 * v1: create, update or start a cycle. The request names both the Moodle user and the canonical
 * employee binding and carries adapter-calculated dates.
 *
 * @package    local_caregivertraining
 * @copyright  2026 CI Institute of Nursing
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class v1_upsert_cycle extends adapter_api {
    /**
     * Parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'idempotencykey' => self::idempotency_param(),
            'cycleid' => new external_value(PARAM_RAW, 'Unique Cycle ID (1-100 of [A-Za-z0-9._:-])'),
            'userid' => new external_value(PARAM_INT, 'Moodle user id'),
            'alayacareid' => new external_value(PARAM_RAW, 'Canonical AlayaCare employee id bound to userid'),
            'hiredate' => new external_value(PARAM_RAW, 'Hire date used by the adapter, YYYY-MM-DD', VALUE_DEFAULT, ''),
            'anniversarydate' => new external_value(PARAM_RAW, 'AlayaCare anniversary date this cycle is due on, YYYY-MM-DD'),
            'supersedescycleid' => new external_value(PARAM_RAW, 'Active cycle this one replaces (rehire)', VALUE_DEFAULT, ''),
        ]);
    }

    /**
     * Execute.
     *
     * @param string $idempotencykey
     * @param string $cycleid
     * @param int $userid
     * @param string $alayacareid
     * @param string $hiredate
     * @param string $anniversarydate
     * @param string $supersedescycleid
     * @return array
     */
    public static function execute(
        string $idempotencykey,
        string $cycleid,
        int $userid,
        string $alayacareid,
        string $hiredate,
        string $anniversarydate,
        string $supersedescycleid = ''
    ): array {
        $params = self::validate_parameters(self::execute_parameters(), compact(
            'idempotencykey',
            'cycleid',
            'userid',
            'alayacareid',
            'hiredate',
            'anniversarydate',
            'supersedescycleid'
        ));
        self::require_adapter();
        $key = $params['idempotencykey'];
        unset($params['idempotencykey']);
        return idempotency::run('v1_upsert_cycle', $key, $params, fn() => cycle_manager::upsert($params));
    }

    /**
     * Returns.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'action' => new external_value(PARAM_ALPHA, 'created, updated or unchanged'),
            'cycle' => self::cycle_structure(),
            'replayed' => new external_value(PARAM_BOOL, 'True when this response was replayed for a repeated key'),
        ]);
    }
}
