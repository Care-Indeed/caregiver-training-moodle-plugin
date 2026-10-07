# Caregiver training endpoints (contract v1)

This page documents every endpoint of `local_caregivertraining`:

- The **REST API** the integration adapter calls: resource URLs, bearer tokens, JSON bodies and standard HTTP status
  codes. This is the recommended interface.
- The equivalent Moodle web service functions. They share the same fields and rules, and remain supported.
- How both respond to common provisioning and cycle scenarios.
- The signed `cycle.completed` event the plugin sends back to the adapter.
- The browser pages, file downloads and CLI script used by administrators and learners.

A machine-readable OpenAPI 3 description of the REST API is in [openapi.yaml](openapi.yaml).

The adapter owns AlayaCare synchronization, eligibility and date calculations. The plugin never calls AlayaCare, so
"not found" in this document always means "not found in Moodle".

## All endpoints at a glance

| Endpoint | Kind | Used by | Purpose |
| --- | --- | --- | --- |
| `GET /v1/health` | REST | Adapter, monitoring | Configuration and queue health. |
| `GET /v1/learners` | REST | Adapter | Search learners by AlayaCare id, payroll number, email or user id. |
| `GET /v1/learners/{alayacareid}` | REST | Adapter | Get one learner. |
| `PUT /v1/learners/{alayacareid}` | REST | Adapter | Create, link or update a learner. |
| `GET /v1/cycles` | REST | Adapter | List cycles by Cycle ID or by modification time. |
| `GET /v1/cycles/{cycleid}` | REST | Adapter | Get one cycle with its unmet completion requirements. |
| `PUT /v1/cycles/{cycleid}` | REST | Adapter | Create, update or start an annual cycle. |
| `PUT /v1/cycles/{cycleid}/access` | REST | Adapter | Apply employment, enrolment and reminder state. |
| `POST /v1/cycles/{cycleid}/repair` | REST | Adapter | Re-evaluate completion and re-queue failed events. |
| `local_caregivertraining_v1_get_binding` | Web service (read) | Adapter | Look up a learner without changing anything. |
| `local_caregivertraining_v1_provision_learner` | Web service (write) | Adapter | Create, link or update a learner. |
| `local_caregivertraining_v1_upsert_cycle` | Web service (write) | Adapter | Create, update or start an annual cycle. |
| `local_caregivertraining_v1_update_access` | Web service (write) | Adapter | Apply employment, enrolment and reminder state. |
| `local_caregivertraining_v1_reconcile` | Web service (write) | Adapter | Read authoritative cycle state and optionally repair it. |
| `local_caregivertraining_v1_health` | Web service (read) | Adapter, monitoring | Configuration and queue health. |
| `local_caregivertraining_v1_record_heartbeat` | AJAX web service | Learner's browser | Report page activity for time tracking. |
| `POST {adapter URL}` (`cycle.completed`) | Outbound webhook | Plugin to adapter | Signed completion event. |
| `/local/caregivertraining/index.php` | Browser page | Administrators, HR | Exceptions, blocked resets, cycles and snapshots, with retry, archive and resolve actions. |
| `/local/caregivertraining/export.php` | Browser download | Administrators, HR | CSV evidence export. |
| `/local/caregivertraining/next.php` | Browser redirect | Learners | Go to the next available activity. |
| `/pluginfile.php/{contextid}/local_caregivertraining/snapshotcert/…` | File download | Administrators, HR | Certificate PDFs preserved in snapshots. |
| `cli/create_test_cycle.php` | CLI script | Developers, testers | Give a learner a test cycle without the adapter. |

REST paths are relative to `{wwwroot}/local/caregivertraining/api.php`.

## Contents

- [Setup](#setup)
- [REST API](#rest-api)
  - [Base URL and authentication](#base-url-and-authentication)
  - [Requests and responses](#requests-and-responses)
  - [REST errors](#rest-errors)
  - [REST idempotency](#rest-idempotency)
  - [Routes](#routes)
- [Moodle web service functions](#moodle-web-service-functions)
  - [Calling the functions](#calling-the-functions)
  - [Web service errors](#web-service-errors)
  - [Web service idempotency](#web-service-idempotency)
- [Function reference](#function-reference)
  - [`local_caregivertraining_v1_get_binding`](#local_caregivertraining_v1_get_binding)
  - [`local_caregivertraining_v1_provision_learner`](#local_caregivertraining_v1_provision_learner)
  - [`local_caregivertraining_v1_upsert_cycle`](#local_caregivertraining_v1_upsert_cycle)
  - [`local_caregivertraining_v1_update_access`](#local_caregivertraining_v1_update_access)
  - [`local_caregivertraining_v1_reconcile`](#local_caregivertraining_v1_reconcile)
  - [`local_caregivertraining_v1_health`](#local_caregivertraining_v1_health)
  - [`local_caregivertraining_v1_record_heartbeat` (browser only)](#local_caregivertraining_v1_record_heartbeat-browser-only)
- [Scenario reference](#scenario-reference)
- [Outbound event: `cycle.completed`](#outbound-event-cyclecompleted)
- [Browser pages](#browser-pages)
  - [`index.php`: administrator view](#indexphp-administrator-view)
  - [`export.php`: CSV evidence export](#exportphp-csv-evidence-export)
  - [`next.php`: next activity](#nextphp-next-activity)
  - [Snapshot certificate files](#snapshot-certificate-files)
- [CLI: `create_test_cycle.php`](#cli-create_test_cyclephp)
- [Capabilities](#capabilities)
- [Shared structures](#shared-structures)

## Setup

Both the REST API and the web service functions use tokens for the **Caregiver training adapter** external service
(`local_caregivertraining_adapter`). The service is disabled by default and restricted to authorised users.

1. Enable web services (Site administration > Advanced features). To also use the web service functions, enable the
   REST protocol.
2. Enable the `local_caregivertraining_adapter` service and add the adapter's service account as an authorised user.
3. Give that account a system-level role with `local/caregivertraining:adapterapi`. No default role has this
   capability.
4. Create a token for the account on that service. The same token works for both interfaces.

## REST API

### Base URL and authentication

```text
{wwwroot}/local/caregivertraining/api.php/v1/...
```

For example `https://moodle.example.com/local/caregivertraining/api.php/v1/cycles/C-2026-5005`. If the web server
doesn't pass the path after `api.php` to PHP, send it as a `route` parameter instead:
`.../api.php?route=/v1/cycles/C-2026-5005`.

Send the token in the `Authorization` header on every request. Tokens in the URL are not accepted.

```http
Authorization: Bearer {token}
```

The token must belong to the adapter service. A token for any other service is rejected with `403 wrongservice`.

```sh
curl -s "$MOODLE/local/caregivertraining/api.php/v1/health" \
  -H "Authorization: Bearer $TOKEN"
```

### Requests and responses

- `PUT` and `POST` bodies are JSON objects (`Content-Type: application/json`). Booleans are JSON `true`/`false`.
- `GET` filters are query string parameters. Lists are comma-separated, for example `cycleids=C-1,C-2`.
- Field names match the [function reference](#function-reference), so one set of field definitions covers both
  interfaces. Identifiers in the URL path (`alayacareid`, `cycleid`) may also appear in the body, but must match.
- Unknown fields are rejected with `400 invalidparameter`, and `details` lists the allowed fields.
- Responses are JSON with `Cache-Control: no-store`.

| Status | Meaning |
| --- | --- |
| `200 OK` | Success. |
| `201 Created` | A learner or cycle was created. The `Location` header points to it. |
| `400 Bad Request` | Missing, malformed or unexpected input. |
| `401 Unauthorized` | Missing, unknown or expired token. |
| `403 Forbidden` | The token is valid but not allowed: wrong service, user not authorised on the service, or missing capability. |
| `404 Not Found` | The learner, cycle or route doesn't exist. |
| `405 Method Not Allowed` | The path exists but not for this method. The `Allow` header lists the valid methods. |
| `409 Conflict` | The request clashes with existing data, or reuses an idempotency key with different content. |
| `500 Internal Server Error` | Unexpected failure. Details are logged on the server, not returned. |
| `503 Service Unavailable` | Plugin not configured, site in maintenance, or a learner lock timed out. Retry after the `Retry-After` seconds. |

### REST errors

Errors use one shape, based on [AlayaCare's error responses](https://developer.alayacare.com/reference/get_accounts):

```json
{
  "code": 409,
  "error": "cycleconflict",
  "message": "Cycle conflict: learner has active cycle C-2026-5005; send supersedescycleid to replace it",
  "details": "Optional extra detail"
}
```

| Field | Description |
| --- | --- |
| `code` | HTTP status code, repeated in the body. |
| `error` | Stable machine-readable code. Branch on this. |
| `message` | Human-readable explanation. |
| `details` | Present when there is more to say, for example which parameter failed validation and why. |

| Status | `error` | Meaning |
| --- | --- | --- |
| 400 | `invalidparameter` | A parameter is missing, has the wrong type, fails a format check or isn't allowed on this route. Also used for a missing `Idempotency-Key` header and invalid JSON. `details` explains which. |
| 400 | `invalidemail` | The email address is not valid. |
| 400 | `invaliddate` | A date is not a real `YYYY-MM-DD` calendar date. |
| 401 | `unauthorized` | No `Authorization: Bearer` header. |
| 401 | `invalidtoken` | The token doesn't exist. |
| 403 | `accessexception` | Token expired, web services disabled, service disabled, IP not allowed, or the user isn't authorised on the service. `details` says which. |
| 403 | `wrongservice` | The token belongs to a different web service. |
| 403 | `nopermissions` | The token's user lacks `local/caregivertraining:adapterapi`. |
| 404 | `routenotfound` | No endpoint matches the method and path. |
| 404 | `learnernotfound` | No learner is bound to this AlayaCare id. |
| 404 | `bindingmismatch` | The `userid` and `alayacareid` don't match an existing binding. |
| 404 | `usernotfound` | The Moodle user doesn't exist, is deleted, or can't be linked (guest or site admin). |
| 404 | `cyclenotfound` | No cycle has this Cycle ID, or it belongs to another learner. |
| 405 | `methodnotallowed` | Wrong method for this path. |
| 409 | `existingaccount` | An unbound Moodle account already uses this email. |
| 409 | `ambiguousemail` | More than one Moodle account uses this email. |
| 409 | `usernametaken` | The username derived from the email belongs to another account. |
| 409 | `bindingconflict` | The request would bind an employee, user, payroll number or email to two different people. |
| 409 | `cycleconflict` | The cycle clashes with another cycle (see `message`). |
| 409 | `cycleimmutable` | The cycle is completed or superseded and its dates can't change. |
| 409 | `alreadycompleted` | A new Cycle ID has the same anniversary date as one of the learner's completed cycles. |
| 409 | `idempotencyconflict` | The idempotency key was already used with a different request. |
| 500 | `internalerror` (or another code) | Unexpected failure. |
| 503 | `notconfigured`, `sitemaintenance`, `locktimeout` | Temporarily unable to process. Safe to retry. |

Every identity or cycle conflict is also recorded as an exception for HR or Engineering review on the plugin's
[administrator view](#indexphp-administrator-view).

### REST idempotency

Every `PUT` requires an `Idempotency-Key` header: 1-128 characters of `[A-Za-z0-9._:-]`, unique per logical request.

```http
Idempotency-Key: prov-5005-20261002-1
```

- Repeating a key with an identical request returns the stored response, with `"replayed": true` in the body. Nothing
  runs again.
- Repeating a key with a different request fails with `409 idempotencyconflict`.
- Errors are not stored. A request that failed can be retried with the same key once the cause is fixed.

`POST /v1/cycles/{cycleid}/repair` is safe to repeat and doesn't need a key.

### Routes

| Method and path | Success | Calls | Notes |
| --- | --- | --- | --- |
| `GET /v1/health` | 200 | [`v1_health`](#local_caregivertraining_v1_health) | No parameters. |
| `GET /v1/learners` | 200 | [`v1_get_binding`](#local_caregivertraining_v1_get_binding) | At least one filter. |
| `GET /v1/learners/{alayacareid}` | 200, 404 | [`v1_get_binding`](#local_caregivertraining_v1_get_binding) | Returns one [binding](#binding). |
| `PUT /v1/learners/{alayacareid}` | 201, 200, 404 | [`v1_provision_learner`](#local_caregivertraining_v1_provision_learner) | Requires `Idempotency-Key`. |
| `GET /v1/cycles` | 200 | [`v1_reconcile`](#local_caregivertraining_v1_reconcile) | Read only, no repair. |
| `GET /v1/cycles/{cycleid}` | 200, 404 | [`v1_reconcile`](#local_caregivertraining_v1_reconcile) | Returns one [cycle](#cycle) with `gates`. |
| `PUT /v1/cycles/{cycleid}` | 201, 200 | [`v1_upsert_cycle`](#local_caregivertraining_v1_upsert_cycle) | Requires `Idempotency-Key`. |
| `PUT /v1/cycles/{cycleid}/access` | 200 | [`v1_update_access`](#local_caregivertraining_v1_update_access) | Requires `Idempotency-Key`. |
| `POST /v1/cycles/{cycleid}/repair` | 200, 404 | [`v1_reconcile`](#local_caregivertraining_v1_reconcile) with `repair` | Empty body. |

The linked function sections describe each field and the full set of rules. The sections below cover what is
specific to REST.

#### `GET /v1/health`

Returns the [health fields](#local_caregivertraining_v1_health) unchanged. Use it for monitoring: `problems` is empty
when the plugin is fully configured.

#### `GET /v1/learners`

Query parameters: `alayacareid`, `payrollnumber`, `email`, `userid`. At least one is required.

```sh
curl -s "$MOODLE/local/caregivertraining/api.php/v1/learners?payrollnumber=PR-5005" \
  -H "Authorization: Bearer $TOKEN"
```

```json
{
  "status": "bound",
  "count": 1,
  "items": [{
    "bindingid": 12, "userid": 345, "alayacareid": "5005", "payrollnumber": "PR-5005",
    "hcanumber": "", "registrationdate": "", "status": "active",
    "matchedby": "payrollnumber"
  }],
  "candidates": []
}
```

`status` is `bound`, `candidate`, `ambiguous`, `conflict` or `none`, as described for
[`v1_get_binding`](#local_caregivertraining_v1_get_binding). A search with no matches is still `200` with
`"status": "none"`.

#### `GET /v1/learners/{alayacareid}`

Returns the learner's [binding](#binding) (without `matchedby`), or `404 learnernotfound`.

#### `PUT /v1/learners/{alayacareid}`

Creates, links or updates the learner bound to `{alayacareid}`. Body fields: `userid`, `email`, `firstname`,
`lastname`, `payrollnumber`, `hcanumber`, `registrationdate`, `createifmissing`, `sendactivation`.

```sh
curl -s -X PUT "$MOODLE/local/caregivertraining/api.php/v1/learners/5005" \
  -H "Authorization: Bearer $TOKEN" \
  -H "Idempotency-Key: prov-5005-1" \
  -H "Content-Type: application/json" \
  -d '{"email": "riley@example.com", "firstname": "Riley", "lastname": "Synthetic",
       "payrollnumber": "PR-5005", "createifmissing": true, "sendactivation": true}'
```

```http
HTTP/1.1 201 Created
Location: https://moodle.example.com/local/caregivertraining/api.php/v1/learners/5005
```

```json
{"status": "created", "userid": 345, "bindingid": 12, "activation": "queued", "replayed": false}
```

| Outcome | Status | Body |
| --- | --- | --- |
| Account created | `201` | `"status": "created"` |
| Existing account linked through `userid` | `200` | `"status": "linked"` |
| Already provisioned | `200` | `"status": "updated"` or `"unchanged"` |
| Nothing matches and `createifmissing` is `false` or omitted | `404` | `learnernotfound`, with a hint in `details` |
| Duplicate or conflicting identity | `409` | See [scenario 2](#2-provision-a-duplicate-student). |

#### `GET /v1/cycles`

Query parameters: `cycleids` (comma-separated), `modifiedsince` (Unix time), `limit` (1-500, default 100).

```sh
curl -s "$MOODLE/local/caregivertraining/api.php/v1/cycles?modifiedsince=1790000000&limit=200" \
  -H "Authorization: Bearer $TOKEN"
```

```json
{"count": 1, "items": [{"cycleid": "C-2026-5005", "status": "open", "gates": ["course_not_complete"], "...": "..."}],
 "servertime": 1790003600}
```

`items` are [cycles](#cycle) with `gates`. To poll for changes, send the previous response's `servertime` as the next
`modifiedsince`.

#### `GET /v1/cycles/{cycleid}`

Returns one [cycle](#cycle) with `gates`, or `404 cyclenotfound`.

#### `PUT /v1/cycles/{cycleid}`

Creates, updates or starts the cycle. Body fields: `userid`, `alayacareid`, `hiredate`, `anniversarydate`,
`supersedescycleid`. The adapter sends only the anniversary date. The plugin works out the open date and the end
of access from it (see [the window rules](#local_caregivertraining_v1_upsert_cycle)).

```sh
curl -s -X PUT "$MOODLE/local/caregivertraining/api.php/v1/cycles/C-2026-5005" \
  -H "Authorization: Bearer $TOKEN" \
  -H "Idempotency-Key: cyc-C-2026-5005-1" \
  -H "Content-Type: application/json" \
  -d '{"userid": 345, "alayacareid": "5005", "hiredate": "2021-03-15", "anniversarydate": "2027-03-15"}'
```

Returns `201` with `"action": "created"`, or `200` with `"action": "updated"` or `"unchanged"`. The body also holds
the resulting `cycle`. Errors are covered in scenarios [4](#4-cycle-already-started-or-in-progress),
[5](#5-cycle-when-the-student-is-not-found) and [6](#6-cycle-when-the-student-already-finished-the-annual-training).

#### `PUT /v1/cycles/{cycleid}/access`

Body fields: `userid`, `alayacareid`, `employmentstatus`, `enrolmentstatus`, `remindersenabled`.
`remindersenabled` may be `true`, `false`, or omitted (or `null`) to leave it unchanged.

```json
{"userid": 345, "alayacareid": "5005", "employmentstatus": "leave", "enrolmentstatus": "suspended",
 "remindersenabled": false}
```

Returns `200` with `cycle` and `policygates`.

#### `POST /v1/cycles/{cycleid}/repair`

No body. Re-evaluates completion for an open cycle. For a completed cycle, it recreates missing side effects and
re-queues failed events. Returns the updated [cycle](#cycle) with `gates`, or `404 cyclenotfound`.

## Moodle web service functions

### Calling the functions

The functions are Moodle's standard web service interface. New integrations should use the [REST API](#rest-api),
which calls the same code. Requests are `POST` to Moodle's web service endpoint:

```http
POST {wwwroot}/webservice/rest/server.php
Content-Type: application/x-www-form-urlencoded

wstoken={token}&moodlewsrestformat=json&wsfunction={function}&{parameters}
```

Array parameters use indexed keys, for example `cycleids[0]=C-2026-5005&cycleids[1]=C-2027-5005`.

Example:

```sh
curl -s "$MOODLE/webservice/rest/server.php" \
  -d wstoken="$TOKEN" -d moodlewsrestformat=json \
  -d wsfunction=local_caregivertraining_v1_health
```

### Web service errors

Moodle reports web service errors in the response body. Check for an `exception` key instead of relying on the HTTP
status code.

```json
{
  "exception": "moodle_exception",
  "errorcode": "error:cycleconflict",
  "message": "Cycle conflict: learner has active cycle C-2026-5005; send supersedescycleid to replace it"
}
```

Branch on `errorcode`. The plugin's own codes carry an `error:` prefix. Core Moodle codes (`invalidparameter`,
`nopermissions`, `locktimeout`, `invalidtoken`) don't. Apart from the prefix, the codes and meanings are the same as
in the [REST error table](#rest-errors).

| `errorcode` | REST equivalent |
| --- | --- |
| `invalidparameter`, `error:invalidemail`, `error:invaliddate` | 400 |
| `invalidtoken` | 401 |
| `nopermissions`, `accessexception` | 403 |
| `error:bindingmismatch`, `error:usernotfound`, `error:cyclenotfound` | 404 |
| `error:existingaccount`, `error:ambiguousemail`, `error:usernametaken`, `error:bindingconflict`, `error:cycleconflict`, `error:cycleimmutable`, `error:alreadycompleted`, `error:idempotencyconflict` | 409 |
| `error:notconfigured`, `locktimeout` | 503 |

Parameter validation errors always return `invalidparameter` with a generic message. Unlike the REST API, the
detailed reason (for example `alayacareid must be 1-64 characters of [A-Za-z0-9._-]`) appears in `debuginfo` only
when Moodle debugging is enabled.

### Web service idempotency

`provision_learner`, `upsert_cycle` and `update_access` require an `idempotencykey` parameter with the same rules as
the [REST `Idempotency-Key` header](#rest-idempotency). Keys are stored per function, so a key used through REST
replays through the matching web service function and vice versa.

## Function reference

The adapter functions below are what the REST routes call. Parameter and response fields are the same in both
interfaces.

| Function | Type | REST route | Purpose |
| --- | --- | --- | --- |
| `local_caregivertraining_v1_get_binding` | read | `GET /v1/learners`, `GET /v1/learners/{alayacareid}` | Look up a learner without changing anything. |
| `local_caregivertraining_v1_provision_learner` | write | `PUT /v1/learners/{alayacareid}` | Create, link or update a learner. |
| `local_caregivertraining_v1_upsert_cycle` | write | `PUT /v1/cycles/{cycleid}` | Create, update or start an annual cycle. |
| `local_caregivertraining_v1_update_access` | write | `PUT /v1/cycles/{cycleid}/access` | Apply employment, enrolment and reminder state to a cycle. |
| `local_caregivertraining_v1_reconcile` | write | `GET /v1/cycles`, `GET /v1/cycles/{cycleid}`, `POST /v1/cycles/{cycleid}/repair` | Read authoritative cycle state and optionally repair it. |
| `local_caregivertraining_v1_health` | read | `GET /v1/health` | Configuration and queue health. |
| `local_caregivertraining_v1_record_heartbeat` | write | None | Browser time tracking (not part of the adapter service). |

### `local_caregivertraining_v1_get_binding`

Read-only lookup the adapter should call before provisioning. At least one field is required.

| Parameter | Type | Required | Description |
| --- | --- | --- | --- |
| `alayacareid` | string | no | Canonical AlayaCare employee id. |
| `payrollnumber` | string | no | Payroll number. |
| `email` | string | no | Email. Used to find bound learners and unbound candidate accounts. |
| `userid` | int | no | Moodle user id. |

Response:

| Field | Description |
| --- | --- |
| `status` | `bound`: exactly one binding matched. `candidate`: no binding, but one unbound account has this email. `ambiguous`: no binding, several accounts have this email. `conflict`: the fields match more than one binding, or the binding found has a different `alayacareid`. `none`: nothing matched. |
| `bindings` | Matching [bindings](#binding), each with `matchedby` listing the fields that matched. |
| `candidates` | Unbound accounts that share the email: `userid`, `username`, `suspended`, `via`. |

```json
{
  "status": "bound",
  "bindings": [{
    "bindingid": 12, "userid": 345, "alayacareid": "5005", "payrollnumber": "PR-5005",
    "hcanumber": "", "registrationdate": "", "status": "active",
    "matchedby": "alayacareid,payrollnumber"
  }],
  "candidates": []
}
```

### `local_caregivertraining_v1_provision_learner`

Idempotently creates or links a learner, binds them to an AlayaCare employee, and updates the protected profile
fields. It never links an account by email on its own, and never creates an account when the match is ambiguous.

| Parameter | Type | Required | Description |
| --- | --- | --- | --- |
| `idempotencykey` | string | yes | Web service only; REST uses the `Idempotency-Key` header. See [Web service idempotency](#web-service-idempotency). |
| `alayacareid` | string | yes | Canonical immutable employee id, 1-64 characters of `[A-Za-z0-9._-]`. |
| `userid` | int | no | Existing Moodle user to link. This is the explicit confirmation that an account belongs to this employee. |
| `email` | string | no | Required when creating. Also used to detect existing accounts. |
| `firstname` | string | no | Required when creating. |
| `lastname` | string | no | Required when creating. |
| `payrollnumber` | string or null | no | Payroll number. `null` leaves it unchanged, `""` clears it. |
| `hcanumber` | string or null | no | HCA number. `null` leaves it unchanged, `""` clears it. |
| `registrationdate` | string or null | no | HCA registration date, `YYYY-MM-DD`. |
| `createifmissing` | bool | no, default `false` | Create an account when nothing matches. |
| `sendactivation` | bool | no, default `false` | Queue the time-limited activation email for accounts that have never been used. |

Response:

| Field | Description |
| --- | --- |
| `status` | `created`, `linked`, `updated`, `unchanged` or `notfound`. |
| `userid` | Moodle user id, or `0` for `notfound`. |
| `bindingid` | Binding id, or `0` for `notfound`. |
| `activation` | `queued`, `alreadyactive` or `notrequested`. |
| `replayed` | `true` when returned from a repeated idempotency key. |

How the request is resolved:

1. If `payrollnumber` is already bound to a **different** employee, fail with `bindingconflict`.
2. If `alayacareid` is already bound:
   - If `userid` is given and differs from the bound user, fail with `bindingconflict`.
   - If the bound user was deleted, fail with `usernotfound`.
   - Otherwise update name, email and profile fields and return `updated` or `unchanged`. Changing the email to one
     used by another account fails with `bindingconflict`.
3. If `alayacareid` is not bound and `userid` is given:
   - If the user is missing, deleted, a guest or a site admin, fail with `usernotfound`.
   - If the user is bound to another employee, fail with `bindingconflict`.
   - Otherwise link them and return `linked`.
4. If `alayacareid` is not bound and no `userid` is given:
   - If several accounts use the email, fail with `ambiguousemail`.
   - If one account uses the email, fail with `existingaccount`. Confirm it is the same person, then call again with
     that `userid`.
   - If `createifmissing` is `false`, return `notfound` without changing anything.
   - Otherwise create a manual-auth account (username is the lowercased email, with an unusable password) and return
     `created`. Missing `email`, `firstname` or `lastname` fails with `invalidparameter`. A username clash fails with
     `usernametaken`.

The `alayacareid` is also copied into the learner's locked **AlayaCare ID** profile field (`cgt_alayacareid`), so it
shows on the profile and can be searched by administrators.

### `local_caregivertraining_v1_upsert_cycle`

Idempotently creates, updates or starts an annual cycle. The adapter sends the AlayaCare anniversary date, which is
the due date. The plugin derives the rest of the window in its compliance timezone:

| Moment | Rule | Cycle field |
| --- | --- | --- |
| Window opens | 00:00 on the anniversary minus 60 days | `timeopen` |
| Due | 23:59:59 on the anniversary | `timedue` |
| Access ends | 23:59:59 on the anniversary plus 14 days | `timeaccessend` |

For example, an anniversary of `2027-03-15` opens on `2027-01-14` and access ends on `2027-03-29`.

| Parameter | Type | Required | Description |
| --- | --- | --- | --- |
| `idempotencykey` | string | yes | Web service only; REST uses the `Idempotency-Key` header. See [Web service idempotency](#web-service-idempotency). |
| `cycleid` | string | yes | Adapter-supplied unique Cycle ID, 1-100 characters of `[A-Za-z0-9._:-]`. |
| `userid` | int | yes | Moodle user id. |
| `alayacareid` | string | yes | Employee id that must already be bound to `userid`. |
| `hiredate` | string | no | `YYYY-MM-DD`, stored for reference. |
| `anniversarydate` | string | yes | AlayaCare anniversary date this cycle is due on, `YYYY-MM-DD`. |
| `supersedescycleid` | string | no | Active cycle this one replaces, for example after a rehire. |

Response:

| Field | Description |
| --- | --- |
| `action` | `created`, `updated` or `unchanged`. |
| `cycle` | The resulting [cycle](#cycle). |
| `replayed` | `true` when returned from a repeated idempotency key. |

Behaviour:

- **The binding is checked first.** If `alayacareid` isn't bound, or is bound to a different user, the call fails with
  `bindingmismatch`. If the binding exists but the user was deleted, it fails with `usernotfound`.
- **New Cycle ID.** The call fails with `alreadycompleted` when one of the learner's completed cycles has the same
  anniversary date. It fails with `cycleconflict` when the learner already has an active cycle (`scheduled`,
  `open` or `blocked`):
  - If that cycle has the same anniversary date: `cycle {id} already covers anniversary date {date}`.
  - Otherwise: `learner has active cycle {id}; send supersedescycleid to replace it`.
  - If `supersedescycleid` names an active cycle, the new cycle is created and the old one becomes `superseded`. If
    it doesn't name an active cycle of this learner, the call fails with `cycleconflict`.
- **Existing Cycle ID.**
  - If it belongs to another learner, fail with `cycleconflict` (`cycleid belongs to another learner`).
  - If the cycle is `completed` or `superseded`, an identical `anniversarydate` and `hiredate` return `unchanged`, and
    different values fail with `cycleimmutable`.
  - Otherwise identical values return `unchanged`, and new values are saved (with the window recalculated) and return
    `updated`. This includes cycles that are already `open`.
- **Starting.** A `scheduled` cycle whose open date has arrived starts immediately. Otherwise the scheduled task
  starts it on the open date. Starting snapshots and resets any earlier progress in the annual course. If the snapshot
  or reset fails, the cycle becomes `blocked` and the learner's progress is left untouched.
- **Enrolment.** The learner is enrolled in the annual course from the open date until the access end (14 days after
  the due date). An overdue cycle stays `open` and the learner keeps access until then. After that the enrolment has
  ended and the cycle reports `overdue` until it is completed or superseded.

### `local_caregivertraining_v1_update_access`

Applies employment, enrolment and reminder state that the adapter has decided on.

| Parameter | Type | Required | Description |
| --- | --- | --- | --- |
| `idempotencykey` | string | yes | Web service only; REST uses the `Idempotency-Key` header. See [Web service idempotency](#web-service-idempotency). |
| `cycleid` | string | yes | Cycle ID. |
| `userid` | int | yes | Moodle user id. |
| `alayacareid` | string | yes | Employee id bound to `userid`. |
| `employmentstatus` | string | yes | `active`, `inactive`, `terminated` or `leave`. |
| `enrolmentstatus` | string | no | `active`, `suspended`, or empty to leave unchanged. |
| `remindersenabled` | int | no, default `-1` | `1`, `0`, or `-1` to leave unchanged. |

Response:

| Field | Description |
| --- | --- |
| `cycle` | The updated [cycle](#cycle). |
| `policygates` | HR policies that aren't approved yet, so nothing was applied for them: `leave_access_unresolved` (leave without explicit enrolment and reminder values) or `terminated_access_unresolved` (termination without an explicit enrolment value). |
| `replayed` | `true` when returned from a repeated idempotency key. |

Notes:

- `terminated` always turns reminders off.
- Enrolment changes apply to `open` cycles, to `completed` cycles when `enrolmentstatus` is given, and to `scheduled`
  cycles when suspending.
- Errors: `bindingmismatch`, `usernotfound`, `cyclenotfound` when the Cycle ID doesn't exist or belongs to another
  learner, and `invalidparameter` for unknown status values.

### `local_caregivertraining_v1_reconcile`

Returns authoritative cycle state. Use it to poll for changes, or to repair missed completions and events.

| Parameter | Type | Required | Description |
| --- | --- | --- | --- |
| `cycleids` | string[] | no | Specific Cycle IDs. When given, `modifiedsince` is ignored. |
| `modifiedsince` | int | no, default `0` | Unix time. Returns cycles modified at or after it, oldest first. |
| `limit` | int | no, default `100` | 1-500. |
| `repair` | bool | no, default `false` | Re-evaluate completion for open cycles. For completed cycles, recreate missing side effects and re-queue failed events. |

Response:

| Field | Description |
| --- | --- |
| `cycles` | [Cycles](#cycle), each with `gates`, the list of unmet completion requirements for open cycles. |
| `servertime` | Server time. Send it as the next `modifiedsince`. |

Completion gate codes: `cycle_not_open`, `course_not_complete`, `completion_predates_cycle`,
`time_policy_unresolved`, `time_requirement_not_met`, `certificate_not_configured`.

### `local_caregivertraining_v1_health`

No parameters. Returns configuration and queue health without personal data.

| Field | Description |
| --- | --- |
| `contract` | Contract version (`v1`). |
| `pluginversion` | Plugin version number. |
| `moodlerelease` | Moodle release string. |
| `timepolicy` | `unresolved`, `tracked` or `nominal`. |
| `adapterdelivery` | Whether outbound delivery is enabled. |
| `cycles` | Counts by status: `scheduled`, `open`, `blocked`, `completed`. |
| `outbox` | Event counts: `pending`, `failed`. |
| `openexceptions` | Exceptions awaiting review. |
| `processcycleslastrun` | Last run of the cycle-processing task. |
| `problems` | `course_not_configured`, `certificate_not_configured`, `time_policy_unresolved`, `adapter_delivery_misconfigured`, `process_cycles_stale`. |
| `servertime` | Server time. |

### `local_caregivertraining_v1_record_heartbeat` (browser only)

Called by the plugin's own JavaScript on annual course pages, using the learner's session (`ajax: true`). It is not
part of the adapter service and can't be called with an adapter token. It requires
`local/caregivertraining:recordtime` in the annual course.

| Parameter | Type | Description |
| --- | --- | --- |
| `cmid` | int | Course module being viewed. |
| `sessiontoken` | string | Random per-page token, 32-64 lowercase hex characters. |
| `visible` | bool | Whether the page is visible. |
| `playing` | bool | Whether media on the page is playing. |
| `interactedago` | int | Seconds since the last interaction. |

The server decides how much time to credit using its own clock. The response includes `credited`, `creditseconds`,
`reason` (for example `credited`, `session_started`, `hidden`, `idle`, `gap`, `concurrent`, `too_frequent`, `busy`,
`no_open_cycle`, `activity_unavailable`, `token_mismatch`) and the updated banner values.

## Scenario reference

Each scenario shows the REST request and result. The web service function behaves the same way, returning the same
error code with an `error:` prefix (see [Web service errors](#web-service-errors)).

### 1. Provision a new student

**Supported.** Send `PUT /v1/learners/{alayacareid}` with `"createifmissing": true`, plus `email`, `firstname` and
`lastname`.

```http
PUT /local/caregivertraining/api.php/v1/learners/5005
Authorization: Bearer {token}
Idempotency-Key: prov-5005-1
Content-Type: application/json

{"email": "riley@example.com", "firstname": "Riley", "lastname": "Synthetic",
 "payrollnumber": "PR-5005", "createifmissing": true, "sendactivation": true}
```

```http
HTTP/1.1 201 Created
Location: https://moodle.example.com/local/caregivertraining/api.php/v1/learners/5005

{"status": "created", "userid": 345, "bindingid": 12, "activation": "queued", "replayed": false}
```

If a Moodle account already uses the email, the request fails with `409 existingaccount` instead of creating a
duplicate. Once you've confirmed it's the same person, repeat the request with `"userid"` (and a new idempotency
key) to link that account. That returns `200` with `"status": "linked"`.

Web service: `local_caregivertraining_v1_provision_learner` with `createifmissing=1`.

### 2. Provision a duplicate student

**Supported.** No request creates a second account or binding for the same person. What you get back depends on what
is duplicated:

| Situation | Status | Result |
| --- | --- | --- |
| Same idempotency key and body | Original status | Stored response with `"replayed": true`. |
| Same idempotency key, different body | `409` | `idempotencyconflict` |
| `alayacareid` already provisioned (new key) | `200` | `"status": "unchanged"`, or `"updated"` if details changed. No new account. |
| `alayacareid` already bound to a different `userid` than requested | `409` | `bindingconflict`: `employee is bound to a different Moodle user` |
| Requested `userid` is already bound to another employee | `409` | `bindingconflict`: `Moodle user is bound to a different employee` |
| `payrollnumber` already belongs to another employee | `409` | `bindingconflict`: `payrollnumber is bound to another employee` |
| One unbound account already uses the email | `409` | `existingaccount`. The message includes that account's id. |
| Several accounts use the email | `409` | `ambiguousemail` |
| Username derived from the email is taken | `409` | `usernametaken` |
| Email change collides with another account | `409` | `bindingconflict`: `email belongs to another account` |

Check first with `GET /v1/learners?alayacareid=...&email=...`. A `status` of `candidate`, `ambiguous` or `conflict`
means a duplicate needs review before provisioning.

### 3. Provision when the AlayaCare ID is not found

**Supported, with caveats.** The plugin has no AlayaCare data, so "not found" means no Moodle learner is bound to the
id.

| Request | Status | Result |
| --- | --- | --- |
| `GET /v1/learners/{alayacareid}` | `404` | `learnernotfound` |
| `GET /v1/learners?alayacareid={id}` | `200` | `"status": "none"`, `"count": 0`, empty `items` and `candidates`. |
| `PUT /v1/learners/{alayacareid}` without `createifmissing` | `404` | `learnernotfound`. Nothing changes, and `details` explains how to create or link the learner. |
| `PUT /v1/learners/{alayacareid}` with `"createifmissing": true` | `201` | The learner is created. |

```json
{
  "code": 404,
  "error": "learnernotfound",
  "message": "No Moodle learner is bound to AlayaCare id 5005.",
  "details": "Send \"createifmissing\": true with email, firstname and lastname to create the learner, or \"userid\" to link an existing account."
}
```

Caveats:

- Provisioning matches only on `alayacareid`, `userid` and `email`. `payrollnumber` is a stored attribute and is only
  checked for conflicts with other employees. To find a learner by payroll number, use
  `GET /v1/learners?payrollnumber=...`.
- A malformed `alayacareid` fails with `400 invalidparameter`, and `details` gives the allowed format.
- The web service function returns `"status": "notfound"` (not an error) where REST returns `404`.
- An employee who doesn't exist in AlayaCare must be caught by the adapter. The plugin can't detect it.

### 4. Cycle already started or in progress

**Supported.** The result of `PUT /v1/cycles/{cycleid}` depends on the Cycle ID:

| Situation | Status | Result |
| --- | --- | --- |
| Same `cycleid`, same `anniversarydate` | `200` | `"action": "unchanged"` with the current cycle (`status` `open`, `scheduled` or `blocked`). |
| Same `cycleid`, new `anniversarydate`, not yet completed | `200` | `"action": "updated"`. The recalculated window applies even if the cycle is already open. |
| New `cycleid`, learner has an active cycle with the same anniversary date | `409` | `cycleconflict`: `cycle C-… already covers anniversary date 2027-03-15` |
| New `cycleid`, learner has a different active cycle | `409` | `cycleconflict`: `learner has active cycle C-…; send supersedescycleid to replace it` |
| New `cycleid` with `supersedescycleid` set to the active cycle | `201` | `"action": "created"`, and the old cycle becomes `superseded`. |
| Two concurrent creates for the same learner | `409` | One succeeds. The other fails with `cycleconflict`: `concurrent cycle creation`. |

Every conflict in this table shares the `cycleconflict` code, so the adapter has to read `message` to tell them apart.
To see the learner's current cycle, use `GET /v1/cycles/{cycleid}`.

### 5. Cycle when the student is not found

**Supported.** `PUT /v1/cycles/{cycleid}` and `PUT /v1/cycles/{cycleid}/access` check the binding before anything
else:

| Situation | Status | Result |
| --- | --- | --- |
| `alayacareid` not provisioned, or bound to a different `userid` | `404` | `bindingmismatch` |
| `userid` doesn't exist in Moodle | `404` | `bindingmismatch` (there is no binding for that user) |
| Binding exists but the Moodle user was deleted | `404` | `usernotfound` |
| `PUT .../access` with an unknown Cycle ID, or one belonging to another learner | `404` | `cyclenotfound` |
| `GET /v1/cycles/{cycleid}` with an unknown Cycle ID | `404` | `cyclenotfound` |

Provision the learner first, then create the cycle.

### 6. Cycle when the student already finished the annual training

**Supported.**

| Situation | Status | Result |
| --- | --- | --- |
| `GET /v1/cycles/{cycleid}` for a completed cycle | `200` | `"status": "completed"`, `"compliance": "complete"`, `timecompleted` and `certificatecode`. |
| `PUT` the same `cycleid` as a completed cycle, same `anniversarydate` | `200` | `"action": "unchanged"` with the completed cycle. |
| `PUT` the same `cycleid` as a completed cycle, different `anniversarydate` | `409` | `cycleimmutable`: `Cycle C-… is final and cannot be changed.` |
| `PUT` a new `cycleid` with the same `anniversarydate` as a completed cycle | `409` | `alreadycompleted`: `The learner already completed the annual training due 2027-03-15 in cycle C-…. A new cycle needs a different anniversary date.` Nothing is created and the finished progress is kept. |
| `PUT` a new `cycleid` with a different `anniversarydate` (next year, or a rehire) | `201` | `"action": "created"` |

When a cycle completes, the plugin also sends the [`cycle.completed`](#outbound-event-cyclecompleted) event.

Known gap:

- **No lookup by learner.** Cycles can only be fetched by Cycle ID or modification time, so the adapter needs the
  Cycle ID to check whether a learner has finished.

## Outbound event: `cycle.completed`

When a cycle completes, the plugin queues exactly one event for it and `POST`s it to the configured adapter URL.
Failed deliveries are retried with exponential backoff (up to 6 hours between attempts, `maxattempts` attempts in
total).

Headers:

| Header | Value |
| --- | --- |
| `Content-Type` | `application/json` |
| `X-CGT-Event-Id` | Stable event UUID. Deduplicate on this. |
| `X-CGT-Event-Type` | `cycle.completed` |
| `X-CGT-Timestamp` | Unix time of this delivery attempt. |
| `X-CGT-Signature` | `v1=` + hex HMAC-SHA256 of `{timestamp}.{raw body}`, keyed with the shared secret. |

The adapter should return a `2xx` status when it accepts the event. `409` is treated as "already processed". Any other
status, or a network error, is retried.

Body:

```json
{
  "contract": "caregivertraining.v1",
  "type": "cycle.completed",
  "eventid": "6f1c1f7e-0d1e-4c55-9a43-2f0f6b1d3a10",
  "occurredat": "2026-10-02T02:14:00+00:00",
  "source": "https://moodle.example.com/",
  "data": {
    "cycleid": "C-2026-5005",
    "alayacareid": "5005",
    "payrollnumber": "PR-5005",
    "moodleuserid": 345,
    "hiredate": "2021-03-15",
    "anniversarydate": "2027-03-15",
    "dueyear": 2027,
    "completedat": "2026-10-01T19:14:00-07:00",
    "completeddate": "2026-10-01",
    "ontime": true,
    "approvedseconds": 18240,
    "timepolicy": "tracked",
    "certificate": {
      "code": "AbC123XyZ9",
      "issueid": 77,
      "verifyurl": "https://moodle.example.com/mod/customcert/verify_certificate.php?code=AbC123XyZ9"
    }
  }
}
```

`completedat` and `completeddate` are in the compliance timezone. `dueyear` is the year of `anniversarydate`.
`ontime` is `true` when the cycle was completed on or before the anniversary date.

## Browser pages

These pages use the logged-in user's Moodle session, not a web service token. Every state-changing request requires
the session key (`sesskey`).

### `index.php`: administrator view

```text
GET  /local/caregivertraining/index.php?tab={tab}&showarchived={0|1}
POST /local/caregivertraining/index.php?tab={tab}&action={action}&id={id}&sesskey={sesskey}
```

Also reachable from Site administration as the plugin's report page. Viewing requires
`local/caregivertraining:viewreports`.

| Parameter | Type | Default | Description |
| --- | --- | --- | --- |
| `tab` | string | `exceptions` | `exceptions`, `blocked`, `cycles` or `snapshots`. |
| `showarchived` | bool | `0` | Include archived cycles and resolved exceptions. |
| `action` | string | none | `retry`, `archive` or `resolve`. Requires `id` and `sesskey`. |
| `id` | int | none | Local cycle id for `retry` and `archive`, exception id for `resolve`. |

Tabs (each shows up to 500 rows):

| Tab | Shows | Action |
| --- | --- | --- |
| `exceptions` | Open identity and cycle exceptions: type, occurrence count, AlayaCare id, details. | `resolve`: mark the exception resolved. |
| `blocked` | Cycles whose snapshot or reset failed, with the reason. | `retry`: run the snapshot and reset again. |
| `cycles` | All cycles except superseded ones, with employee fields, status, compliance, due (anniversary) date, access end date, approved time and certificate code. | `archive`: hide a completed cycle from default views. Nothing is deleted. |
| `snapshots` | Evidence snapshots: type, creation time, verification, hash and certificate links. | None. |

All three actions require `local/caregivertraining:managecycles`. `archive` only accepts completed or superseded
cycles and fails with `cycleconflict` otherwise. After an action, the page redirects back to the same tab. `retry`
shows the resulting status, plus the blocked reason if it failed again.

Each action triggers a Moodle event: `cycle_retried`, `cycle_archived` or `exception_resolved`.

### `export.php`: CSV evidence export

```text
POST /local/caregivertraining/export.php
sesskey={sesskey}&filter={filter}
```

Requires `local/caregivertraining:export`. The administrator view has a form for it.

| Parameter | Type | Required | Description |
| --- | --- | --- | --- |
| `sesskey` | string | yes | Session key. |
| `filter` | string | no | Exact match on AlayaCare id, payroll number, HCA number, Cycle ID or certificate code. Empty exports everything. |

Returns a CSV download named `caregiver-training-{YYYYMMDD-HHMMSS}.csv`. There is one row per snapshot, or one row
for a cycle without snapshots. Columns:

```text
alayacareid, payrollnumber, hcanumber, registrationdate, moodleuserid, fullname,
cycleid, status, compliance, hiredate, anniversarydate, accessenddate, timecompleted, approvedseconds,
timepolicy, certificatecode, resetstate, archived, snapshotid, snapshottype, snapshotverified,
snapshotsha256, snapshotcreated, snapshotretainuntil, snapshotcounts, certificatefiles
```

- Times are ISO 8601 in the compliance timezone, and `snapshotretainuntil` is a date.
- `certificatefiles` lists `code:pdfsha256` pairs separated by spaces.
- Each export triggers an `evidence_exported` event recording the filter and row count.

### `next.php`: next activity

```text
GET /local/caregivertraining/next.php?courseid={courseid}&cmid={cmid}
```

This is the target of the learner's **Next activity** button on annual course pages.

| Parameter | Type | Required | Description |
| --- | --- | --- | --- |
| `courseid` | int | yes | Course id. The user must be able to access the course. |
| `cmid` | int | no | Current activity. Only activities after it, in course order, are considered. |

The page redirects to the first activity after `cmid` that the learner can see and open, that tracks completion, and
that isn't complete yet. If there isn't one, it redirects to the course page with the message "no next activity".
The target activity still enforces its own access rules.

### Snapshot certificate files

```text
GET /pluginfile.php/{systemcontextid}/local_caregivertraining/snapshotcert/{snapshotid}/{filename}?forcedownload=1
```

These are copies of certificate PDFs taken when a snapshot was created, so they survive the course reset. The
`snapshots` tab of the administrator view links to them. Downloading requires
`local/caregivertraining:viewreports` in the system context.

## CLI: `create_test_cycle.php`

Links an existing Moodle user to a test AlayaCare id and gives them a cycle in the annual course, without the
adapter. It runs as the site administrator.

```sh
php public/local/caregivertraining/cli/create_test_cycle.php --userid=42
```

| Option | Default | Description |
| --- | --- | --- |
| `--userid` | required | Moodle user id of the learner. |
| `--alayacareid` | `TEST-{userid}` | AlayaCare id to link. Ignored if the user is already linked. |
| `--cycleid` | `TEST-{userid}-{anniversarydate}` | Cycle ID. |
| `--anniversarydate` | 30 days from today | Anniversary (due) date, `YYYY-MM-DD`. The window opens 60 days before it, so the default cycle opens immediately. |
| `-h`, `--help` | | Print help. |

It goes through the same code as `provision_learner` and `upsert_cycle`, so the same conflict rules and errors apply.
If the learner already has progress in the course and the cycle opens today or earlier, that progress is snapshotted
and reset, exactly as for a real cycle.

## Capabilities

| Capability | Context | Granted by default to | Needed for |
| --- | --- | --- | --- |
| `local/caregivertraining:adapterapi` | System | Nobody | The REST API and all adapter web service functions. |
| `local/caregivertraining:viewreports` | System | Manager | `index.php`, snapshot certificate files. |
| `local/caregivertraining:export` | System | Manager | `export.php`. |
| `local/caregivertraining:managecycles` | System | Manager | Retry, archive and resolve actions in `index.php`. |
| `local/caregivertraining:recordtime` | Course | Student | `record_heartbeat` and the time tracker. |

## Shared structures

### Binding

| Field | Description |
| --- | --- |
| `bindingid` | Binding id. |
| `userid` | Moodle user id. |
| `alayacareid` | Canonical AlayaCare employee id. |
| `payrollnumber` | Payroll number. |
| `hcanumber` | HCA number, from the locked profile field. |
| `registrationdate` | HCA registration date (`YYYY-MM-DD`), or empty. |
| `status` | Binding status. |
| `matchedby` | `get_binding` only: the fields that matched. |

### Cycle

| Field | Description |
| --- | --- |
| `cycleid` | Cycle ID. |
| `userid` | Moodle user id. |
| `alayacareid` | Canonical AlayaCare employee id. |
| `status` | `scheduled`, `open`, `blocked`, `completed` or `superseded`. |
| `compliance` | `upcoming`, `open`, `overdue`, `complete` or `notapplicable`. |
| `hiredate` | `YYYY-MM-DD`, or empty. |
| `anniversarydate` | `YYYY-MM-DD`. The due date. |
| `timeopen` | Unix time when the window opens: 00:00 on the anniversary minus 60 days. |
| `timedue` | Unix time of the last second of the anniversary date. |
| `timeaccessend` | Unix time of the last second of course access: the anniversary plus 14 days. |
| `employmentstatus` | Last reported employment status. |
| `enrolmentstatus` | `active` or `suspended`. |
| `remindersenabled` | Whether reminders are sent. |
| `resetstate` | `pending`, `notrequired`, `done` or `blocked`. |
| `blockedreason` | Why the cycle is blocked, or empty. |
| `timecompleted` | Completion time, or `0`. |
| `approvedseconds` | Approved training seconds at completion. |
| `timepolicy` | Time policy in force at completion. |
| `certificatecode` | Certificate code, or empty. |
| `events` | Outbound events for this cycle: `eventtype`, `eventid`, `status` (`pending`, `delivered`, `failed`), `attempts`. |
