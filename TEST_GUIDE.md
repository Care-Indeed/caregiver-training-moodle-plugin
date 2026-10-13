# Manual QA test guide: `local_caregivertraining`

This guide checks the caregiver annual training plugin against the agreed requirements. Every route, field, status
code and message below comes from the plugin code (version `2026100802`, release `0.2.0`), and the error bodies were
captured from the local Docker site. Where the implementation differs from the requirement, the test case is marked
**⚠️** with an explanation.

> For a plain-language walkthrough aimed at non-technical testers, see [docs/testing-guide.md](docs/testing-guide.md).
> Full API reference: [docs/api-endpoints.md](docs/api-endpoints.md) and [docs/openapi.yaml](docs/openapi.yaml).

**Differences from the original requirement wording, at a glance:**

| Requirement says | Implementation | Where covered |
| --- | --- | --- |
| API key header (`x-api-key`) | Moodle web service token sent as `Authorization: Bearer <API_KEY>` | A3, C4 |
| `alayacareId`, `moodleId`, `certificateUrl` | `alayacareid`, `moodleuserid`, `certificate.verifyurl` (lower case, nested) | B1 |
| Annual training status webhook | Only `cycle.completed` is pushed; other statuses must be polled | B2 |
| Start cycle with Moodle ID only | `PUT /v1/cycles/{cycleid}` with `userid` **and** `alayacareid` **and** `anniversarydate` | D1 |
| Provision auto-enrols in the course | Enrolment happens when the cycle is created, not at provisioning | C1, D1 |
| Swagger URL | No hosted Swagger UI; `docs/openapi.yaml` file | C6 |

**Recommended run order** (later tests reuse data from earlier ones): A → C → D1–D3, D5 → E3 (makes a completed
cycle) → D4 → B → E1, E2, E4–E6 → F → G.

---

## A. Environment setup

### A1. Run the app locally

The local stack lives in the **Moodle repository** (`../moodle`), not in this plugin repository. Run these from the
Moodle repository folder.

```bash
pnpm run local:init               # first time only: creates docker/local/local.env
pnpm run local:up:plugin-dev      # Moodle with THIS plugin checkout mounted (http://localhost:8080)
pnpm run local:seed               # synthetic course, 3 learners, adapter user and token
```

- Use `local:up:plugin-dev`, not `local:up`. Plain `local:up` installs the plugin version pinned in the Dockerfile,
  which may be older than the code under test.
- After starting, open `http://localhost:8080/admin/index.php` once as admin to finish any plugin upgrade.
- Confirm the version: **Site administration → Plugins → Plugins overview**, search "Caregiver" → `2026100802`.
- Mail is captured by Mailpit at `http://localhost:8025`. Nothing leaves your machine.

| Container | Purpose |
| --- | --- |
| `moodle-local-moodle-1` | Moodle (PHP). Plugin path: `/var/www/html/public/local/caregivertraining` |
| `moodle-local-db-1` | Postgres 17. User and database `moodle`, table prefix `m_` |
| `mailpit` | Email capture, `http://localhost:8025` |

### A2. Environment variables and plugin settings

Set in the Moodle repository's `docker/local/local.env`. These **override** the plugin settings page:

| Variable | Purpose | Value for webhook testing |
| --- | --- | --- |
| `CAREGIVERTRAINING_ADAPTER_ENABLED` | Turns outbound webhook delivery on. **If set to `0`, the settings checkbox is ignored.** | `1` |
| `CAREGIVERTRAINING_ADAPTER_URL` | Webhook URL. Must be `https://`, or `http://` to `localhost` / `127.x.x.x` / `::1` only. | `http://127.0.0.1:9000/cgt` |
| `CAREGIVERTRAINING_ADAPTER_SECRET` | HMAC signing secret | `local-test-secret` |

After editing, recreate the container: `pnpm run local:up:plugin-dev`. Note that `pnpm run local:seed` refuses to
run while `CAREGIVERTRAINING_ADAPTER_ENABLED=1`, so seed first, then enable delivery.

Plugin settings (**Site administration → Plugins → Local plugins → Caregiver annual training → Settings**):

| Setting | Config name | Test value |
| --- | --- | --- |
| Annual course ID | `courseid` | Course id of the annual course (seed sets this) |
| Learner role | `roleid` | `0` = the default student role |
| Custom certificate course module ID | `certificatecmid` | cmid of the Custom certificate in that course. **Required**, or every cycle has the gate `certificate_not_configured`. |
| Time policy | `timepolicy` | `tracked` (default `unresolved` blocks completion) |
| Required seconds | `requiredseconds` | `18000` for the 5-hour tests; `600` for quick UI runs |
| Count every activity in the annual course | `countallactivities` | ticked (otherwise list cmids in `countablecmids`) |
| Maximum delivery attempts | `maxattempts` | `20` default; `3` for test B1c |

Find the course id from the course URL (`course/view.php?id=<COURSE_ID>`) and a cmid from an activity URL
(`mod/customcert/view.php?id=<CMID>`), or with SQL:

```bash
docker exec -i moodle-local-db-1 psql -U moodle moodle -c \
  "SELECT cm.id AS cmid, m.name AS module FROM m_course_modules cm JOIN m_modules m ON m.id = cm.module WHERE cm.course = <COURSE_ID>;"
```

### A3. Get a valid and an invalid API key

The "API key" is a Moodle web service token for the **Caregiver training adapter** service
(`local_caregivertraining_adapter`).

**Valid key (UI, recommended):**

1. **Site administration → Server → Web services → Manage tokens → Create token.**
2. User: `cgt-adapter-local` (created by the seed). Service: **Caregiver training adapter**. Optionally set
   "Valid until".
3. Save, then copy the token shown once. This is `<API_KEY>`.

**Valid key (seed file):** `docker compose --env-file docker/local/local.env exec -T moodle cat /var/www/moodledata/cgt-local/adapter-token`.
⚠️ If the database was restored after seeding, this file can hold a token that no longer exists (you get
`401 invalidtoken`). Use the UI method instead.

**Invalid keys for negative tests:**

| Key | How to get it | Expected |
| --- | --- | --- |
| Missing | Omit the `Authorization` header | `401 unauthorized` |
| Unknown | Any random string, e.g. `nottherealtoken` | `401 invalidtoken` |
| Wrong service | Create a token for a different service (e.g. *Moodle mobile web service*) | `403 wrongservice` |
| Expired | Create a token with "Valid until" in the past (or wait), or edit `m_external_tokens.validuntil` | `403 accessexception` |
| No capability | Temporarily remove `local/caregivertraining:adapterapi` from the `cgtadapter` role | `403 nopermissions` |

### A4. Shell variables used throughout

```bash
export BASE_URL="http://localhost:8080"                         # <BASE_URL>
export API_KEY="<API_KEY>"                                       # from A3
export API="$BASE_URL/local/caregivertraining/api.php"
sql() { docker exec -i moodle-local-db-1 psql -U moodle moodle -c "$1"; }
mcli() { docker exec -i moodle-local-moodle-1 php "$@"; }       # Moodle CLI inside the container
export ANNIV=$(date -d '+30 days' +%F)                           # anniversary 30 days ahead (window already open)
```

Git Bash on Windows: if a `docker exec` path gets rewritten (e.g. `/var/www` becomes `C:/Program Files/Git/var/www`),
prefix the command with `MSYS_NO_PATHCONV=1`.

Run scheduled tasks immediately instead of waiting:

```bash
mcli /var/www/html/admin/cli/scheduled_task.php --execute='\local_caregivertraining\task\process_cycles'      # every 15 min
mcli /var/www/html/admin/cli/scheduled_task.php --execute='\local_caregivertraining\task\deliver_outbox'      # every 5 min
mcli /var/www/html/admin/cli/scheduled_task.php --execute='\local_caregivertraining\task\send_notifications'  # :05 and :35
mcli /var/www/html/admin/cli/scheduled_task.php --execute='\core\task\completion_regular_task'                # course completion
```

### A5. Capture outgoing webhooks

**Option 1: local listener inside the Moodle container (no internet, verified working).** The plugin only accepts
`https://` or loopback URLs, so the listener must run inside the Moodle container on `127.0.0.1`.

1. Create `qa-hook.php` in the Moodle repository folder:

   ```php
   <?php
   $body = file_get_contents('php://input');
   file_put_contents('/tmp/cgt-webhooks.log', date('c') . ' ' . $_SERVER['REQUEST_METHOD'] . ' ' . $_SERVER['REQUEST_URI'] . "\n"
       . json_encode(getallheaders(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n" . $body . "\n\n", FILE_APPEND);
   $status = (int) (@file_get_contents('/tmp/cgt-webhook-status') ?: 200);
   http_response_code($status);
   header('Content-Type: application/json');
   echo json_encode(['received' => true, 'status' => $status]);
   ```

2. Copy it in and start it (repeat after every container restart):

   ```bash
   docker compose --env-file docker/local/local.env cp qa-hook.php moodle:/tmp/hook.php
   docker compose --env-file docker/local/local.env exec -d moodle php -S 127.0.0.1:9000 /tmp/hook.php
   ```

3. Set `CAREGIVERTRAINING_ADAPTER_URL=http://127.0.0.1:9000/cgt` (A2).
4. Read what was received: `docker exec moodle-local-moodle-1 cat /tmp/cgt-webhooks.log`
5. Simulate the receiver being down: `docker exec moodle-local-moodle-1 sh -c 'echo 503 > /tmp/cgt-webhook-status'`
   (back to normal: `echo 200`). To simulate "connection refused", stop it:
   `docker exec moodle-local-moodle-1 pkill -f 127.0.0.1:9000`.

**Option 2: webhook.site (needs internet; synthetic data only).** Open <https://webhook.site>, copy your unique
`https://webhook.site/<uuid>` URL into `CAREGIVERTRAINING_ADAPTER_URL`. To simulate failure, use **Edit → Default
status code = 500**.

`ngrok` is not needed: Moodle pushes outward, and an `http://host.docker.internal` URL is rejected by the plugin
(not https, not loopback).

### A6. Test data and SQL shortcuts

Test learners created in section C:

| AlayaCare ID | Name | Email | Payroll number |
| --- | --- | --- | --- |
| `QA-1001` | Quinn Tester | `qa.1001@example.com` | `PR-QA-1001` |
| `QA-1002` | Robin Tester | `qa.1002@example.com` | `PR-QA-1002` |

**Shortcut S1: give a cycle training time.** Tracked time is the sum of `creditedseconds` in
`m_local_cgt_timesession` for countable activities. `<CYCLE_PK>` is `m_local_cgt_cycle.id`; `<CMID>` is any countable
activity in the annual course.

```bash
sql "INSERT INTO m_local_cgt_timesession (cycleid, userid, cmid, sessiontoken, timestarted, timelastbeat, beats, rejectedbeats, creditedseconds)
     VALUES (<CYCLE_PK>, <MOODLE_ID>, <CMID>, md5(random()::text), extract(epoch FROM now())::int, extract(epoch FROM now())::int, 0, 0, <SECONDS>);"
```

To change the total later: `sql "UPDATE m_local_cgt_timesession SET creditedseconds = <SECONDS> WHERE cycleid = <CYCLE_PK>;"`
(if there is only one row).

**Shortcut S2: mark the course complete for a learner.**

```bash
sql "INSERT INTO m_course_completions (userid, course, timeenrolled, timestarted, timecompleted, reaggregate)
     VALUES (<MOODLE_ID>, <COURSE_ID>, 0, extract(epoch FROM now())::int, extract(epoch FROM now())::int, 0)
     ON CONFLICT (userid, course) DO UPDATE SET timecompleted = EXCLUDED.timecompleted;"
```

**Shortcut S3: re-evaluate a cycle now** (instead of waiting for the 15-minute task):

```bash
curl -s -X POST "$API/v1/cycles/<CYCLE_ID>/repair" -H "Authorization: Bearer $API_KEY"
```

The response lists unmet requirements in `gates`. Gate codes: `cycle_not_open`, `course_not_complete`,
`completion_predates_cycle`, `time_policy_unresolved`, `time_requirement_not_met`, `certificate_not_configured`.

Useful queries:

```bash
sql "SELECT id, cycleid, userid, status, anniversarydate, resetstate, approvedseconds, certificatecode FROM m_local_cgt_cycle ORDER BY id;"
sql "SELECT id, userid, alayacareid, payrollnumber, status FROM m_local_cgt_binding ORDER BY id;"
sql "SELECT id, cycleid, eventtype, eventid, status, attempts, to_timestamp(nextattempt) AS next, lasterror FROM m_local_cgt_outbox ORDER BY id;"
sql "SELECT id, type, alayacareid, status, details FROM m_local_cgt_exception ORDER BY id DESC LIMIT 10;"
```

---

## B. Webhooks

### B1a – Certificate (completion) webhook is sent with the learner and certificate details ⚠️ field names differ

**⚠️ Partially matches the requirement.** The plugin sends one signed `cycle.completed` event when a cycle completes
(course complete + time met + certificate issued). The requested data is present, under different names:

| Required | Sent as | Notes |
| --- | --- | --- |
| `alayacareId` | `data.alayacareid` | lower case |
| `moodleId` | `data.moodleuserid` | |
| `certificateUrl` | `data.certificate.verifyurl` | Public certificate **verification page**, not a direct PDF link |

Confirm with the adapter team whether these names are acceptable or need renaming.

**Preconditions:** A5 listener running; `CAREGIVERTRAINING_ADAPTER_ENABLED=1`, URL and secret set (A2);
`certificatecmid` configured; QA-1001 has a completed cycle (made in E3), or complete it now with S1 (18000) + S2 + S3.

**Steps:**

1. Confirm an event was queued:
   `sql "SELECT eventtype, eventid, status, attempts FROM m_local_cgt_outbox o JOIN m_local_cgt_cycle c ON c.id = o.cycleid WHERE c.cycleid = 'QA-1001-A';"`
2. Deliver it: `mcli /var/www/html/admin/cli/scheduled_task.php --execute='\local_caregivertraining\task\deliver_outbox'`
3. Read the receiver log: `docker exec moodle-local-moodle-1 cat /tmp/cgt-webhooks.log`

**Expected result:** One `POST /cgt` with these headers:

```text
Content-Type: application/json
User-Agent: MoodleBot/5.1 (+http://localhost:8080)
X-CGT-Event-Id: <uuid>
X-CGT-Event-Type: cycle.completed
X-CGT-Timestamp: <unix seconds>
X-CGT-Signature: v1=<64 hex chars>
```

Body:

```json
{
  "contract": "caregivertraining.v1",
  "type": "cycle.completed",
  "eventid": "<uuid, same as X-CGT-Event-Id>",
  "occurredat": "2026-10-09T06:30:00+00:00",
  "source": "http://localhost:8080/",
  "data": {
    "cycleid": "QA-1001-A",
    "alayacareid": "QA-1001",
    "payrollnumber": "PR-QA-1001",
    "moodleuserid": <MOODLE_ID>,
    "hiredate": "2024-03-15",
    "anniversarydate": "<ANNIV>",
    "dueyear": 2026,
    "completedat": "2026-10-08T23:30:00-07:00",
    "completeddate": "2026-10-08",
    "ontime": true,
    "approvedseconds": 18000,
    "timepolicy": "tracked",
    "certificate": {
      "code": "<certificate code>",
      "issueid": <issue id>,
      "verifyurl": "http://localhost:8080/mod/customcert/verify_certificate.php?code=<certificate code>"
    }
  }
}
```

`m_local_cgt_outbox` row: `status = delivered`, `attempts = 1`, `timedelivered` set.

**How to verify:**

- Receiver log (above) and the outbox query in A6.
- No `externalid`, `opendate` or `duedate` keys in the body.
- `GET /v1/cycles/QA-1001-A` → `"events": [{"eventtype": "cycle.completed", "status": "delivered", "attempts": 1, ...}]`.
- Open `verifyurl` in a browser: the Custom certificate verification page shows the certificate.
- Signature check (inside the container; replace the event id and timestamp from the headers):

  ```bash
  docker exec -i moodle-local-moodle-1 php -r 'define("CLI_SCRIPT", 1); require "/var/www/html/config.php";
    $r = $DB->get_record("local_cgt_outbox", ["eventid" => $argv[1]]);
    echo "v1=" . hash_hmac("sha256", $argv[2] . "." . $r->payload, getenv("CAREGIVERTRAINING_ADAPTER_SECRET")), "\n";' <EVENT_ID> <TIMESTAMP>
  ```

  The output must equal `X-CGT-Signature`.

`[ ] Pass  [ ] Fail  Notes: ____`

### B1b – Webhook receiver is down: retries and logging

**Preconditions:** As B1a, but use a learner whose cycle has **not** completed yet (QA-1002, after E1).

**Steps:**

1. Make the receiver fail: `docker exec moodle-local-moodle-1 sh -c 'echo 503 > /tmp/cgt-webhook-status'`
2. Complete QA-1002's cycle: S1 (18000) + S2 + S3 on `QA-1002-A`.
3. Run `deliver_outbox` (A4).
4. Check the outbox query (A6).
5. Speed up the retry: `sql "UPDATE m_local_cgt_outbox SET nextattempt = 0 WHERE status = 'pending';"`, run
   `deliver_outbox` again, check again.
6. Stop the listener (`pkill`, A5), reset `nextattempt = 0`, run `deliver_outbox` again.

**Expected result:**

| After | `status` | `attempts` | `lasterror` | `nextattempt` |
| --- | --- | --- | --- | --- |
| Step 3 | `pending` | 1 | `HTTP 503` | now + 2 min |
| Step 5 | `pending` | 2 | `HTTP 503` | now + 4 min |
| Step 6 | `pending` | 3 | exception class, e.g. `GuzzleHttp\Exception\ConnectException` | now + 8 min |

Backoff is `min(6 hours, 60 s × 2^attempts)`: 2, 4, 8, 16 … minutes, capped at 6 hours. The completion itself is not
affected: the cycle stays `completed`, and the learner sees "Completed on …".

**How to verify:** outbox query; receiver log shows each attempt with the **same** `X-CGT-Event-Id` and a new
`X-CGT-Timestamp`; `GET /v1/health` → `"outbox": {"pending": 1, "failed": 0}`.

`[ ] Pass  [ ] Fail  Notes: ____`

### B1c – Retries exhausted, then recovered

**Steps:**

1. Set **Maximum delivery attempts** to `3` in the settings.
2. Continuing from B1b, reset `nextattempt = 0` and run `deliver_outbox` until `status` changes.
3. Open **Site administration → Plugins → Local plugins → Caregiver annual training → Caregiver training**,
   tab **Identity and data exceptions**.
4. Restart the listener with status 200 (A5), then call `curl -s -X POST "$API/v1/cycles/QA-1002-A/repair" -H "Authorization: Bearer $API_KEY"`.
5. Run `deliver_outbox`.

**Expected result:**

- Step 2: `status = failed`; `GET /v1/health` → `"outbox": {"pending": 0, "failed": 1}`.
- Step 3: an open exception of type `outbox_failed` with the event id and last error.
- Step 4: the event is re-queued with the **same** event id: `status = pending`, `attempts = 0`.
- Step 5: `status = delivered`; one more POST in the receiver log with the same `X-CGT-Event-Id`.
- A receiver answering `409` is also treated as delivered (already processed).

**How to verify:** outbox query, exceptions query, receiver log. Reset **Maximum delivery attempts** to `20` afterwards.

`[ ] Pass  [ ] Fail  Notes: ____`

### B2 – Annual training status webhook (in progress, completed, …) ⚠️ Not implemented

**⚠️ Not implemented: expected behaviour TBD.** Only `cycle.completed` is pushed. There are no webhooks for
"scheduled", "open/in progress", "overdue", "blocked" or "superseded". The adapter can instead **poll** status:

**Steps (pull alternative that does exist):**

```bash
curl -s "$API/v1/cycles?modifiedsince=0&limit=100" -H "Authorization: Bearer $API_KEY"
```

**Expected result:** `200`

```json
{"count": 2, "items": [{"cycleid": "QA-1001-A", "status": "completed", "compliance": "complete", "...": "...", "gates": []}],
 "servertime": 1791527053}
```

Status values: `status` = `scheduled`, `open`, `blocked`, `completed`, `superseded`; `compliance` = `upcoming`, `open`,
`overdue`, `complete`, `notapplicable`. Send the previous `servertime` as the next `modifiedsince` to get only changes.

**How to verify:** each test learner appears with the status shown in the admin view's **Current cycles** tab.
Decide with the adapter team whether polling is acceptable or a status webhook must be built.

`[ ] Pass  [ ] Fail  Notes: ____`

---

## C. Provision endpoint (`PUT /v1/learners/{alayacareid}`)

All requests need `Authorization: Bearer <API_KEY>`. Every `PUT` also needs an `Idempotency-Key` header
(1–128 characters of `[A-Za-z0-9._:-]`). Use a new key for each new request.

### C1 – New student is created ⚠️ enrolment happens at cycle creation

**Preconditions:** `QA-1001` and `qa.1001@example.com` don't exist yet
(`sql "SELECT id FROM m_user WHERE email = 'qa.1001@example.com';"` → 0 rows).

**Steps:**

```bash
curl -s -i -X PUT "$API/v1/learners/QA-1001" \
  -H "Authorization: Bearer $API_KEY" -H "Idempotency-Key: qa-prov-1001-1" -H "Content-Type: application/json" \
  -d '{"email": "qa.1001@example.com", "firstname": "Quinn", "lastname": "Tester",
       "payrollnumber": "PR-QA-1001", "hcanumber": "HCA-QA-1001", "registrationdate": "2024-03-01",
       "createifmissing": true, "sendactivation": true}'
```

**Expected result:** `201 Created`, header `Location: http://localhost:8080/local/caregivertraining/api.php/v1/learners/QA-1001`

```json
{"status": "created", "userid": <MOODLE_ID>, "bindingid": <id>, "activation": "queued", "replayed": false}
```

Write `<MOODLE_ID>` down.

**How to verify:**

- `sql "SELECT id, username, auth, email FROM m_user WHERE email = 'qa.1001@example.com';"` → one row, username
  `qa.1001@example.com`, auth `manual`.
- `sql "SELECT userid, alayacareid, payrollnumber, status FROM m_local_cgt_binding WHERE alayacareid = 'QA-1001';"` →
  `PR-QA-1001`, `active`.
- `curl -s "$API/v1/learners/QA-1001" -H "Authorization: Bearer $API_KEY"` → `200` with `payrollnumber`,
  `hcanumber`, `registrationdate`.
- UI: the user's profile shows category **Caregiver employment (managed by AlayaCare integration)** with
  **AlayaCare ID**, **Payroll number**, **HCA number**, **HCA registration date** (not editable by the learner).
- After `mcli /var/www/html/admin/cli/cron.php`, Mailpit has "set up your training account" for `qa.1001@example.com`.
- ⚠️ **Enrolment:** provisioning alone does **not** enrol the learner. Enrolment in the annual course is created by the
  cycle (D1). Check: `sql "SELECT COUNT(*) FROM m_user_enrolments ue JOIN m_enrol e ON e.id = ue.enrolid WHERE ue.userid = <MOODLE_ID> AND e.courseid = <COURSE_ID>;"` → `0` now, `1` after D1.

`[ ] Pass  [ ] Fail  Notes: ____`

### C2 – Duplicate student: idempotent, never a second account

**Implemented behaviour:** idempotent `200` for an already-provisioned AlayaCare id; `409` only for conflicting
identities.

**Steps and expected results:**

| # | Request | Expected |
| --- | --- | --- |
| a | Repeat C1 exactly (same key `qa-prov-1001-1`) | `201`, same body with `"replayed": true` |
| b | Same key, different body (e.g. `"firstname": "Q"`) | `409` `idempotencyconflict` |
| c | Repeat C1 body with a new key `qa-prov-1001-2` | `200` `{"status": "unchanged", "userid": <MOODLE_ID>, ...}` |
| d | New key, change `firstname` to `"Quinny"` | `200` `"status": "updated"` |
| e | `PUT /v1/learners/QA-9999` with `email: qa.1001@example.com`, `createifmissing: true` | `409` `existingaccount` |
| f | `PUT /v1/learners/QA-9998` with `payrollnumber: PR-QA-1001` | `409` `bindingconflict`, message ends `payrollnumber is bound to another employee` |

Example (c):

```bash
curl -s -i -X PUT "$API/v1/learners/QA-1001" \
  -H "Authorization: Bearer $API_KEY" -H "Idempotency-Key: qa-prov-1001-2" -H "Content-Type: application/json" \
  -d '{"email": "qa.1001@example.com", "firstname": "Quinn", "lastname": "Tester",
       "payrollnumber": "PR-QA-1001", "hcanumber": "HCA-QA-1001", "registrationdate": "2024-03-01",
       "createifmissing": true, "sendactivation": true}'
```

Example error body (e):

```json
{"code": 409, "error": "existingaccount",
 "message": "Moodle account <MOODLE_ID> already uses this email. Confirm it is the same person and call again with userid=<MOODLE_ID>."}
```

**How to verify:** `sql "SELECT COUNT(*) FROM m_user WHERE email = 'qa.1001@example.com' AND deleted = 0;"` → `1`
after all steps; `sql "SELECT COUNT(*) FROM m_local_cgt_binding WHERE alayacareid = 'QA-1001';"` → `1`. Conflicts
(e, f) appear on the **Identity and data exceptions** tab.

`[ ] Pass  [ ] Fail  Notes: ____`

### C3 – AlayaCare ID not found

The plugin never calls AlayaCare, so "not found" means no Moodle learner is bound to that id.

**Steps:**

```bash
curl -s -i "$API/v1/learners/NOPE-999" -H "Authorization: Bearer $API_KEY"
curl -s -i "$API/v1/learners?alayacareid=NOPE-999" -H "Authorization: Bearer $API_KEY"
curl -s -i -X PUT "$API/v1/learners/NOPE-999" \
  -H "Authorization: Bearer $API_KEY" -H "Idempotency-Key: qa-nf-1" -H "Content-Type: application/json" \
  -d '{"email": "x@example.com", "firstname": "X", "lastname": "Y"}'
```

**Expected result:**

1. `404`
   ```json
   {"code": 404, "error": "learnernotfound", "message": "No Moodle learner is bound to AlayaCare id NOPE-999."}
   ```
2. `200` (a search with no hits is not an error)
   ```json
   {"status": "none", "count": 0, "items": [], "candidates": []}
   ```
3. `404` (no `createifmissing`, so nothing is created)
   ```json
   {"code": 404, "error": "learnernotfound", "message": "No Moodle learner is bound to AlayaCare id NOPE-999.",
    "details": "Send \"createifmissing\": true with email, firstname and lastname to create the learner, or \"userid\" to link an existing account."}
   ```

**How to verify:** `sql "SELECT COUNT(*) FROM m_user WHERE email = 'x@example.com';"` → `0`. An employee missing in
AlayaCare itself must be caught by the adapter.

`[ ] Pass  [ ] Fail  Notes: ____`

### C4 – Missing or invalid API key

**Steps:**

```bash
curl -s -i "$API/v1/health"                                                     # missing
curl -s -i "$API/v1/health" -H "Authorization: Bearer nottherealtoken"          # unknown
curl -s -i "$API/v1/health" -H "Authorization: Bearer <OTHER_SERVICE_TOKEN>"    # wrong service (A3)
curl -s -i "$API/v1/health?wstoken=$API_KEY"                                    # token in URL is not accepted
```

**Expected result:**

1. `401`, header `WWW-Authenticate: Bearer`
   ```json
   {"code": 401, "error": "unauthorized", "message": "Send the web service token as \"Authorization: Bearer <token>\"."}
   ```
2. `401`, header `WWW-Authenticate: Bearer error="invalid_token"`
   ```json
   {"code": 401, "error": "invalidtoken", "message": "Invalid token - token not found"}
   ```
3. `403` `{"code": 403, "error": "wrongservice", "message": "This token belongs to a different web service. Create a token for the Caregiver training adapter service."}`
4. `401` `unauthorized` (same as 1)

Also: expired token → `403 accessexception`; token user without `local/caregivertraining:adapterapi` → `403 nopermissions`.

**How to verify:** status line of each `curl -i`; nothing changes in the database.

`[ ] Pass  [ ] Fail  Notes: ____`

### C5 – Missing or invalid body fields

**Steps and expected results** (all `400`; every body has `"code": 400`):

| # | Request | `error` | `details` / `message` |
| --- | --- | --- | --- |
| a | `PUT` with no `Idempotency-Key` header | `invalidparameter` | `Send a unique Idempotency-Key header (1-128 of [A-Za-z0-9._:-]) with every PUT request.` |
| b | `"createifmissing": true` with only `email` | `invalidparameter` | `email, firstname and lastname are required to create a learner` |
| c | URL id `bad%20id!` | `invalidparameter` | `alayacareid must be 1-64 characters of [A-Za-z0-9._-]` |
| d | `"email": "not-an-email"` with `createifmissing` | `invalidemail` | message `Invalid email address.` |
| e | Old fields `{"externalid": "E1", "payrollid": "P1"}` | `invalidparameter` | `Unexpected field(s): externalid, payrollid. Allowed: alayacareid, userid, email, firstname, lastname, payrollnumber, hcanumber, registrationdate, createifmissing, sendactivation.` |
| f | Body `{bad` | `invalidparameter` | `The request body is not valid JSON: Syntax error` |
| g | `"registrationdate": "2024-02-30"` | `invaliddate` | message `Invalid date "2024-02-30". Use YYYY-MM-DD.` |

`message` is `Invalid parameter value detected` for every `invalidparameter` row.

Example (b):

```bash
curl -s -i -X PUT "$API/v1/learners/NOPE-999" \
  -H "Authorization: Bearer $API_KEY" -H "Idempotency-Key: qa-mf-1" -H "Content-Type: application/json" \
  -d '{"createifmissing": true, "email": "x@example.com"}'
```

```json
{"code": 400, "error": "invalidparameter", "message": "Invalid parameter value detected",
 "details": "email, firstname and lastname are required to create a learner"}
```

**How to verify:** no new `m_user` or `m_local_cgt_binding` rows. Errors are not stored against the idempotency key,
so the same key can be reused once the request is fixed.

`[ ] Pass  [ ] Fail  Notes: ____`

### C6 – Documentation matches behaviour ⚠️ no hosted Swagger UI

**Where:** [docs/api-endpoints.md](docs/api-endpoints.md) (sections *PUT /v1/learners/{alayacareid}* and scenarios
1–3) and [docs/openapi.yaml](docs/openapi.yaml). ⚠️ There is no Swagger URL served by Moodle. To browse it, paste
`openapi.yaml` into <https://editor.swagger.io>.

| Check | Doc says | Matches C1–C5? |
| --- | --- | --- |
| Route and method | `PUT /v1/learners/{alayacareid}` | [ ] |
| Auth | `Authorization: Bearer {token}` | [ ] |
| `Idempotency-Key` required on PUT | Yes | [ ] |
| New learner | `201` + `"status": "created"` + `Location` | [ ] |
| Duplicate | `200` `unchanged`/`updated`; `409` `existingaccount`/`bindingconflict`/`idempotencyconflict` | [ ] |
| Not found | `404 learnernotfound` with hint | [ ] |
| Bad input | `400 invalidparameter` with `details` | [ ] |
| Field names | `payrollnumber`, no `externalid`/`payrollid` | [ ] |
| OpenAPI `ProvisionLearner` schema lists the same body fields | | [ ] |

`[ ] Pass  [ ] Fail  Notes: ____`

---

## D. Start cycle endpoint (`PUT /v1/cycles/{cycleid}`)

⚠️ **Inputs differ from "Moodle ID + API key":** the adapter supplies the Cycle ID in the URL and sends `userid`
(the Moodle ID), `alayacareid` and `anniversarydate` (the due date). The plugin derives the window: opens 60 days
before the anniversary, due at 23:59:59 on it, access ends 14 days after it (America/Los_Angeles).

### D1 – Successful start creates a student-owned cycle

**Preconditions:** QA-1001 provisioned (C1); no cycle yet. `ANNIV` set (A4).

**Steps:**

```bash
curl -s -i -X PUT "$API/v1/cycles/QA-1001-A" \
  -H "Authorization: Bearer $API_KEY" -H "Idempotency-Key: qa-cyc-1001-a" -H "Content-Type: application/json" \
  -d "{\"userid\": <MOODLE_ID>, \"alayacareid\": \"QA-1001\", \"hiredate\": \"2024-03-15\", \"anniversarydate\": \"$ANNIV\"}"
```

**Expected result:** `201 Created`, `Location: …/api.php/v1/cycles/QA-1001-A`

```json
{
  "action": "created",
  "cycle": {
    "cycleid": "QA-1001-A", "userid": <MOODLE_ID>, "alayacareid": "QA-1001",
    "status": "open", "compliance": "open",
    "hiredate": "2024-03-15", "anniversarydate": "<ANNIV>",
    "timeopen": <unix>, "timedue": <unix>, "timeaccessend": <unix>,
    "employmentstatus": "active", "enrolmentstatus": "active", "remindersenabled": true,
    "resetstate": "notrequired", "blockedreason": "",
    "timecompleted": 0, "approvedseconds": 0, "timepolicy": "", "certificatecode": "", "events": []
  },
  "replayed": false
}
```

`status` is `open` because the window (anniversary − 60 days) has already started. An anniversary more than 60 days
away gives `"status": "scheduled"` and `"compliance": "upcoming"`.

**How to verify:**

- `sql "SELECT id, cycleid, userid, courseid, status, anniversarydate, to_timestamp(timeopen), to_timestamp(timedue), to_timestamp(timeaccessend) FROM m_local_cgt_cycle WHERE cycleid = 'QA-1001-A';"`
  → one row owned by `<MOODLE_ID>`. Note its `id` as `<CYCLE_PK>`.
- Enrolment created: the query from C1 returns `1`; `m_user_enrolments.timeend` equals `timeaccessend`.
- Log in as Quinn (set a password as admin first): the course shows the banner "Due …· 30 days remaining".
- Response has no `opendate` or `duedate` keys.

`[ ] Pass  [ ] Fail  Notes: ____`

### D2 – Cycle already started or in progress: no duplicate

**Preconditions:** D1 done.

**Steps and expected results:**

| # | Request | Expected |
| --- | --- | --- |
| a | Repeat D1 exactly (same key) | `201`, same body, `"replayed": true` |
| b | Same `cycleid` `QA-1001-A`, same body, new key `qa-cyc-1001-a2` | `200` `"action": "unchanged"`, `status` still `open` |
| c | **New** `cycleid` `QA-1001-DUP`, same `anniversarydate` | `409` `cycleconflict`: `Cycle conflict: cycle QA-1001-A already covers anniversary date <ANNIV>` |
| d | New `cycleid` `QA-1001-NEXT`, different anniversary (e.g. +1 year) | `409` `cycleconflict`: `Cycle conflict: learner has active cycle QA-1001-A; send supersedescycleid to replace it` |

Example (c):

```bash
curl -s -i -X PUT "$API/v1/cycles/QA-1001-DUP" \
  -H "Authorization: Bearer $API_KEY" -H "Idempotency-Key: qa-cyc-1001-dup" -H "Content-Type: application/json" \
  -d "{\"userid\": <MOODLE_ID>, \"alayacareid\": \"QA-1001\", \"anniversarydate\": \"$ANNIV\"}"
```

```json
{"code": 409, "error": "cycleconflict", "message": "Cycle conflict: cycle QA-1001-A already covers anniversary date <ANNIV>"}
```

**How to verify:** `sql "SELECT COUNT(*) FROM m_local_cgt_cycle WHERE userid = <MOODLE_ID> AND status IN ('scheduled','open','blocked');"` →
`1`. Each 409 also appears on the **Identity and data exceptions** tab.

`[ ] Pass  [ ] Fail  Notes: ____`

### D3 – Student not found

**Steps:**

```bash
curl -s -i -X PUT "$API/v1/cycles/QA-NOPE-1" \
  -H "Authorization: Bearer $API_KEY" -H "Idempotency-Key: qa-cyc-nope" -H "Content-Type: application/json" \
  -d '{"userid": 999999, "alayacareid": "NOPE-999", "anniversarydate": "2027-03-15"}'
curl -s -i "$API/v1/cycles/QA-NOPE-1" -H "Authorization: Bearer $API_KEY"
```

**Expected result:**

1. `404` `{"code": 404, "error": "bindingmismatch", "message": "The Moodle user and AlayaCare employee do not match an existing binding."}`
   The same happens when the AlayaCare id exists but belongs to a different `userid`. A bound user who was deleted
   gives `404 usernotfound`.
2. `404` `{"code": 404, "error": "cyclenotfound", "message": "No cycle has Cycle ID QA-NOPE-1."}`

**How to verify:** `sql "SELECT COUNT(*) FROM m_local_cgt_cycle WHERE cycleid = 'QA-NOPE-1';"` → `0`.

`[ ] Pass  [ ] Fail  Notes: ____`

### D4 – Annual training already finished: blocked

**Preconditions:** QA-1001's cycle `QA-1001-A` is `completed` (E3).

**Steps and expected results:**

| # | Request | Expected |
| --- | --- | --- |
| a | `GET /v1/cycles/QA-1001-A` | `200` `"status": "completed"`, `"compliance": "complete"`, `timecompleted` > 0, `certificatecode` set, `"gates": []` |
| b | `PUT` same `cycleid`, same `anniversarydate`, new key | `200` `"action": "unchanged"` |
| c | `PUT` same `cycleid`, different `anniversarydate` | `409` `cycleimmutable`: `Cycle QA-1001-A is final and cannot be changed.` |
| d | `PUT` **new** `cycleid` `QA-1001-AGAIN`, same `anniversarydate` | `409` `alreadycompleted` (below) |
| e | `PUT` new `cycleid` `QA-1001-B`, anniversary 45 days ahead | `201` `"action": "created"` (used again in E2) |

Example (d):

```bash
curl -s -i -X PUT "$API/v1/cycles/QA-1001-AGAIN" \
  -H "Authorization: Bearer $API_KEY" -H "Idempotency-Key: qa-cyc-1001-again" -H "Content-Type: application/json" \
  -d "{\"userid\": <MOODLE_ID>, \"alayacareid\": \"QA-1001\", \"anniversarydate\": \"$ANNIV\"}"
```

```json
{"code": 409, "error": "alreadycompleted",
 "message": "The learner already completed the annual training due <ANNIV> in cycle QA-1001-A. A new cycle needs a different anniversary date."}
```

**How to verify:** no `QA-1001-AGAIN` row in `m_local_cgt_cycle`; Quinn's completed activities and certificate are
still present (no reset happened).

`[ ] Pass  [ ] Fail  Notes: ____`

### D5 – Missing or invalid API key, and bad input

**Steps:** repeat the C4 calls against `PUT "$API/v1/cycles/QA-1001-A"`, then:

| # | Request | Expected |
| --- | --- | --- |
| a | No `Authorization` header | `401 unauthorized` |
| b | `Bearer nottherealtoken` | `401 invalidtoken` |
| c | Body without `anniversarydate` | `400 invalidparameter`, `details`: `Missing required key in single structure: anniversarydate` |
| d | Old fields `opendate`/`duedate` instead of `anniversarydate` | `400 invalidparameter`, `details`: `Unexpected field(s): opendate, duedate. Allowed: cycleid, userid, alayacareid, hiredate, anniversarydate, supersedescycleid.` |
| e | `"anniversarydate": "2027-02-30"` | `400 invaliddate` |
| f | `DELETE "$API/v1/cycles/QA-1001-A"` | `405 methodnotallowed`, header `Allow: GET, PUT` |
| g | `GET "$API/v1/start-cycle"` | `404 routenotfound`: `No endpoint matches GET /v1/start-cycle.` |

**How to verify:** status codes; no cycle changes in `m_local_cgt_cycle`.

`[ ] Pass  [ ] Fail  Notes: ____`

### D6 – Documentation matches behaviour ⚠️ no hosted Swagger UI

**Where:** [docs/api-endpoints.md](docs/api-endpoints.md) (*PUT /v1/cycles/{cycleid}*, *v1_upsert_cycle*, scenarios
4–6) and [docs/openapi.yaml](docs/openapi.yaml) (`upsertCycle`).

| Check | Doc says | Matches D1–D5? |
| --- | --- | --- |
| Route and body fields | `PUT /v1/cycles/{cycleid}`: `userid`, `alayacareid`, `hiredate`, `anniversarydate`, `supersedescycleid` | [ ] |
| Window rule | opens −60 days, due on anniversary, access ends +14 days | [ ] |
| Created / unchanged | `201 created`, `200 unchanged`/`updated` | [ ] |
| In progress | `409 cycleconflict` (two messages) | [ ] |
| Not found | `404 bindingmismatch`, `404 cyclenotfound` | [ ] |
| Already finished | `409 alreadycompleted`, `409 cycleimmutable` | [ ] |
| No `opendate`/`duedate` in request or response | | [ ] |

`[ ] Pass  [ ] Fail  Notes: ____`

---

## E. Annual training features (UI and logic)

Learner pages: log in as the test learner in a private window. Admin pages: **Site administration → Plugins →
Local plugins → Caregiver annual training → Caregiver training** (`$BASE_URL/local/caregivertraining/index.php`).

### E1 – Student-owned cycles are independent

**Preconditions:** Provision QA-1002 (C1 with `QA-1002`, `qa.1002@example.com`, `PR-QA-1002`, key `qa-prov-1002-1`),
then create cycle `QA-1002-A` (D1 with its own `userid`, key `qa-cyc-1002-a`). Set passwords for both users.

**Steps:**

1. Give QA-1001 600 seconds with S1 (QA-1001's `<CYCLE_PK>`). Leave QA-1002 at 0.
2. Log in as Quinn, open an activity in the annual course; then as Robin in another private window.
3. As Robin, mark one activity done. As Quinn, reload.

**Expected result:** each banner shows only that learner's own due date and minutes (Quinn: 10 mins, Robin: 0 mins).
Robin's activity completion doesn't change Quinn's course page.

**How to verify:** `sql "SELECT cycleid, userid, status, approvedseconds FROM m_local_cgt_cycle WHERE cycleid LIKE 'QA-%';"` → one
row per learner; `sql "SELECT cycleid, userid, SUM(creditedseconds) FROM m_local_cgt_timesession GROUP BY cycleid, userid;"`
→ time is per cycle and user. Learners have no link to the admin view (it requires `local/caregivertraining:viewreports`).

`[ ] Pass  [ ] Fail  Notes: ____`

### E2 – Snapshot / history dashboard

**Where:** admin view, tab **Prior-cycle snapshots** (`index.php?tab=snapshots`). Other tabs: **Identity and data
exceptions**, **Blocked resets**, **Current cycles**.

**Preconditions:** QA-1001's first cycle completed (E3) and the next cycle `QA-1001-B` created (D4e).

**Steps:**

1. Open **Prior-cycle snapshots**.
2. Open **Current cycles**; tick **Show archived** and untick it.
3. As Quinn, open the course.

**Expected result:**

- Snapshots tab columns: **Employee**, **Cycle ID**, **Type**, **Created**, **Verified**, **SHA-256**,
  **Certificate** (download link to the preserved certificate PDF).
- QA-1001 has a `completion` snapshot (taken when `QA-1001-A` completed) and a `prereset` snapshot (taken when
  `QA-1001-B` started, before clearing progress). Both are against Cycle ID `QA-1001-A`, `Verified` = yes.
- Current cycles columns: **Employee**, **AlayaCare ID**, **Payroll number**, **HCA number**, **HCA registration
  date**, **Cycle ID**, **Status**, **Compliance**, **Due (anniversary)**, **Access ends**, **Completed**,
  **Approved time**, **Certificate**, **Actions** (Archive for completed cycles).
- Both `QA-1001-A` (completed, with certificate code) and `QA-1001-B` (open) are listed.
- Quinn's course progress is cleared for `QA-1001-B`, and the banner shows the new due date.

**How to verify:** `sql "SELECT s.id, c.cycleid, s.type, s.verified, to_timestamp(s.retainuntil)::date FROM m_local_cgt_snapshot s JOIN m_local_cgt_cycle c ON c.id = s.cycleid ORDER BY s.id;"`
(`retainuntil` is 3 years ahead); `sql "SELECT cycleid, resetstate FROM m_local_cgt_cycle WHERE userid = <MOODLE_ID>;"` →
`QA-1001-A` = `done`.

`[ ] Pass  [ ] Fail  Notes: ____`

### E3 – Minimum 5 hours before completion

**Preconditions:** settings `timepolicy = tracked`, `requiredseconds = 18000`, `countallactivities` ticked,
`certificatecmid` set. QA-1001 has open cycle `QA-1001-A` (`<CYCLE_PK>`), no time yet.

**How to speed up time:** don't wait 5 hours. Seed the time with S1, mark the course complete with S2, and
re-evaluate with S3.

**Steps:**

1. S2: mark the course complete for QA-1001.
2. S1 with `<SECONDS>` = `17999` (4 h 59 min 59 s). Run S3 on `QA-1001-A`.
3. Update the time to `18000` (exactly 5 h). Run S3.
4. (On QA-1002, which has its own cycle:) S2, S1 with `18001`, S3 on `QA-1002-A`. Skip this if you are keeping
   QA-1002 incomplete for B1b; do B1b first in that case.
5. Variant: set **Time policy** to *Unresolved*, then S3 on an open cycle.

**Expected result:**

| Step | Approved | `status` | `gates` | Banner |
| --- | --- | --- | --- | --- |
| 2 | 17999 | `open` | `["time_requirement_not_met"]` | "4 hrs 59 mins completed of 5 hours · 1 min remaining · About 99%" |
| 3 | 18000 | `completed` | `[]` | "Completed on <date>" |
| 4 | 18001 | `completed` | `[]` | "Completed on <date>" |
| 5 | n/a | `open` | includes `time_policy_unresolved` | "Recorded activity time: … completion is on hold." |

At completion: `approvedseconds` and `timepolicy` are frozen on the cycle, a certificate is issued, a `completion`
snapshot is taken, a completion email is queued, and one `cycle.completed` outbox event is created.

**How to verify:** S3 response (`status`, `gates`, `approvedseconds`, `certificatecode`); outbox query (A6); Mailpit
"Annual caregiver training completed" after `send_notifications`. Real-time path (optional): set
`requiredseconds = 600` and work in the course for 10 minutes (E4), then run `completion_regular_task` and
`process_cycles` (the course needs **Course completion** criteria set to the activities).

`[ ] Pass  [ ] Fail  Notes: ____`

### E4 – Time tracker UI

**Preconditions:** `requiredseconds = 600`, a learner with an open cycle and less than 10 minutes.

**Steps:**

1. Open an **activity** page in the annual course (time is tracked only on activity pages, not the course page).
   Open browser DevTools → Network and filter on `record_heartbeat`.
2. Interact (scroll, click) for 2 minutes.
3. Switch to another browser tab for 1 minute, then come back.
4. Leave the page untouched for 3 minutes (no video playing).
5. Reload the page; navigate to another activity.
6. Open the same activity in two tabs at once and stay active in both.
7. Keep going until 10 minutes are reached.

**Expected result:**

- Banner line: `<approved> completed of 10 mins · <remaining> remaining · About <n>%` with a round progress chart.
  Remaining minutes round **up**; completed never exceeds the requirement.
- A heartbeat request every **30 s** (`heartbeatseconds`); the banner updates after each one without reloading.
- Step 3: beats while hidden earn nothing (`reason: "hidden"`); a beat is sent on return.
- Step 4: after **120 s** without interaction (`idleseconds`), beats return `reason: "idle"` and time stops.
- Step 5: the first beat on a new page returns `session_started` and earns nothing, so a reload costs up to one
  interval. Time already earned is kept.
- Step 6: only one tab earns credit (`reason: "concurrent"` on the other); no double counting.
- Step 7: at 10 minutes the beat returns `requirement_met`, and the page stops sending heartbeats.

**How to verify:** heartbeat responses in DevTools (`credited`, `creditseconds`, `reason`, `timeline`);
`sql "SELECT cmid, beats, rejectedbeats, lastrejectreason, creditedseconds FROM m_local_cgt_timesession WHERE userid = <MOODLE_ID> ORDER BY id;"`.

`[ ] Pass  [ ] Fail  Notes: ____`

### E5 – Next activity button

**Preconditions:** learner with an **open** or **completed** cycle; activities use completion tracking. For the
locked case, add an access restriction to activity 2 ("Activity completion: activity 1 must be complete").

**Steps:**

1. Course page: look under the content.
2. Activity 1 (not done): click **Next activity**.
3. With the restriction on activity 2 and activity 1 not done: view activity 1.
4. Mark everything done; view the course page and the last activity.
5. Leave activity 1 unfinished, mark the last activity done, and view the last activity.
6. Start a quiz attempt.

**Expected result:**

1. A blue **Next activity** button that leads to the first unfinished, available activity
   (`/local/caregivertraining/next.php?courseid=<COURSE_ID>`).
2. Goes to the next unfinished activity **after** the current one in course order (`next.php?courseid=…&cmid=…`).
3. No button; text "Complete this activity to unlock the next one."
4. Course page and last activity page: "All available activities are complete."
5. A **Next activity** button that goes back to activity 1 (`next.php?courseid=<COURSE_ID>`, no `cmid`).
6. No button on the quiz attempt and summary pages. No button at all for learners whose cycle is scheduled or blocked.

**How to verify:** the URL after clicking; activity completion on the course page.

`[ ] Pass  [ ] Fail  Notes: ____`

### E6 – Export to CSV

**Where:** admin view, the form at the top of every tab: text box "Filter by AlayaCare ID, payroll number, HCA
number or Cycle ID" and button **Export CSV**. Requires `local/caregivertraining:export`.

**Steps:**

1. Leave the filter empty, click **Export CSV**.
2. Export with filter `QA-1001`, then `PR-QA-1002`, then `HCA-QA-1001`, then `QA-1001-A`, then `QA-10` (partial).

**Expected result:**

- File name: `caregiver-training-YYYYMMDD-HHMMSS.csv`.
- Header row (26 columns, in this order):

  ```text
  alayacareid,payrollnumber,hcanumber,registrationdate,moodleuserid,fullname,cycleid,status,compliance,hiredate,anniversarydate,accessenddate,timecompleted,approvedseconds,timepolicy,certificatecode,resetstate,archived,snapshotid,snapshottype,snapshotverified,snapshotsha256,snapshotcreated,snapshotretainuntil,snapshotcounts,certificatefiles
  ```

- Sample row (completed cycle with a completion snapshot):

  ```text
  QA-1001,PR-QA-1001,HCA-QA-1001,2024-03-01,<MOODLE_ID>,Quinn Tester,QA-1001-A,completed,complete,2024-03-15,<ANNIV>,<ANNIV+14>,2026-10-08T23:30:00-07:00,18000,tracked,<code>,done,0,<id>,completion,1,<sha256>,2026-10-08T23:30:05-07:00,2029-10-08,"{...}",<code>:<pdf sha256>
  ```

- One row per snapshot; a cycle without snapshots gives one row with the last 8 columns empty.
- Filters are **exact** matches on AlayaCare ID, payroll number, HCA number, Cycle ID or certificate code. `QA-1001`
  → only Quinn's rows; `PR-QA-1002` → only Robin's; `QA-10` → **no rows** (no partial matching).

**How to verify:** open in Excel; compare with **Current cycles** and **Prior-cycle snapshots**. Each export logs an
`evidence_exported` event (**Site administration → Reports → Logs**) with the filter and row count.

`[ ] Pass  [ ] Fail  Notes: ____`

---

## F. Field changes (regression checks)

### F1 – `externalId` removed

**Steps and expected results:**

| Where | Check | Expected |
| --- | --- | --- |
| API response | `curl -s "$API/v1/learners/QA-1001" -H "Authorization: Bearer $API_KEY"` | Keys: `bindingid`, `userid`, `alayacareid`, `payrollnumber`, `hcanumber`, `registrationdate`, `status`. No `externalid`. |
| API request | C5 (e) | `400`, `Unexpected field(s): externalid, …` |
| Webhook | B1a body | No `externalid` |
| UI | User profile, admin view | No "External ID" |
| CSV | E6 header | No `externalid` column |
| DB | `sql "SELECT column_name FROM information_schema.columns WHERE table_name = 'm_local_cgt_binding';"` | No `externalid` |
| Profile fields | `sql "SELECT shortname FROM m_user_info_field WHERE shortname LIKE 'cgt_%';"` | `cgt_alayacareid`, `cgt_payrollnumber`, `cgt_hcanumber`, `cgt_hcaregistrationdate`; no `cgt_alayacare_externalid` |
| Docs | grep below | Only the upgrade step and tests mention it |

`[ ] Pass  [ ] Fail  Notes: ____`

### F2 – `payrollId` renamed to `payrollNumber`

The implemented name is all lower case: `payrollnumber`.

| Where | Check | Expected |
| --- | --- | --- |
| API | `GET /v1/learners/QA-1001`; `GET /v1/learners?payrollnumber=PR-QA-1001` | `"payrollnumber": "PR-QA-1001"`; search returns `"matchedby": "payrollnumber"` |
| API request | `PUT /v1/learners/...` with `payrollid` | `400 Unexpected field(s): payrollid` |
| Webhook | B1a | `data.payrollnumber` |
| UI | Profile, **Current cycles** tab | Label **Payroll number** |
| CSV | E6 header | `payrollnumber` column (2nd) |
| DB | column query from F1 | `payrollnumber` present, `payrollid` absent |
| Docs | `docs/api-endpoints.md`, `docs/openapi.yaml` | `payrollnumber` only |

`[ ] Pass  [ ] Fail  Notes: ____`

### F3 – `openDate` and `dueDate` removed ⚠️ email placeholders remain

| Where | Check | Expected |
| --- | --- | --- |
| API request | D5 (d) | `400 Unexpected field(s): opendate, duedate` |
| API response | `GET /v1/cycles/QA-1001-A` | No `opendate`/`duedate`; has `anniversarydate`, `timeopen`, `timedue`, `timeaccessend` |
| Webhook | B1a | No `opendate`/`duedate`; has `anniversarydate`, `dueyear` |
| CSV | E6 header | No `opendate`/`duedate`; has `anniversarydate`, `accessenddate` |
| DB | `sql "SELECT column_name FROM information_schema.columns WHERE table_name = 'm_local_cgt_cycle';"` | No `opendate`/`duedate`; has `anniversarydate` |
| UI | Banner "Due <date>", column "Due (anniversary)" | Labels only, derived from `anniversarydate` |

⚠️ The email template placeholders `{opendate}` and `{duedate}` still exist (settings → Notifications, and the
default email texts). They are template variables computed from `anniversarydate`, not API fields. Decide whether
they should be renamed (for example `{anniversarydate}`).

`[ ] Pass  [ ] Fail  Notes: ____`

### F4 – No stale references in code and docs

The plugin is PHP with no `src/` folder. Run from the plugin repository root:

```bash
# ripgrep
rg -ni "externalid|payrollid|opendate|duedate" --glob '!db/upgrade.php' --glob '!tests/**' --glob '!TEST_GUIDE.md' .
# or grep
grep -rniE "externalid|payrollid|opendate|duedate" --include='*.php' --include='*.js' --include='*.md' --include='*.yaml' \
  --exclude=upgrade.php --exclude-dir=tests --exclude=TEST_GUIDE.md .
```

**Expected (current state):** only the template placeholders, which are intentional (see F3):

```text
lang/en/local_caregivertraining.php  {duedate} in 5 default email strings; {opendate}/{duedate} in template_desc
classes/local/notifier.php           '{opendate}' and '{duedate}' placeholder replacement
```

`db/upgrade.php` (the step that drops and renames the columns) and `tests/` (asserting the old fields are rejected)
are expected to mention the old names.

**Moodle repository** (`../moodle`), outside this plugin: no matches expected.

```bash
cd ../moodle && rg -ni "externalid|payrollid|opendate|duedate" docker docs/caregiver-training
```

`docs/caregiver-training/contracts-v1.md`, `docker/local/sanitize/01-sanitize.sql` and `docker/local/seed.php` use
`payrollnumber` and `anniversarydate`.

`[ ] Pass  [ ] Fail  Notes: ____`

---

## G. Summary checklist

| ID | Area | Test case | Expected result | Status (Pass/Fail) | Notes |
|----|------|-----------|-----------------|--------------------|-------|
| B1a | Webhooks | Certificate/completion webhook ⚠️ | Signed `cycle.completed` POST with `alayacareid`, `moodleuserid`, `certificate.verifyurl`; outbox `delivered` | | Field names differ from spec |
| B1b | Webhooks | Receiver down | `pending`, `lasterror` recorded, backoff 2/4/8… min, same event id | | |
| B1c | Webhooks | Retries exhausted, recovered | `failed` + `outbox_failed` exception; `repair` re-queues; delivered | | |
| B2 | Webhooks | Status webhook ⚠️ | Not implemented; poll `GET /v1/cycles?modifiedsince=` | | Decide with adapter team |
| C1 | Provision | New student | `201 created`, `Location`; binding + profile fields; enrol at cycle ⚠️ | | |
| C2 | Provision | Duplicate student | Replay `201 replayed`; `200 unchanged/updated`; `409` for conflicts | | |
| C3 | Provision | AlayaCare ID not found | `404 learnernotfound` (GET/PUT); search `200 none` | | |
| C4 | Provision | Missing/invalid API key | `401 unauthorized`, `401 invalidtoken`, `403 wrongservice` | | Bearer token, not `x-api-key` |
| C5 | Provision | Invalid body | `400 invalidparameter` with `details`; `400 invalidemail`; `400 invaliddate` | | |
| C6 | Provision | Docs ⚠️ | `docs/api-endpoints.md`, `docs/openapi.yaml` match | | No hosted Swagger UI |
| D1 | Start cycle | Successful start | `201 created`, `status open`, row in `m_local_cgt_cycle`, enrolment | | Needs `alayacareid` + `anniversarydate` ⚠️ |
| D2 | Start cycle | Already in progress | `200 unchanged` (same id); `409 cycleconflict` (new id); 1 active row | | |
| D3 | Start cycle | Student not found | `404 bindingmismatch`; `404 cyclenotfound` | | |
| D4 | Start cycle | Already finished | `409 alreadycompleted`; `409 cycleimmutable`; next year `201` | | |
| D5 | Start cycle | Missing/invalid key, bad input | `401`; `400` missing/legacy fields; `405`; `404 routenotfound` | | |
| D6 | Start cycle | Docs ⚠️ | Docs match D1–D5 | | No hosted Swagger UI |
| E1 | Features | Independent student cycles | Separate cycles, time and banners | | |
| E2 | Features | Snapshot/history dashboard | `completion` + `prereset` snapshots, verified, certificate links | | |
| E3 | Features | Minimum 5 hours | 17999 → not met; 18000 and 18001 → completed | | |
| E4 | Features | Time tracker UI | 30 s beats; hidden/idle/concurrent earn nothing; stops at requirement | | |
| E5 | Features | Next activity button | Goes to next unfinished; locked text; "all complete" on last page; back to earlier unfinished; none on quiz attempts | | |
| E6 | Features | CSV export | `caregiver-training-YYYYMMDD-HHMMSS.csv`, 26 columns, exact-match filter | | |
| F1 | Field changes | `externalId` removed | Absent from API, webhook, UI, CSV, DB, docs | | |
| F2 | Field changes | `payrollnumber` | Present in API, webhook, UI, CSV, DB, docs | | |
| F3 | Field changes | `openDate`/`dueDate` removed ⚠️ | Rejected by API; absent in responses/CSV/DB | | Email placeholders remain |
| F4 | Field changes | No stale references | Plugin clean except placeholders; Moodle repo clean | | |
