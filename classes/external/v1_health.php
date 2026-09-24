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
use local_caregivertraining\local\config;

/**
 * v1: configuration and queue health without personal data.
 *
 * @package    local_caregivertraining
 * @copyright  2026 CI Institute of Nursing
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class v1_health extends adapter_api {
    /**
     * Parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([]);
    }

    /**
     * Execute.
     *
     * @return array
     */
    public static function execute(): array {
        global $DB;
        self::require_adapter();

        $problems = [];
        $courseid = config::course_id();
        if (!$courseid || !$DB->record_exists('course', ['id' => $courseid])) {
            $problems[] = 'course_not_configured';
        }
        $cmid = config::certificate_cmid();
        $cm = $cmid ? get_coursemodule_from_id('customcert', $cmid) : false;
        if (!$cm || (int) $cm->course !== $courseid) {
            $problems[] = 'certificate_not_configured';
        }
        if (config::time_policy() === config::POLICY_UNRESOLVED) {
            $problems[] = 'time_policy_unresolved';
        }
        if (config::adapter_enabled() && (config::adapter_url() === '' || config::adapter_secret() === '')) {
            $problems[] = 'adapter_delivery_misconfigured';
        }

        $count = fn(string $table, string $select, array $params = []) => $DB->count_records_select($table, $select, $params);
        $lastrun = (int) $DB->get_field(
            'task_scheduled',
            'lastruntime',
            ['classname' => '\\local_caregivertraining\\task\\process_cycles']
        );
        if (!$lastrun || $lastrun < time() - HOURSECS) {
            $problems[] = 'process_cycles_stale';
        }

        return [
            'contract' => config::CONTRACT_VERSION,
            'pluginversion' => (int) get_config(config::COMPONENT, 'version'),
            'moodlerelease' => $GLOBALS['CFG']->release,
            'timepolicy' => config::time_policy(),
            'adapterdelivery' => config::adapter_enabled(),
            'cycles' => [
                'scheduled' => $count('local_cgt_cycle', "status = 'scheduled'"),
                'open' => $count('local_cgt_cycle', "status = 'open'"),
                'blocked' => $count('local_cgt_cycle', "status = 'blocked'"),
                'completed' => $count('local_cgt_cycle', "status = 'completed'"),
            ],
            'outbox' => [
                'pending' => $count('local_cgt_outbox', "status = 'pending'"),
                'failed' => $count('local_cgt_outbox', "status = 'failed'"),
            ],
            'openexceptions' => $count('local_cgt_exception', "status = 'open'"),
            'processcycleslastrun' => $lastrun,
            'problems' => $problems,
            'servertime' => time(),
        ];
    }

    /**
     * Returns.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'contract' => new external_value(PARAM_ALPHANUM, 'Contract version'),
            'pluginversion' => new external_value(PARAM_INT, 'Plugin version'),
            'moodlerelease' => new external_value(PARAM_RAW, 'Moodle release'),
            'timepolicy' => new external_value(PARAM_ALPHA, 'Active time policy'),
            'adapterdelivery' => new external_value(PARAM_BOOL, 'Outbound delivery enabled'),
            'cycles' => new external_single_structure([
                'scheduled' => new external_value(PARAM_INT, 'Scheduled'),
                'open' => new external_value(PARAM_INT, 'Open'),
                'blocked' => new external_value(PARAM_INT, 'Blocked'),
                'completed' => new external_value(PARAM_INT, 'Completed'),
            ]),
            'outbox' => new external_single_structure([
                'pending' => new external_value(PARAM_INT, 'Pending'),
                'failed' => new external_value(PARAM_INT, 'Failed'),
            ]),
            'openexceptions' => new external_value(PARAM_INT, 'Open exceptions'),
            'processcycleslastrun' => new external_value(PARAM_INT, 'Last run of process_cycles'),
            'problems' => new external_multiple_structure(new external_value(PARAM_ALPHANUMEXT, 'Problem code')),
            'servertime' => new external_value(PARAM_INT, 'Server time'),
        ]);
    }
}
