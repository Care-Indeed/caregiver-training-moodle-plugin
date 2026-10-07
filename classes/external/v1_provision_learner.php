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
use local_caregivertraining\local\binding_manager;
use local_caregivertraining\local\idempotency;

/**
 * v1: idempotent learner provision/link/update.
 *
 * @package    local_caregivertraining
 * @copyright  2026 CI Institute of Nursing
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class v1_provision_learner extends adapter_api {
    /**
     * Parameters. Optional attributes left null are not changed; an empty string clears them.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'idempotencykey' => self::idempotency_param(),
            'alayacareid' => new external_value(PARAM_RAW, 'Canonical immutable AlayaCare employee id'),
            'userid' => new external_value(PARAM_INT, 'Existing Moodle user to link (explicit confirmation)', VALUE_DEFAULT, 0),
            'email' => new external_value(PARAM_RAW, 'Email', VALUE_DEFAULT, ''),
            'firstname' => new external_value(PARAM_NOTAGS, 'First name', VALUE_DEFAULT, ''),
            'lastname' => new external_value(PARAM_NOTAGS, 'Last name', VALUE_DEFAULT, ''),
            'payrollnumber' => new external_value(PARAM_RAW, 'Payroll number', VALUE_DEFAULT, null, NULL_ALLOWED),
            'hcanumber' => new external_value(PARAM_RAW, 'HCA number', VALUE_DEFAULT, null, NULL_ALLOWED),
            'registrationdate' => new external_value(
                PARAM_RAW,
                'Authoritative HCA registration date YYYY-MM-DD (adapter-derived)',
                VALUE_DEFAULT,
                null,
                NULL_ALLOWED
            ),
            'createifmissing' => new external_value(PARAM_BOOL, 'Create an account when no match exists', VALUE_DEFAULT, false),
            'sendactivation' => new external_value(
                PARAM_BOOL,
                'Queue the time-limited activation email for never-used accounts',
                VALUE_DEFAULT,
                false
            ),
        ]);
    }

    /**
     * Execute.
     *
     * @param string $idempotencykey
     * @param string $alayacareid
     * @param int $userid
     * @param string $email
     * @param string $firstname
     * @param string $lastname
     * @param string|null $payrollnumber
     * @param string|null $hcanumber
     * @param string|null $registrationdate
     * @param bool $createifmissing
     * @param bool $sendactivation
     * @return array
     */
    public static function execute(
        string $idempotencykey,
        string $alayacareid,
        int $userid = 0,
        string $email = '',
        string $firstname = '',
        string $lastname = '',
        ?string $payrollnumber = null,
        ?string $hcanumber = null,
        ?string $registrationdate = null,
        bool $createifmissing = false,
        bool $sendactivation = false
    ): array {
        $params = self::validate_parameters(self::execute_parameters(), compact(
            'idempotencykey',
            'alayacareid',
            'userid',
            'email',
            'firstname',
            'lastname',
            'payrollnumber',
            'hcanumber',
            'registrationdate',
            'createifmissing',
            'sendactivation'
        ));
        self::require_adapter();
        $key = $params['idempotencykey'];
        unset($params['idempotencykey']);
        return idempotency::run('v1_provision_learner', $key, $params, fn() => binding_manager::provision($params));
    }

    /**
     * Returns.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'status' => new external_value(PARAM_ALPHA, 'created, linked, updated, unchanged or notfound'),
            'userid' => new external_value(PARAM_INT, 'Moodle user id'),
            'bindingid' => new external_value(PARAM_INT, 'Binding id'),
            'activation' => new external_value(PARAM_ALPHA, 'queued, alreadyactive or notrequested'),
            'replayed' => new external_value(PARAM_BOOL, 'True when this response was replayed for a repeated key'),
        ]);
    }
}
