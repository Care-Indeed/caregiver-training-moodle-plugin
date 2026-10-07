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
 * REST entry point for the adapter, e.g. GET /local/caregivertraining/api.php/v1/cycles/C-1 with
 * "Authorization: Bearer <token>". Servers without PATH_INFO can pass the path as ?route=/v1/cycles/C-1.
 *
 * @package    local_caregivertraining
 * @copyright  2026 CI Institute of Nursing
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('NO_DEBUG_DISPLAY', true);
define('WS_SERVER', true);
define('NO_MOODLE_COOKIES', true);

require(__DIR__ . '/../../config.php');

use local_caregivertraining\local\rest_api;

$query = [];
parse_str((string) ($_SERVER['QUERY_STRING'] ?? ''), $query);
$path = (string) ($_SERVER['PATH_INFO'] ?? '');
if ($path === '' && isset($query['route']) && is_string($query['route'])) {
    $path = $query['route'];
}
unset($query['route']);

$headers = array_change_key_case(function_exists('getallheaders') ? (getallheaders() ?: []) : [], CASE_LOWER);
$authorization = (string) ($headers['authorization'] ?? $_SERVER['HTTP_AUTHORIZATION'] ?? '');

try {
    $response = rest_api::authenticate($authorization) ?? rest_api::handle(
        (string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'),
        $path,
        $query,
        (string) file_get_contents('php://input'),
        $headers
    );
} catch (\Throwable $e) {
    // The early web service exception handler cannot render PHP errors, which would leave an empty 500.
    $response = rest_api::error_from_exception($e);
}
rest_api::send($response);
