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

namespace local_caregivertraining\local;

/**
 * Idempotency keys for mutating adapter calls: a replay returns the stored response.
 *
 * @package    local_caregivertraining
 * @copyright  2026 CI Institute of Nursing
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class idempotency {
    /**
     * Run $operation once per (function, key). Replays with the same request return the stored
     * response; replays with a different request are rejected.
     *
     * @param string $functionname
     * @param string $key
     * @param array $request request parameters (excluding the key)
     * @param callable $operation returns an array response
     * @return array
     */
    public static function run(string $functionname, string $key, array $request, callable $operation): array {
        global $DB;

        $key = trim($key);
        if ($key === '' || strlen($key) > 128 || !preg_match('/^[A-Za-z0-9._:\-]+$/', $key)) {
            throw new \invalid_parameter_exception('idempotencykey must be 1-128 characters of [A-Za-z0-9._:-]');
        }
        ksort($request);
        $hash = hash('sha256', json_encode($request));

        $lock = locks::acquire('idem:' . $functionname . ':' . $key);
        try {
            $existing = $DB->get_record('local_cgt_request', ['functionname' => $functionname, 'idemkey' => $key]);
            if ($existing) {
                if ($existing->requesthash !== $hash) {
                    throw new \moodle_exception('error:idempotencyconflict', config::COMPONENT);
                }
                $response = json_decode((string) $existing->response, true);
                if (is_array($response)) {
                    $response['replayed'] = true;
                    return $response;
                }
            }

            $response = $operation();
            $response['replayed'] = false;

            $DB->insert_record('local_cgt_request', (object) [
                'functionname' => $functionname,
                'idemkey' => $key,
                'requesthash' => $hash,
                'response' => json_encode($response),
                'timecreated' => time(),
            ]);
            return $response;
        } finally {
            $lock->release();
        }
    }
}
