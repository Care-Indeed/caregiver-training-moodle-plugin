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

/**
 * Link an existing Moodle user to a test AlayaCare ID and give them a cycle, without the adapter.
 *
 * @package    local_caregivertraining
 * @copyright  2026 CI Institute of Nursing
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('CLI_SCRIPT', true);

require(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/clilib.php');

use local_caregivertraining\local\binding_manager;
use local_caregivertraining\local\config;
use local_caregivertraining\local\cycle_manager;

[$options, $unrecognised] = cli_get_params([
    'help' => false,
    'userid' => 0,
    'alayacareid' => '',
    'cycleid' => '',
    'anniversarydate' => '',
], ['h' => 'help']);

if ($unrecognised) {
    cli_error(get_string('cliunknowoption', 'admin', implode("\n  ", $unrecognised)));
}

$help = <<<EOT
Give an existing learner a test training cycle in the configured annual course.

WARNING: if the learner already has progress in the course, opening the cycle snapshots that
progress as evidence and then resets it, exactly as a real annual cycle would.

Options:
  --userid=ID          Moodle user id of the learner (required)
  --alayacareid=ID     AlayaCare id to link, if the user is not linked yet (default TEST-<userid>)
  --cycleid=ID         Cycle id (default TEST-<userid>-<anniversarydate>)
  --anniversarydate=Y-m-d
                       Anniversary (due) date (default 30 days after today). The window opens
                       60 days before it and access ends 14 days after it.
  -h, --help           Print this help

Example:
  php public/local/caregivertraining/cli/create_test_cycle.php --userid=42

EOT;

if ($options['help'] || empty($options['userid'])) {
    echo $help;
    exit(empty($options['help']) ? 1 : 0);
}

\core\session\manager::set_user(get_admin());

$userid = (int) $options['userid'];
$today = new DateTimeImmutable('now', config::timezone());
$anniversarydate = $options['anniversarydate'] ?: $today->modify('+30 days')->format('Y-m-d');

try {
    $binding = binding_manager::get_by_userid($userid);
    if ($binding) {
        $alayacareid = $binding->alayacareid;
        cli_writeln("User {$userid} is already linked to AlayaCare id {$alayacareid}.");
    } else {
        $alayacareid = $options['alayacareid'] ?: "TEST-{$userid}";
        binding_manager::provision(['alayacareid' => $alayacareid, 'userid' => $userid]);
        cli_writeln("Linked user {$userid} to AlayaCare id {$alayacareid}.");
    }

    $result = cycle_manager::upsert([
        'cycleid' => $options['cycleid'] ?: "TEST-{$userid}-{$anniversarydate}",
        'userid' => $userid,
        'alayacareid' => $alayacareid,
        'anniversarydate' => $anniversarydate,
    ]);
} catch (\moodle_exception $e) {
    cli_error($e->getMessage() . (empty($e->debuginfo) ? '' : " ({$e->debuginfo})"));
}

$cycle = $result['cycle'];
cli_writeln("Cycle {$cycle['cycleid']} {$result['action']}: status {$cycle['status']}, "
    . "opens " . cycle_manager::open_date($cycle['anniversarydate']) . ", due {$cycle['anniversarydate']}, "
    . "access ends " . cycle_manager::access_end_date($cycle['anniversarydate']) . ".");
