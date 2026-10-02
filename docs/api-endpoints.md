# Caregiver training endpoints (contract v1)

This page documents every endpoint of `local_caregivertraining`:

- The web service functions the integration adapter calls, and how they respond to common provisioning and cycle
  scenarios.
- The signed `cycle.completed` event the plugin sends back to the adapter.
- The browser pages, file downloads and CLI script used by administrators and learners.

The adapter owns AlayaCare synchronization, eligibility and date calculations. The plugin never calls AlayaCare, so
"not found" in this document always means "not found in Moodle".

## All endpoints at a glance

| Endpoint | Kind | Used by | Purpose |
| --- | --- | --- | --- |
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

## Contents

- [Calling the API](#calling-the-api)
- [Error responses](#error-responses)
- [Idempotency](#idempotency)
- [Endpoints](#endpoints)
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

## Calling the API

All adapter functions belong to the **Caregiver training adapter** external service
(`local_caregivertraining_adapter`). The service is disabled by default and restricted to authorised users.

Setup:

1. Enable web services and the REST protocol.
2. Enable the `local_caregivertraining_adapter` service and add the adapter's service account as an authorised user.
3. Give that account a system-level role with `local/caregivertraining:adapterapi`. No default role has this
   capability.
4. Create a token for the account on that service.

Requests are `POST` to the Moodle REST server:

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

## Error responses

Moodle reports errors in the response body. Check for an `exception` key instead of relying on the HTTP status code.

```json
{
  "exception": "moodle_exception",
  "errorcode": "cycleconflict",
  "message": "Cycle conflict: learner has active cycle C-2026-5005; send supersedescycleid to replace it"
}
```

Branch on `errorcode`. For `bindingconflict` and `cycleconflict`, the `message` also explains which rule was broken.

Parameter validation errors (`invalid_parameter_exception`) always return `errorcode` `invalidparameter` and a
generic message. The detailed reason, such as `alayacareid must be 1-64 characters of [A-Za-z0-9._-]`, appears in
`debuginfo` only when Moodle debugging is enabled.

| `errorcode` | Meaning |
| --- | --- |
| `invalidparameter` | A parameter is missing, has the wrong type or fails a format check. |
| `nopermissions` | The token's user lacks `local/caregivertraining:adapterapi`. |
| `notconfigured` | The annual course, manual enrolment or another required setting is missing. |
| `idempotencyconflict` | The idempotency key was already used with different parameters. |
| `locktimeout` | Another request for the same learner held the lock for too long. Safe to retry. |
| `invalidemail` | The email address is not valid. |
| `existingaccount` | An unbound Moodle account already uses this email. |
| `ambiguousemail` | More than one Moodle account uses this email. |
| `usernametaken` | The username derived from the email belongs to another account. |
| `usernotfound` | The Moodle user doesn't exist, is deleted, or can't be linked (guest or site admin). |
| `bindingconflict` | The request would bind an employee, user, External ID, payroll number or email to two different people. |
| `bindingmismatch` | The `userid` and `alayacareid` don't match an existing binding. |
| `invaliddate` | A date is not a real `YYYY-MM-DD` calendar date. |
| `dateorder` | `opendate` is after `duedate`. |
| `cycleconflict` | The cycle clashes with another cycle (see the message). |
| `cycleimmutable` | The cycle is completed or superseded and its dates can't change. |
| `cyclenotfound` | No cycle with this Cycle ID belongs to the learner. |

Every identity or cycle conflict is also recorded as an exception for HR or Engineering review on the plugin's admin
page.

## Idempotency

`provision_learner`, `upsert_cycle` and `update_access` require an `idempotencykey`: 1-128 characters of
`[A-Za-z0-9._:-]`, unique per logical request.

- Repeating a key with identical parameters returns the stored response with `"replayed": true`. Nothing runs again.
- Repeating a key with different parameters fails with `idempotencyconflict`.
- Errors are not stored. A request that failed can be retried with the same key once the cause is fixed.

## Endpoints

| Function | Type | Purpose |
| --- | --- | --- |
| `local_caregivertraining_v1_get_binding` | read | Look up a learner without changing anything. |
| `local_caregivertraining_v1_provision_learner` | write | Create, link or update a learner. |
| `local_caregivertraining_v1_upsert_cycle` | write | Create, update or start an annual cycle. |
| `local_caregivertraining_v1_update_access` | write | Apply employment, enrolment and reminder state to a cycle. |
| `local_caregivertraining_v1_reconcile` | write | Read authoritative cycle state and optionally repair it. |
| `local_caregivertraining_v1_health` | read | Configuration and queue health. |
| `local_caregivertraining_v1_record_heartbeat` | write | Browser time tracking (not part of the adapter service). |

### `local_caregivertraining_v1_get_binding`

Read-only lookup the adapter should call before provisioning. At least one field is required.

| Parameter | Type | Required | Description |
| --- | --- | --- | --- |
| `alayacareid` | string | no | Canonical AlayaCare employee id. |
| `externalid` | string | no | AlayaCare External ID. |
| `payrollid` | string | no | Employee ID or payroll number. |
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
    "bindingid": 12, "userid": 345, "alayacareid": "5005", "externalid": "EXT-5005",
    "payrollid": "PR-5005", "hcanumber": "", "registrationdate": "", "status": "active",
    "matchedby": "alayacareid,externalid"
  }],
  "candidates": []
}
```

### `local_caregivertraining_v1_provision_learner`

Idempotently creates or links a learner, binds them to an AlayaCare employee, and updates the protected profile
fields. It never links an account by email on its own, and never creates an account when the match is ambiguous.

| Parameter | Type | Required | Description |
| --- | --- | --- | --- |
| `idempotencykey` | string | yes | See [Idempotency](#idempotency). |
| `alayacareid` | string | yes | Canonical immutable employee id, 1-64 characters of `[A-Za-z0-9._-]`. |
| `userid` | int | no | Existing Moodle user to link. This is the explicit confirmation that an account belongs to this employee. |
| `email` | string | no | Required when creating. Also used to detect existing accounts. |
| `firstname` | string | no | Required when creating. |
| `lastname` | string | no | Required when creating. |
| `externalid` | string or null | no | AlayaCare External ID. `null` leaves it unchanged, `""` clears it. |
| `payrollid` | string or null | no | Payroll number. `null` leaves it unchanged, `""` clears it. |
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

1. If `externalid` or `payrollid` is already bound to a **different** employee, fail with `bindingconflict`.
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

### `local_caregivertraining_v1_upsert_cycle`

Idempotently creates, updates or starts an annual cycle. Dates are calculated by the adapter and interpreted in the
plugin's compliance timezone: `opendate` starts at 00:00 and `duedate` ends at 23:59:59.

| Parameter | Type | Required | Description |
| --- | --- | --- | --- |
| `idempotencykey` | string | yes | See [Idempotency](#idempotency). |
| `cycleid` | string | yes | Adapter-supplied unique Cycle ID, 1-100 characters of `[A-Za-z0-9._:-]`. |
| `userid` | int | yes | Moodle user id. |
| `alayacareid` | string | yes | Employee id that must already be bound to `userid`. |
| `hiredate` | string | no | `YYYY-MM-DD`, stored for reference. |
| `opendate` | string | yes | Window open date, `YYYY-MM-DD`. |
| `duedate` | string | yes | Due date, `YYYY-MM-DD`. Must be on or after `opendate`. |
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
- **New Cycle ID.** The call fails with `cycleconflict` when the learner already has an active cycle (`scheduled`,
  `open` or `blocked`):
  - If that cycle has the same due date: `cycle {id} already covers due date {date}`.
  - Otherwise: `learner has active cycle {id}; send supersedescycleid to replace it`.
  - If `supersedescycleid` names an active cycle, the new cycle is created and the old one becomes `superseded`. If
    it doesn't name an active cycle of this learner, the call fails with `cycleconflict`.
- **Existing Cycle ID.**
  - If it belongs to another learner, fail with `cycleconflict` (`cycleid belongs to another learner`).
  - If the cycle is `completed` or `superseded`, identical dates return `unchanged` and different dates fail with
    `cycleimmutable`.
  - Otherwise identical dates return `unchanged`, and new dates are saved and return `updated`. This includes cycles
    that are already `open`.
- **Starting.** A `scheduled` cycle whose open date has arrived starts immediately. Otherwise the scheduled task
  starts it on the open date. Starting snapshots and resets any earlier progress in the annual course. If the snapshot
  or reset fails, the cycle becomes `blocked` and the learner's progress is left untouched.
- **Enrolment.** The learner is enrolled in the annual course from the open date with no end date. Overdue cycles stay
  open.

### `local_caregivertraining_v1_update_access`

Applies employment, enrolment and reminder state that the adapter has decided on.

| Parameter | Type | Required | Description |
| --- | --- | --- | --- |
| `idempotencykey` | string | yes | See [Idempotency](#idempotency). |
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

### 1. Provision a new student

**Supported.** Call `provision_learner` with `createifmissing=true`, plus `email`, `firstname` and `lastname`.

```text
wsfunction=local_caregivertraining_v1_provision_learner
idempotencykey=prov-5005-1
alayacareid=5005
email=riley@example.com
firstname=Riley
lastname=Synthetic
externalid=EXT-5005
payrollid=PR-5005
createifmissing=1
sendactivation=1
```

```json
{"status": "created", "userid": 345, "bindingid": 12, "activation": "queued", "replayed": false}
```

If a Moodle account already exists for the person, the call fails with `existingaccount` instead of creating a
duplicate. Repeat it with `userid` to link that account, which returns `"status": "linked"`.

### 2. Provision a duplicate student

**Supported.** No call path creates a second account or binding for the same person. What you get back depends on
what is duplicated:

| Situation | Result |
| --- | --- |
| Same idempotency key and parameters | Stored response returned with `"replayed": true`. |
| Same idempotency key, different parameters | `idempotencyconflict` |
| `alayacareid` already provisioned (new key) | `"status": "unchanged"`, or `"updated"` if details changed. No new account. |
| `alayacareid` already bound to a different `userid` than requested | `bindingconflict`: `employee is bound to a different Moodle user` |
| Requested `userid` is already bound to another employee | `bindingconflict`: `Moodle user is bound to a different employee` |
| `externalid` or `payrollid` already belongs to another employee | `bindingconflict`: `externalid is bound to another employee` (or `payrollid`) |
| One unbound account already uses the email | `existingaccount`. The message includes that account's id. |
| Several accounts use the email | `ambiguousemail` |
| Username derived from the email is taken | `usernametaken` |
| Email change collides with another account | `bindingconflict`: `email belongs to another account` |

Call `get_binding` first to see what matches. A `status` of `candidate`, `ambiguous` or `conflict` means a duplicate
needs review before provisioning.

### 3. Provision when the AlayaCare External ID is not found

**Supported, with caveats.** The plugin has no AlayaCare data, so "not found" means no Moodle learner is bound to the
id.

- `get_binding` with `alayacareid` or `externalid` returns `"status": "none"` with empty `bindings` and
  `candidates`.
- `provision_learner` with `createifmissing=false` returns this when nothing matches, without changing data:

  ```json
  {"status": "notfound", "userid": 0, "bindingid": 0, "activation": "notrequested", "replayed": false}
  ```

- With `createifmissing=true`, the same request creates the learner instead.

Caveats:

- `provision_learner` matches only on `alayacareid`, `userid` and `email`. `externalid` and `payrollid` are stored
  attributes and are only checked for conflicts with other employees. To find a learner by External ID, use
  `get_binding`.
- A missing or malformed `alayacareid` fails with `invalidparameter`. The detailed reason is only visible with
  debugging enabled.
- An employee who doesn't exist in AlayaCare must be caught by the adapter. The plugin can't detect it.

### 4. Cycle already started or in progress

**Supported.** The result of `upsert_cycle` depends on the Cycle ID:

| Situation | Result |
| --- | --- |
| Same `cycleid`, same dates | `"action": "unchanged"` with the current cycle (`status` `open`, `scheduled` or `blocked`). |
| Same `cycleid`, new dates, not yet completed | `"action": "updated"`. The new dates apply even if the cycle is already open. |
| New `cycleid`, learner has an active cycle with the same due date | `cycleconflict`: `cycle C-… already covers due date 2026-12-31` |
| New `cycleid`, learner has a different active cycle | `cycleconflict`: `learner has active cycle C-…; send supersedescycleid to replace it` |
| New `cycleid` with `supersedescycleid` set to the active cycle | `"action": "created"`, and the old cycle becomes `superseded`. |
| Two concurrent creates for the same learner | One succeeds. The other fails with `cycleconflict`: `concurrent cycle creation`. |

Every case in this table shares the `cycleconflict` error code, so the adapter has to read `message` to tell them
apart.

### 5. Cycle when the student is not found

**Supported.** `upsert_cycle` and `update_access` check the binding before anything else:

| Situation | Result |
| --- | --- |
| `alayacareid` not provisioned, or bound to a different `userid` | `bindingmismatch` |
| `userid` doesn't exist in Moodle | `bindingmismatch` (there is no binding for that user) |
| Binding exists but the Moodle user was deleted | `usernotfound` |
| `update_access` with an unknown Cycle ID, or one belonging to another learner | `cyclenotfound` |

Provision the learner first, then create the cycle.

### 6. Cycle when the student already finished the annual training

**Supported for the same cycle. Partly supported across cycles.**

| Situation | Result |
| --- | --- |
| Same `cycleid` as a completed cycle, same dates | `"action": "unchanged"` with `"status": "completed"`, `"compliance": "complete"`, `timecompleted` and `certificatecode`. |
| Same `cycleid` as a completed cycle, different dates | `cycleimmutable`: `Cycle C-… is final and cannot be changed.` |
| New `cycleid` after a completed cycle (next year) | `"action": "created"`. Completed cycles don't block new ones. |
| `reconcile` with `cycleids[0]={cycleid}` | Current status, compliance, completion time and certificate code. |

When a cycle completes, the plugin also sends the [`cycle.completed`](#outbound-event-cyclecompleted) event.

Known gaps:

- **Duplicate cycles for an already-completed year.** The due-date clash check only looks at active cycles. If the
  adapter sends a new `cycleid` with the same due date as a completed cycle, the plugin creates a second cycle, which
  resets the learner's finished progress when it opens. The adapter must avoid this until the plugin rejects it.
- **No lookup by learner.** `reconcile` searches by Cycle ID or modification time only, so the adapter needs the Cycle
  ID to check whether a learner has finished.

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
    "externalid": "EXT-5005",
    "payrollid": "PR-5005",
    "moodleuserid": 345,
    "hiredate": "2021-03-15",
    "opendate": "2026-01-01",
    "duedate": "2026-12-31",
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

`completedat` and `completeddate` are in the compliance timezone.

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
| `cycles` | All cycles except superseded ones, with employee fields, status, compliance, dates, approved time and certificate code. | `archive`: hide a completed cycle from default views. Nothing is deleted. |
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
| `filter` | string | no | Exact match on AlayaCare id, External ID, payroll number, HCA number, Cycle ID or certificate code. Empty exports everything. |

Returns a CSV download named `caregiver-training-{YYYYMMDD-HHMMSS}.csv`. There is one row per snapshot, or one row
for a cycle without snapshots. Columns:

```text
alayacareid, externalid, payrollid, hcanumber, registrationdate, moodleuserid, fullname,
cycleid, status, compliance, hiredate, opendate, duedate, timecompleted, approvedseconds,
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
| `--cycleid` | `TEST-{userid}-{opendate}` | Cycle ID. |
| `--opendate` | Today | Window open date, `YYYY-MM-DD`, in the compliance timezone. |
| `--duedate` | 30 days from today | Due date, `YYYY-MM-DD`. |
| `-h`, `--help` | | Print help. |

It goes through the same code as `provision_learner` and `upsert_cycle`, so the same conflict rules and errors apply.
If the learner already has progress in the course and the cycle opens today or earlier, that progress is snapshotted
and reset, exactly as for a real cycle.

## Capabilities

| Capability | Context | Granted by default to | Needed for |
| --- | --- | --- | --- |
| `local/caregivertraining:adapterapi` | System | Nobody | All adapter web service functions. |
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
| `externalid` | AlayaCare External ID. |
| `payrollid` | Payroll number. |
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
| `hiredate`, `opendate`, `duedate` | `YYYY-MM-DD`. `hiredate` may be empty. |
| `timeopen` | Unix time when the window opens. |
| `timedue` | Unix time of the last second of the due date. |
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
