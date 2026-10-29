# Caregiver annual training: administrator and HR guide

This guide explains what the **Caregiver annual training** plugin (`local_caregivertraining`) does, how to set it up,
and how to find your way around it in Moodle. It is written for site administrators, HR and training coordinators.

For developers and the integration adapter, see [api-endpoints.md](api-endpoints.md) (every endpoint, error and
scenario) and [openapi.yaml](openapi.yaml). For step-by-step test scripts, see [testing-guide.md](testing-guide.md)
(plain language) and [../TEST_GUIDE.md](../TEST_GUIDE.md) (technical QA).

## Contents

- [What the plugin does](#what-the-plugin-does)
- [Features](#features)
- [Who uses what](#who-uses-what)
- [Setting up the plugin](#setting-up-the-plugin)
- [How an annual cycle works](#how-an-annual-cycle-works)
- [What learners see](#what-learners-see)
- [The administrator view](#the-administrator-view)
- [Emails](#emails)
- [Scheduled tasks](#scheduled-tasks)
- [Command-line tools](#command-line-tools)
- [Troubleshooting](#troubleshooting)

## What the plugin does

Every caregiver must complete annual training before their AlayaCare anniversary date. The plugin runs that yearly
cycle in one Moodle course:

1. The **AlayaCare adapter** (a separate integration service) tells Moodle about each caregiver and their anniversary
   date. Moodle never contacts AlayaCare itself.
2. The plugin gives the caregiver their own **cycle** for that year. When the cycle opens, it keeps a verified copy of
   last year's evidence, resets only that caregiver's progress, and enrols them in the annual course.
3. While the caregiver trains, the plugin shows their due date and recorded time, counts active time on activity
   pages, and sends reminders.
4. When every requirement is met, it issues the certificate, emails the caregiver (and HR), and sends a signed
   `cycle.completed` event back to the adapter.

Each caregiver's cycle is independent. Resetting or completing one caregiver never affects anyone else in the course.

## Features

**Identity and enrolment**

- Links each Moodle account to one AlayaCare employee ID, permanently. One employee maps to one account, and vice
  versa.
- Creates new learner accounts on request and emails them a secure "set your password" link.
- Never links an existing account by email alone, so a shared or mistyped email can't attach the wrong person.
- Stores the AlayaCare ID, payroll number, HCA number and HCA registration date in locked profile fields that
  learners can't edit.
- Enrols learners in the annual course for exactly their window, and ends access 14 days after the due date.

**Annual cycles**

- Works out each cycle's dates from the anniversary date: it opens 60 days before, is due at 23:59:59 on the
  anniversary, and access ends 14 days after, all in the compliance timezone.
- Allows one active cycle per learner and refuses to reopen an anniversary that is already complete.
- Before resetting a learner for a new year, takes a verified **snapshot** of their previous evidence, including a
  copy of the certificate PDF. If the snapshot or reset fails, nothing changes and the cycle is marked **blocked** for
  review.

**Learner experience**

- A banner on every annual course page with the due date, days remaining (or overdue), and a clock-face showing the
  minutes completed against the requirement.
- A **Next activity** button at the bottom of each activity page that goes to the next unfinished activity in course
  order. It is not shown on the course page or on the last activity.

**Time requirement**

- Requires a minimum amount of training time (five hours by default) before completion.
- Counts time only while the learner is active on a countable activity page with the tab visible. Idle time, hidden
  tabs and several tabs at once earn nothing extra, and counting stops when the requirement is reached.
- Supports a **nominal** policy instead, which credits a fixed duration for each completed activity.

**Completion and certificates**

- Completes a cycle only when the course is complete, the time requirement is met, and the certificate can be
  issued.
- Checks the certificate design before issuing: it must have the learner's name and certificate code, no empty pages
  and no missing images. It can also be pinned to one approved design.
- Gives the certificate a public verification link that employers can open without logging in.
- Optionally publishes each certificate to S3 as a PDF and an image, and shows it in the completion email.

**Notifications**

- Branded emails when the window opens, as reminders before the due date, on the due date, when overdue, and on
  completion, each with its own on/off switch and editable wording.
- Optional HR blind copies (BCC) of every learner email.
- Each email is sent once per cycle, however often the task runs.

**Administration and evidence**

- An administrator view with tabs for exceptions, blocked resets, current cycles and prior-cycle snapshots.
- A CSV export of cycles and snapshots for audits, filterable by AlayaCare ID, payroll number, HCA number, Cycle ID
  or certificate code.
- A health check the adapter can poll, listing configuration problems.
- Snapshots are kept at least three years and are never deleted automatically.

## Who uses what

| Person | What they use | Permission needed |
| --- | --- | --- |
| Caregiver (learner) | The annual course, banner, time tracker and Next activity button | `local/caregivertraining:recordtime` (students have it) |
| HR or training coordinator | The administrator view and CSV export | `local/caregivertraining:viewreports` and `local/caregivertraining:export` (managers have them) |
| Site administrator | Settings, plus retry, archive and resolve in the administrator view | `local/caregivertraining:managecycles` (managers have it) |
| AlayaCare adapter | The REST API or web service functions | `local/caregivertraining:adapterapi` (nobody has it by default) |

## Setting up the plugin

### 1. Check the requirements

- Moodle 5.1 with PHP 8.3.
- The **Custom certificate** activity plugin (`mod_customcert`), plus the core Lesson, Quiz and Feedback activities.
- Moodle's cron running every minute. The plugin's work happens in scheduled tasks.

### 2. Install or upgrade

Upload the plugin ZIP at **Site administration → Plugins → Install plugins**, or place it in
`public/local/caregivertraining` and run `php admin/cli/upgrade.php`.

To upgrade, install the newer ZIP over the existing plugin. **Don't uninstall first**: uninstalling deletes the
plugin's tables, which removes every learner link, cycle and snapshot.

### 3. Prepare the annual course

1. Create (or choose) the annual training course and turn on **completion tracking** in its settings.
2. Add the training activities, and give each one an activity completion condition.
3. Set **course completion** (Course → More → Course completion) so that it requires the activities. The plugin
   waits for Moodle's course completion before it completes a cycle.
4. Add a **Custom certificate** activity. Its design must include the learner's name and the certificate code, and
   **Allow anyone to verify a certificate** must be on so the verification link works without a login.

### 4. Configure the plugin settings

Go to **Site administration → Plugins → Local plugins → Caregiver annual training**. Like other Moodle plugins, the settings are
split into pages: **General settings**, **Training time**, **Notifications**, **Email templates**, **Certificate
storage** and **AlayaCare integration**, followed by the **Training records** page. Each page has its own **Save
changes** button.

While something still stops learners from completing (no course or certificate chosen, the time rule not decided, or
no activities counting toward the time), **General settings** starts with a warning listing what to fix. Settings
that don't apply are hidden: for example, the activity list only appears once you choose how time is counted.

**General settings**

| Setting | What to enter |
| --- | --- |
| Annual training course | Pick the course (type to search). Save before choosing the certificate. |
| Learner role | **Default (Student)**, or another course role. |
| Learner system role | The site-level role caregivers get when the adapter creates or links them. Defaults to **Home Care Aide** where that role exists; **None** turns it off. It is never removed.
| Compliance timezone | The timezone due dates are counted in, for example `America/Los_Angeles`. |
| Keep completion records for (years) | At least 3. |
| Certificate activity | The Custom certificate activity of the annual course. Required. |
| Locked certificate design | Optional. Paste the fingerprint shown under the setting to lock the approved design. |

**Training time**

| Setting | What to enter |
| --- | --- |
| How time is counted | **Time actually spent** counts active time. **Set time per activity** credits a fixed time per completed activity. **Not decided yet** (the default) blocks every completion, so change it before go-live. |
| Minimum training time | In hours or minutes, for example 5 hours. Set it to 0 when completing the course is enough. |
| Count every activity in the course | Tick to count every activity, including ones added later, then pick the **Activity types that don't count**. Untick to pick the **Activities that count** from a list. |
| Time per activity | Only for **Set time per activity**: seconds per activity, as JSON such as `{"12": 1800}`. The course's activity numbers are listed under the box. |
| Check-in interval and Idle after | Only for **Time actually spent**. The defaults suit most courses. |

**Notifications**

| Setting | What to enter |
| --- | --- |
| Training open, Due today, Overdue and Completion email | Switch each email on or off. Training open and Completion are on by default. |
| Reminder emails | Days before the due date to send reminders, for example `30,14`. Empty sends none. |
| Copy HR on emails | Comma-separated addresses blind-copied (BCC) on every learner email. Learners can't see them. |

**Email templates** has a **Subject** and **Message** for each email, filled in with the default wording. Edit them
using the [placeholders](#placeholders); empty a field to go back to the default.

**Certificate storage** (S3) is optional. Leave the bucket empty to keep certificates in Moodle only. When set, each
completed certificate is published as a PDF and an image at an unguessable public address. In AWS, leave the access
and secret keys empty to use the server's own credentials.

**AlayaCare integration**

| Setting | What to enter |
| --- | --- |
| Send completions to AlayaCare | Tick to send `cycle.completed` events, once the address and secret are set. |
| Adapter address | The adapter's HTTPS address. |
| Signing secret | The shared secret used to sign each event. |
| Retry limit | How many times a failed event is retried before it is listed as an exception (default 20). |

Environment variables override some settings. When `CAREGIVERTRAINING_ADAPTER_ENABLED`,
`CAREGIVERTRAINING_ADAPTER_URL`, `CAREGIVERTRAINING_ADAPTER_SECRET`, `CAREGIVERTRAINING_CERTIFICATE_FINGERPRINT` or
any `CAREGIVERTRAINING_S3_*` variable is set on the server, it wins, and a yellow note under that setting says so.

### 5. Connect the AlayaCare adapter

1. Enable web services at **Site administration → Advanced features**.
2. Create a dedicated account for the adapter with the **Web services authentication** method, so it can't log in
   interactively.
3. Give that account a system role that has only `local/caregivertraining:adapterapi` and `webservice/rest:use`.
4. At **Site administration → Server → Web services → External services**, enable **Caregiver training adapter**
   and add the account as an authorised user.
5. At **Site administration → Server → Web services → Manage tokens**, create a token for the account on that
   service, with an expiry date. Give the token to the adapter team as a secret.

The adapter should only send `userid` for an existing learner's own account. The plugin refuses (`404 usernotfound`)
to link a learner to the adapter account or any other service account, a suspended account, a guest or a site admin.

### 6. Check that everything is ready

Ask the adapter team to call the health check, `GET /local/caregivertraining/api.php/v1/health`, or check these
yourself:

- The **Training records** page opens without errors.
- **How time is counted** isn't **Not decided yet**.
- **Site administration → Server → Tasks → Scheduled tasks** shows the plugin's tasks running (see
  [Scheduled tasks](#scheduled-tasks)).

The health check lists any remaining problems, such as `course_not_configured`, `certificate_not_configured`,
`certificate_template_incomplete`, `certificate_verification_not_public`, `time_policy_unresolved` or
`process_cycles_stale`.

## How an annual cycle works

```text
 Anniversary − 60 days        Anniversary (due)          Anniversary + 14 days
          │                           │                            │
 scheduled ─▶ open ─────────────────────▶ overdue ───────────────────▶ access ends
          │   snapshot, reset, enrol
          └─ the learner trains; the cycle completes as soon as every requirement is met
```

**Cycle statuses**

| Status | Meaning |
| --- | --- |
| Scheduled | Created, but the window hasn't opened yet. The learner has no access until the open date. |
| Open | The window is open and the learner can train. It stays open after the due date (shown as overdue) until it completes. |
| Blocked | The snapshot or reset failed. Nothing was changed. An administrator reviews it and clicks **Retry after review**. |
| Completed | Every requirement was met and the certificate was issued. Completed cycles can't be changed. |
| Superseded | Replaced by a newer cycle, for example after a rehire. |

**Compliance** is shown alongside the status: upcoming, open, overdue, complete, or not applicable for superseded
cycles.

**Completion requirements.** A cycle completes only when all of these are true. Any that aren't are listed as
"gates" in the API and the CSV export:

| Gate | What it means | How to clear it |
| --- | --- | --- |
| `cycle_not_open` | The window hasn't opened. | Wait for the open date. |
| `course_not_complete` | Moodle hasn't recorded course completion. | The learner finishes the activities, and the "Calculate regular completion data" task runs. |
| `completion_predates_cycle` | The course completion is from before this cycle. | The learner completes the course again in this cycle. |
| `time_policy_unresolved` | **How time is counted** is still **Not decided yet**. | Choose **Time actually spent** or **Set time per activity**. |
| `time_requirement_not_met` | Not enough time recorded. | The learner keeps working on activity pages until the banner reaches the requirement. |
| `certificate_not_configured` | No valid certificate is set. | Choose the **Certificate activity** in **General settings**. |
| `certificate_template_incomplete` | The certificate design can't be issued. | Fix the design (name, code, no empty pages, no missing images, or the approved fingerprint). |

## What learners see

**Accounts.** New caregivers receive an email with their username and a **Set your password** button. The link
expires for security after Moodle's password reset time (`$CFG->pwresettime`, in seconds), and the email states how long
it lasts. The admin page only offers up to 4 hours, so a longer time, such as a week, is set in `config.php`. If it has expired, they use
**Lost password?** on the login page.

**The banner.** Every page of the annual course shows the due date (larger on the course homepage) and either the days remaining, "Opens on …"
before the window, "Overdue by … The course remains open until …" after the due date, or "Completed on …". It also
shows a clock-face with the course total completed of the required time and the percentage.

**Time counting.** Time counts only while the learner is on a countable activity page, the tab is visible and they
have interacted recently (or media is playing). It doesn't count on the course page itself.

**Next activity.** At the bottom of each activity page, a **Next activity** button goes to the next unfinished
activity they can open. If the next one is locked by an access restriction, they see "Complete this activity to
unlock the next one." There's no button on the course page or on the last activity.

**Completion.** When everything is done, the banner says "Completed on …", the certificate appears in the
certificate activity, and the completion email arrives with the certificate code and link.

## The Training records page

Go to **Site administration → Plugins → Local plugins → Caregiver annual training → Training records** (the first
link in the plugin's menu), or open `/local/caregivertraining/index.php`.

The page opens on **Employees**. The menu at the top switches between three views; the numbers show how many
items need attention.

| View | Shows | Actions |
| --- | --- | --- |
| Employees | One row per employee with their latest cycle: Cycle ID, status, due date, completion date and number of cycles. Search by name, AlayaCare ID, payroll or HCA number, Cycle ID or certificate code, and filter by status (Open, Overdue, Upcoming, Complete). Click a column heading to sort. 50 employees per page. | Click a name to open that employee's training history. |
| Exceptions | Problems that need a person to decide, such as duplicate emails, payroll numbers bound to another employee, failed certificate renders or failed event deliveries. Repeats are counted on one row. **Show resolved too** includes handled ones. | **Mark as resolved** once handled. |
| Blocked resets | Cycles whose evidence snapshot or reset failed, with the reason. | **Retry reset** runs the snapshot and reset again. |

**Employee training history.** The employee's details (with a link to their Moodle profile), then every cycle, newest
first: year, Cycle ID, status, due date, access end, completion date, approved time, certificate code (links to the
verification page) and the evidence kept (before each reset and at completion, with its verification status and a
**Certificate PDF** link). **Back** returns to the list with your search kept.

Row actions are in the **⋮** menu at the end of each row, and each asks for confirmation first. **Archive** marks a
completed cycle as archived: it stays in the history with an "Archived" label and stops showing in the learner's
banner. Nothing is deleted.

**Downloading.** Below the employee list, **Download training history as** downloads the full history of the
employees currently listed (respecting your search and status filter); on an employee's page it downloads that
employee's history. Choose CSV, Excel, ODS, PDF, HTML or JSON. The file is named `caregiver-training-YYYYMMDD-HHMMSS`,
has plain-language headers and one row per cycle, grouped by employee with the newest cycle first. Dates are
`YYYY-MM-DD` (completion `YYYY-MM-DD HH:MM`) in the compliance timezone, approved time is in hours, and Yes/No values
are words. The evidence for each cycle is summarised in one cell, for example "At completion, 2026-03-05 (verified);
Before the yearly reset, 2027-01-20 (verified)". Each download is logged.

## Emails

| Email | When | Button | Default |
| --- | --- | --- | --- |
| Account setup | When the adapter creates a caregiver account and asks for an activation email. | Set Your Password | Sent when requested, unless the learner has already logged in |
| Training is open | When the cycle opens, 60 days before the due date. | Continue Training | On |
| Reminder | The configured number of days before the due date, for example 30 and 14. | Continue Training | Off (no days set) |
| Due today | On the due date. | Continue Training | Off |
| Overdue | Once after the due date, if not complete. | Continue Training | Off |
| Completed | When the cycle completes, with the certificate PDF attached. | View Certificate | On |

Emails are sent by the "Send caregiver training notifications" task, so they can take up to 30 minutes to arrive.
Each one is sent only once per cycle. HR is blind-copied on the learner's own email, so HR sees exactly what the learner got,
with the learner in the To line. While HR copies are set, learner emails skip the Moodle notification (the bell).

Each email has one button, for its main link. Where the message mentions that link (for example "Start your
training here: {courseurl}"), the button appears in that spot; other links, such as the login page, stay plain
links. Edit the wording under **Email templates**; the account setup email is there too.

All emails end with the same footer: the Quick Start Guide link, HR Compliance's phone number and email, and the
platform support email. The phone number can be tapped to call and the addresses open a new email. Change them once
under **Notifications → Email footer**; the Quick Start Guide line only appears once its link is set.

Moodle's own course welcome email ("you are enrolled in the course …") comes from the course's manual enrolment
settings, not from this plugin. To turn it off, go to the course's **Participants → Enrolment methods**, edit
**Manual enrolments**, and set **Send course welcome message** to No.

### Placeholders

You can use these in the subject and message settings:

| Placeholder | Replaced with |
| --- | --- |
| `{firstname}`, `{lastname}` | The learner's name. |
| `{opendate}`, `{duedate}`, `{accessenddate}` | The cycle's dates, for example "November 12, 2026". |
| `{courseurl}` | The annual course. |
| `{cycleid}` | The Cycle ID. |
| `{certificatecode}` | The certificate code (completion only). |
| `{certificateurl}` | The certificate: the public PDF when S3 storage is set, otherwise the certificate activity. |
| `{loginurl}` | The login page. |
| `{quickstartguideurl}` | The Quick Start Guide link from the email footer settings. |
| `{username}`, `{expiry}`, `{link}` | Account setup only: the username, how long the link lasts, and the set-password link. |

## Scheduled tasks

They're listed at **Site administration → Server → Tasks → Scheduled tasks**.

| Task | Runs | What it does |
| --- | --- | --- |
| Start due caregiver cycles and reconcile completion | Every 15 minutes | Opens cycles whose window has started (snapshot, reset, enrol) and completes cycles whose requirements are met. |
| Send caregiver training notifications | At 5 and 35 minutes past each hour | Sends the emails that are due. |
| Deliver caregiver training events to the adapter | Every 5 minutes | Sends `cycle.completed` events and retries failed ones with increasing delays. |

Completion also depends on Moodle's own **Calculate regular completion data** task, which records course completion.

To run a task immediately, click **Run now** next to it. If there's no Run now link, an administrator must set
**Site administration → Server → System paths → Path to PHP CLI** and tick **Allow 'Run now' for scheduled tasks**
at **Site administration → Security → Site security settings**. Alternatively, a server administrator can run:

```sh
php admin/cli/scheduled_task.php --execute='\local_caregivertraining\task\process_cycles'
```

## Command-line tools

Run these on the server from the Moodle folder.

| Command | Purpose |
| --- | --- |
| `php public/local/caregivertraining/cli/create_test_cycle.php --userid=42` | Links an existing user to a test AlayaCare ID and gives them a cycle, without the adapter. For testing only. Options: `--alayacareid`, `--cycleid`, `--anniversarydate`, `--sendactivation` (queue the account setup email). |
| `php public/local/caregivertraining/cli/apply_certificate_design.php` | Shows the configured certificate's design, its fingerprint and anything that blocks issuing it. Add `--from=<template id>` to compare with another design, and `--execute` to replace it. |

## Troubleshooting

**The banner or Next activity button doesn't appear.**
The learner has no open or completed cycle in the configured course, or you're looking at a different course. Check
**Training records**, and check that the **Annual training course** setting is that course. The button also never
appears on the course page or the last activity.

**The learner finished everything but isn't marked complete.**
Look up their cycle with the adapter's `GET /v1/cycles/{cycleid}`, or in the CSV export, and check the gates. The
most common causes are time still short of the requirement, **How time is counted** still **Not decided yet**, or course completion
not yet calculated. Run "Calculate regular completion data" and then "Start due caregiver cycles and reconcile
completion".

**A cycle is blocked.**
Open the Blocked resets tab to see the reason, fix the cause, and click **Retry after review**.

**Cycles wait with `certificate_template_incomplete`.**
The certificate design is missing the learner name or code, has an empty page or a missing image, or differs from
the pinned fingerprint. Run `cli/apply_certificate_design.php` to see what's wrong. The cycles complete
automatically once it's fixed.

**A new learner never got their login.**
Check that the adapter asked for an activation email and that the notifications task has run. If the welcome email
is addressed to the adapter's own account, the adapter sent the wrong `userid` when provisioning; see
[Connect the AlayaCare adapter](#5-connect-the-alayacare-adapter).

**The adapter gets `409 existingaccount`.**
Someone already has an account with that email. Confirm it's the same person, then the adapter repeats the request
with that account's `userid` to link it.

**Every learner's progress and cycles disappeared.**
The plugin was uninstalled, which deletes its data. Restore from a backup, or have the adapter provision the learners
and cycles again. Always upgrade by installing the new ZIP over the existing plugin.
