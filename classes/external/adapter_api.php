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

use core_external\external_api;
use core_external\external_multiple_structure;
use core_external\external_single_structure;
use core_external\external_value;

/**
 * Shared checks and structures for adapter-facing functions.
 *
 * @package    local_caregivertraining
 * @copyright  2026 CI Institute of Nursing
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
abstract class adapter_api extends external_api {
    /**
     * Validate system context and the adapter capability.
     */
    protected static function require_adapter(): void {
        $context = \context_system::instance();
        self::validate_context($context);
        require_capability('local/caregivertraining:adapterapi', $context);
    }

    /**
     * Idempotency key parameter.
     *
     * @return external_value
     */
    protected static function idempotency_param(): external_value {
        return new external_value(PARAM_RAW, 'Unique key per logical request (1-128 of [A-Za-z0-9._:-]); replays return the '
            . 'stored response');
    }

    /**
     * Cycle structure.
     *
     * @return external_single_structure
     */
    public static function cycle_structure(): external_single_structure {
        return new external_single_structure([
            'cycleid' => new external_value(PARAM_RAW, 'Cycle ID'),
            'userid' => new external_value(PARAM_INT, 'Moodle user id'),
            'alayacareid' => new external_value(PARAM_RAW, 'Canonical AlayaCare employee id'),
            'status' => new external_value(PARAM_ALPHA, 'scheduled, open, blocked, completed or superseded'),
            'compliance' => new external_value(PARAM_ALPHA, 'upcoming, open, overdue, complete or notapplicable'),
            'hiredate' => new external_value(PARAM_RAW, 'YYYY-MM-DD or empty'),
            'opendate' => new external_value(PARAM_RAW, 'YYYY-MM-DD'),
            'duedate' => new external_value(PARAM_RAW, 'YYYY-MM-DD'),
            'timeopen' => new external_value(PARAM_INT, 'Open instant (unix)'),
            'timedue' => new external_value(PARAM_INT, 'Last second of the due date (unix)'),
            'employmentstatus' => new external_value(PARAM_ALPHA, 'Reported employment status'),
            'enrolmentstatus' => new external_value(PARAM_ALPHA, 'active or suspended'),
            'remindersenabled' => new external_value(PARAM_BOOL, 'Reminders enabled'),
            'resetstate' => new external_value(PARAM_ALPHA, 'pending, notrequired, done or blocked'),
            'blockedreason' => new external_value(PARAM_RAW, 'Reason when blocked'),
            'timecompleted' => new external_value(PARAM_INT, 'Completion time or 0'),
            'approvedseconds' => new external_value(PARAM_INT, 'Approved seconds at completion'),
            'timepolicy' => new external_value(PARAM_RAW, 'Time policy at completion'),
            'certificatecode' => new external_value(PARAM_RAW, 'Certificate code'),
            'events' => new external_multiple_structure(new external_single_structure([
                'eventtype' => new external_value(PARAM_RAW, 'Event type'),
                'eventid' => new external_value(PARAM_RAW, 'Stable event id'),
                'status' => new external_value(PARAM_ALPHA, 'pending, delivered or failed'),
                'attempts' => new external_value(PARAM_INT, 'Delivery attempts'),
            ])),
        ]);
    }

    /**
     * Binding structure.
     *
     * @return external_single_structure
     */
    public static function binding_structure(): external_single_structure {
        return new external_single_structure([
            'bindingid' => new external_value(PARAM_INT, 'Binding id'),
            'userid' => new external_value(PARAM_INT, 'Moodle user id'),
            'alayacareid' => new external_value(PARAM_RAW, 'Canonical AlayaCare employee id'),
            'externalid' => new external_value(PARAM_RAW, 'AlayaCare external id'),
            'payrollid' => new external_value(PARAM_RAW, 'Payroll number'),
            'status' => new external_value(PARAM_ALPHA, 'Binding status'),
            'matchedby' => new external_value(PARAM_RAW, 'Fields that matched', VALUE_OPTIONAL),
        ]);
    }
}
