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

use core_external\external_api;
use local_caregivertraining\external\v1_get_binding;
use local_caregivertraining\external\v1_health;
use local_caregivertraining\external\v1_provision_learner;
use local_caregivertraining\external\v1_reconcile;
use local_caregivertraining\external\v1_update_access;
use local_caregivertraining\external\v1_upsert_cycle;

/**
 * Resource-style REST API over the v1 adapter functions. Each route validates input with the
 * external function's own parameter description and calls the same code, so both interfaces
 * share one contract.
 *
 * @package    local_caregivertraining
 * @copyright  2026 CI Institute of Nursing
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class rest_api {
    /** @var string External service whose tokens may call the API. */
    const SERVICE = 'local_caregivertraining_adapter';

    /** @var string Entry point, relative to wwwroot. */
    const BASE = '/local/caregivertraining/api.php';

    /** @var array<string,int> HTTP status per error code (plugin codes without the "error:" prefix). */
    const STATUS = [
        'invalidparameter' => 400,
        'invalidemail' => 400,
        'invaliddate' => 400,
        'invalidtoken' => 401,
        'nopermissions' => 403,
        'accessexception' => 403,
        'usernotfound' => 404,
        'bindingmismatch' => 404,
        'cyclenotfound' => 404,
        'existingaccount' => 409,
        'ambiguousemail' => 409,
        'usernametaken' => 409,
        'bindingconflict' => 409,
        'cycleconflict' => 409,
        'cycleimmutable' => 409,
        'alreadycompleted' => 409,
        'idempotencyconflict' => 409,
        'locktimeout' => 503,
        'notconfigured' => 503,
        'sitemaintenance' => 503,
    ];

    /**
     * Routes as [method, path pattern, handler]. Named groups become path parameters.
     *
     * @return array[]
     */
    private static function routes(): array {
        return [
            ['GET', '#^/v1/health$#', 'get_health'],
            ['GET', '#^/v1/learners$#', 'search_learners'],
            ['GET', '#^/v1/learners/(?<alayacareid>[^/]+)$#', 'get_learner'],
            ['PUT', '#^/v1/learners/(?<alayacareid>[^/]+)$#', 'put_learner'],
            ['GET', '#^/v1/cycles$#', 'list_cycles'],
            ['GET', '#^/v1/cycles/(?<cycleid>[^/]+)$#', 'get_cycle'],
            ['PUT', '#^/v1/cycles/(?<cycleid>[^/]+)$#', 'put_cycle'],
            ['PUT', '#^/v1/cycles/(?<cycleid>[^/]+)/access$#', 'put_access'],
            ['POST', '#^/v1/cycles/(?<cycleid>[^/]+)/repair$#', 'repair_cycle'],
        ];
    }

    /**
     * Check the bearer token. Sets the token's user as the current user on success.
     *
     * @param string $authorization Authorization header value
     * @return array|null error response, or null when authenticated
     */
    public static function authenticate(string $authorization): ?array {
        global $CFG;
        require_once($CFG->dirroot . '/webservice/lib.php');
        if (!preg_match('/^Bearer\s+(\S+)$/i', trim($authorization), $m)) {
            return self::error(
                401,
                'unauthorized',
                'Send the web service token as "Authorization: Bearer <token>".',
                null,
                ['WWW-Authenticate' => 'Bearer']
            );
        }
        try {
            $auth = (new \webservice())->authenticate_user($m[1]);
        } catch (\Throwable $e) {
            $response = self::error_from_exception($e);
            if ($response['status'] === 401) {
                $response['headers']['WWW-Authenticate'] = 'Bearer error="invalid_token"';
            }
            return $response;
        }
        if ($auth['service']->shortname !== self::SERVICE) {
            return self::error(403, 'wrongservice', 'This token belongs to a different web service. Create a token for the '
                . 'Caregiver training adapter service.');
        }
        return null;
    }

    /**
     * Route and execute a request as the current user.
     *
     * @param string $method HTTP method
     * @param string $path path after the entry point, e.g. /v1/cycles/C-1
     * @param array $query query string parameters
     * @param string $rawbody request body
     * @param array $headers request headers with lowercase names
     * @return array{status: int, body: array, headers: array<string,string>}
     */
    public static function handle(string $method, string $path, array $query, string $rawbody, array $headers): array {
        $method = strtoupper($method);
        $path = '/' . trim($path, '/');
        try {
            $allowed = [];
            foreach (self::routes() as [$routemethod, $pattern, $handler]) {
                if (!preg_match($pattern, $path, $matches)) {
                    continue;
                }
                if ($routemethod !== $method) {
                    $allowed[] = $routemethod;
                    continue;
                }
                $params = array_map('rawurldecode', array_filter($matches, 'is_string', ARRAY_FILTER_USE_KEY));
                $body = in_array($method, ['PUT', 'POST'], true) ? self::parse_body($rawbody) : [];
                return self::$handler($params, $query, $body, trim((string) ($headers['idempotency-key'] ?? '')));
            }
            if ($allowed) {
                return self::error(
                    405,
                    'methodnotallowed',
                    "{$method} is not supported on {$path}.",
                    null,
                    ['Allow' => implode(', ', array_unique($allowed))]
                );
            }
            return self::error(404, 'routenotfound', "No endpoint matches {$method} {$path}.");
        } catch (\Throwable $e) {
            return self::error_from_exception($e);
        }
    }

    /**
     * GET /v1/health
     *
     * @param array $params
     * @param array $query
     * @return array
     */
    private static function get_health(array $params, array $query): array {
        self::only($query, []);
        return self::ok(self::call(v1_health::class, []));
    }

    /**
     * GET /v1/learners?alayacareid=&payrollnumber=&email=&userid=
     *
     * @param array $params
     * @param array $query
     * @return array
     */
    private static function search_learners(array $params, array $query): array {
        self::only($query, ['alayacareid', 'payrollnumber', 'email', 'userid']);
        $result = self::call(v1_get_binding::class, $query);
        return self::ok(['status' => $result['status'], 'count' => count($result['bindings']), 'items' => $result['bindings'],
            'candidates' => $result['candidates']]);
    }

    /**
     * GET /v1/learners/{alayacareid}
     *
     * @param array $params
     * @param array $query
     * @return array
     */
    private static function get_learner(array $params, array $query): array {
        self::only($query, []);
        $result = self::call(v1_get_binding::class, ['alayacareid' => $params['alayacareid']]);
        if (!$result['bindings']) {
            return self::learner_not_found($params['alayacareid']);
        }
        $learner = $result['bindings'][0];
        unset($learner['matchedby']);
        return self::ok($learner);
    }

    /**
     * PUT /v1/learners/{alayacareid}: provision (create, link or update).
     *
     * @param array $params
     * @param array $query
     * @param array $body
     * @param string $key
     * @return array
     */
    private static function put_learner(array $params, array $query, array $body, string $key): array {
        self::only($query, []);
        self::only($body, ['alayacareid', 'userid', 'email', 'firstname', 'lastname', 'payrollnumber', 'hcanumber',
            'registrationdate', 'createifmissing', 'sendactivation']);
        self::same_as_path($body, 'alayacareid', $params['alayacareid']);
        $args = ['idempotencykey' => self::require_key($key), 'alayacareid' => $params['alayacareid']] + $body;
        $result = self::call(v1_provision_learner::class, $args);
        if ($result['status'] === 'notfound') {
            return self::learner_not_found($params['alayacareid'], 'Send "createifmissing": true with email, firstname and '
                . 'lastname to create the learner, or "userid" to link an existing account.');
        }
        if ($result['status'] === 'created') {
            return self::created($result, '/v1/learners/' . rawurlencode($params['alayacareid']));
        }
        return self::ok($result);
    }

    /**
     * GET /v1/cycles?cycleids=&modifiedsince=&limit=
     *
     * @param array $params
     * @param array $query
     * @return array
     */
    private static function list_cycles(array $params, array $query): array {
        self::only($query, ['cycleids', 'modifiedsince', 'limit']);
        if (isset($query['cycleids']) && !is_array($query['cycleids'])) {
            $query['cycleids'] = preg_split('/\s*,\s*/', trim((string) $query['cycleids']), -1, PREG_SPLIT_NO_EMPTY);
        }
        $result = self::call(v1_reconcile::class, $query);
        return self::ok(['count' => count($result['cycles']), 'items' => $result['cycles'],
            'servertime' => $result['servertime']]);
    }

    /**
     * GET /v1/cycles/{cycleid}
     *
     * @param array $params
     * @param array $query
     * @return array
     */
    private static function get_cycle(array $params, array $query): array {
        self::only($query, []);
        return self::one_cycle($params['cycleid'], false);
    }

    /**
     * PUT /v1/cycles/{cycleid}: create, update or start.
     *
     * @param array $params
     * @param array $query
     * @param array $body
     * @param string $key
     * @return array
     */
    private static function put_cycle(array $params, array $query, array $body, string $key): array {
        self::only($query, []);
        self::only($body, ['cycleid', 'userid', 'alayacareid', 'hiredate', 'anniversarydate', 'supersedescycleid']);
        self::same_as_path($body, 'cycleid', $params['cycleid']);
        $args = ['idempotencykey' => self::require_key($key), 'cycleid' => $params['cycleid']] + $body;
        $result = self::call(v1_upsert_cycle::class, $args);
        if ($result['action'] === 'created') {
            return self::created($result, '/v1/cycles/' . rawurlencode($params['cycleid']));
        }
        return self::ok($result);
    }

    /**
     * PUT /v1/cycles/{cycleid}/access
     *
     * @param array $params
     * @param array $query
     * @param array $body
     * @param string $key
     * @return array
     */
    private static function put_access(array $params, array $query, array $body, string $key): array {
        self::only($query, []);
        self::only($body, ['userid', 'alayacareid', 'employmentstatus', 'enrolmentstatus', 'remindersenabled']);
        if (array_key_exists('remindersenabled', $body)) {
            $reminders = $body['remindersenabled'];
            $body['remindersenabled'] = $reminders === null ? -1 : (is_bool($reminders) ? (int) $reminders : $reminders);
        }
        $args = ['idempotencykey' => self::require_key($key), 'cycleid' => $params['cycleid']] + $body;
        return self::ok(self::call(v1_update_access::class, $args));
    }

    /**
     * POST /v1/cycles/{cycleid}/repair
     *
     * @param array $params
     * @param array $query
     * @param array $body
     * @return array
     */
    private static function repair_cycle(array $params, array $query, array $body): array {
        self::only($query, []);
        self::only($body, []);
        return self::one_cycle($params['cycleid'], true);
    }

    /**
     * One cycle through reconcile, or 404.
     *
     * @param string $cycleid
     * @param bool $repair
     * @return array
     */
    private static function one_cycle(string $cycleid, bool $repair): array {
        $result = self::call(v1_reconcile::class, ['cycleids' => [$cycleid], 'limit' => 1, 'repair' => $repair]);
        if (!$result['cycles']) {
            return self::error(404, 'cyclenotfound', "No cycle has Cycle ID {$cycleid}.");
        }
        return self::ok($result['cycles'][0]);
    }

    /**
     * Validate, execute and clean through an external function.
     *
     * @param string $class external function class
     * @param array $args
     * @return array
     */
    private static function call(string $class, array $args): array {
        $args = external_api::validate_parameters($class::execute_parameters(), $args);
        return external_api::clean_returnvalue($class::execute_returns(), $class::execute(...$args));
    }

    /**
     * Decode a JSON object body.
     *
     * @param string $rawbody
     * @return array
     */
    private static function parse_body(string $rawbody): array {
        if (trim($rawbody) === '') {
            return [];
        }
        try {
            $body = json_decode($rawbody, true, 32, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new \invalid_parameter_exception('The request body is not valid JSON: ' . $e->getMessage());
        }
        if (!is_array($body) || array_is_list($body) && $body !== []) {
            throw new \invalid_parameter_exception('The request body must be a JSON object.');
        }
        return $body;
    }

    /**
     * Reject fields a route does not accept.
     *
     * @param array $input
     * @param string[] $allowed
     */
    private static function only(array $input, array $allowed): void {
        $unexpected = array_diff(array_keys($input), $allowed);
        if ($unexpected) {
            throw new \invalid_parameter_exception('Unexpected field(s): ' . implode(', ', $unexpected) . '. Allowed: '
                . ($allowed ? implode(', ', $allowed) : 'none') . '.');
        }
    }

    /**
     * A body field that repeats a path parameter must agree with it.
     *
     * @param array $body
     * @param string $field
     * @param string $value
     */
    private static function same_as_path(array $body, string $field, string $value): void {
        if (array_key_exists($field, $body) && (string) $body[$field] !== $value) {
            throw new \invalid_parameter_exception("{$field} in the body does not match the URL.");
        }
    }

    /**
     * The Idempotency-Key header, required on writes.
     *
     * @param string $key
     * @return string
     */
    private static function require_key(string $key): string {
        if ($key === '') {
            throw new \invalid_parameter_exception('Send a unique Idempotency-Key header (1-128 of [A-Za-z0-9._:-]) with '
                . 'every PUT request.');
        }
        return $key;
    }

    /**
     * 404 for an unprovisioned employee.
     *
     * @param string $alayacareid
     * @param string $hint
     * @return array
     */
    private static function learner_not_found(string $alayacareid, string $hint = ''): array {
        return self::error(
            404,
            'learnernotfound',
            "No Moodle learner is bound to AlayaCare id {$alayacareid}.",
            $hint !== '' ? $hint : null
        );
    }

    /**
     * Map an exception to an error response. Unexpected errors are logged and not described.
     *
     * @param \Throwable $e
     * @return array
     */
    public static function error_from_exception(\Throwable $e): array {
        if (!$e instanceof \moodle_exception) {
            debugging('local_caregivertraining REST API: ' . get_class($e) . ': ' . $e->getMessage(), DEBUG_NORMAL);
            return self::error(500, 'internalerror', 'Internal server error.');
        }
        $code = preg_replace('/^error:/', '', $e->errorcode);
        $status = self::STATUS[$code] ?? 500;
        if ($status === 500) {
            debugging('local_caregivertraining REST API: ' . get_class($e) . ': ' . $e->getMessage(), DEBUG_NORMAL);
            return self::error(500, $code, 'Internal server error.');
        }
        $details = null;
        if ($e instanceof \invalid_parameter_exception || $code === 'accessexception' || $e->module === config::COMPONENT) {
            $details = $e->debuginfo ?: null;
        }
        $headers = $status === 503 ? ['Retry-After' => '5'] : [];
        return self::error($status, $code, $e->getMessage(), $details, $headers);
    }

    /**
     * 200 response.
     *
     * @param array $body
     * @return array
     */
    private static function ok(array $body): array {
        return ['status' => 200, 'body' => $body, 'headers' => []];
    }

    /**
     * 201 response with the new resource's location.
     *
     * @param array $body
     * @param string $path
     * @return array
     */
    private static function created(array $body, string $path): array {
        return ['status' => 201, 'body' => $body,
            'headers' => ['Location' => (new \moodle_url(self::BASE . $path))->out(false)]];
    }

    /**
     * Error response: {code, error, message, details?}.
     *
     * @param int $status
     * @param string $error
     * @param string $message
     * @param string|null $details
     * @param array $headers
     * @return array
     */
    private static function error(
        int $status,
        string $error,
        string $message,
        ?string $details = null,
        array $headers = []
    ): array {
        $body = ['code' => $status, 'error' => $error, 'message' => $message];
        if ($details !== null && $details !== '') {
            $body['details'] = $details;
        }
        return ['status' => $status, 'body' => $body, 'headers' => $headers];
    }

    /**
     * Write a response.
     *
     * @param array $response
     */
    public static function send(array $response): void {
        http_response_code($response['status']);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        foreach ($response['headers'] as $name => $value) {
            header("{$name}: {$value}");
        }
        echo json_encode($response['body'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    }
}
