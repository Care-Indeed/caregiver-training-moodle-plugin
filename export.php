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
 * CSV export of cycles and snapshots.
 *
 * @package    local_caregivertraining
 * @copyright  2026 CI Institute of Nursing
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');

use local_caregivertraining\local\export;

require_login();
$context = context_system::instance();
require_capability('local/caregivertraining:export', $context);
require_sesskey();

$filter = optional_param('filter', '', PARAM_RAW_TRIMMED);
$rows = export::rows($filter);

\local_caregivertraining\event\evidence_exported::create([
    'context' => $context,
    'other' => ['filter' => $filter, 'rows' => count($rows)],
])->trigger();

\core\dataformat::download_data('caregiver-training-' . date('Ymd-His'), 'csv', export::columns(), $rows);
