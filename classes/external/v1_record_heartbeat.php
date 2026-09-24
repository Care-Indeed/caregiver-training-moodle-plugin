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
use core_external\external_function_parameters;
use core_external\external_single_structure;
use core_external\external_value;
use local_caregivertraining\local\config;
use local_caregivertraining\local\time_tracker;

/**
 * v1: learner heartbeat (AJAX, session + sesskey authenticated by core/ajax).
 *
 * @package    local_caregivertraining
 * @copyright  2026 CI Institute of Nursing
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class v1_record_heartbeat extends external_api {
    /**
     * Parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'cmid' => new external_value(PARAM_INT, 'Course module id of the page being viewed'),
            'sessiontoken' => new external_value(PARAM_ALPHANUM, 'Random per-page token (32-64 hex)'),
            'visible' => new external_value(PARAM_BOOL, 'Page visible'),
            'playing' => new external_value(PARAM_BOOL, 'Media playing'),
            'interactedago' => new external_value(PARAM_INT, 'Seconds since the last interaction'),
        ]);
    }

    /**
     * Execute.
     *
     * @param int $cmid
     * @param string $sessiontoken
     * @param bool $visible
     * @param bool $playing
     * @param int $interactedago
     * @return array
     */
    public static function execute(int $cmid, string $sessiontoken, bool $visible, bool $playing, int $interactedago): array {
        global $USER;
        $params = self::validate_parameters(self::execute_parameters(), compact(
            'cmid',
            'sessiontoken',
            'visible',
            'playing',
            'interactedago'
        ));
        $courseid = config::course_id();
        if (!$courseid) {
            throw new \moodle_exception('error:notconfigured', config::COMPONENT, '', 'courseid');
        }
        $context = \context_module::instance($params['cmid']);
        self::validate_context($context);
        if ((int) $context->get_course_context()->instanceid !== $courseid) {
            throw new \invalid_parameter_exception('Activity is not in the annual course');
        }
        require_capability('local/caregivertraining:recordtime', \context_course::instance($courseid));

        return time_tracker::heartbeat(
            (int) $USER->id,
            $params['cmid'],
            \core_text::strtolower($params['sessiontoken']),
            $params['visible'],
            $params['playing'],
            max(0, $params['interactedago'])
        );
    }

    /**
     * Returns.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'credited' => new external_value(PARAM_BOOL, 'Whether this beat earned credit'),
            'creditseconds' => new external_value(PARAM_INT, 'Seconds credited for this beat'),
            'reason' => new external_value(PARAM_ALPHANUMEXT, 'credited, session_started, hidden, idle, gap, concurrent, ...'),
            'policy' => new external_value(PARAM_ALPHA, 'Active time policy'),
            'requiredseconds' => new external_value(PARAM_INT, 'Required seconds'),
            'approvedseconds' => new external_value(PARAM_INT, 'Approved countable seconds'),
            'recordedseconds' => new external_value(PARAM_INT, 'All recorded seconds (not necessarily approved)'),
            'remainingseconds' => new external_value(PARAM_INT, 'Remaining approved seconds'),
            'intervalseconds' => new external_value(PARAM_INT, 'Heartbeat interval'),
        ]);
    }
}
