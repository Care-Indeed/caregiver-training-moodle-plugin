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
use local_caregivertraining\local\binding_manager;

/**
 * v1: read-only binding lookup.
 *
 * @package    local_caregivertraining
 * @copyright  2026 CI Institute of Nursing
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class v1_get_binding extends adapter_api {
    /**
     * Parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'alayacareid' => new external_value(PARAM_RAW, 'Canonical AlayaCare employee id', VALUE_DEFAULT, ''),
            'payrollnumber' => new external_value(PARAM_RAW, 'Payroll number', VALUE_DEFAULT, ''),
            'email' => new external_value(PARAM_RAW, 'Email (candidate matching only)', VALUE_DEFAULT, ''),
            'userid' => new external_value(PARAM_INT, 'Moodle user id', VALUE_DEFAULT, 0),
        ]);
    }

    /**
     * Execute.
     *
     * @param string $alayacareid
     * @param string $payrollnumber
     * @param string $email
     * @param int $userid
     * @return array
     */
    public static function execute(
        string $alayacareid = '',
        string $payrollnumber = '',
        string $email = '',
        int $userid = 0
    ): array {
        $params = self::validate_parameters(self::execute_parameters(), compact(
            'alayacareid',
            'payrollnumber',
            'email',
            'userid'
        ));
        self::require_adapter();
        if (!array_filter($params)) {
            throw new \invalid_parameter_exception('Provide at least one lookup field');
        }
        return binding_manager::lookup($params);
    }

    /**
     * Returns.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'status' => new external_value(PARAM_ALPHA, 'bound, candidate, ambiguous, conflict or none'),
            'bindings' => new external_multiple_structure(self::binding_structure()),
            'candidates' => new external_multiple_structure(new external_single_structure([
                'userid' => new external_value(PARAM_INT, 'Unbound Moodle user id'),
                'username' => new external_value(PARAM_RAW, 'Username'),
                'suspended' => new external_value(PARAM_BOOL, 'Suspended'),
                'via' => new external_value(PARAM_ALPHA, 'Match source'),
            ])),
        ]);
    }
}
