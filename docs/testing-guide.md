# Testing the Caregiver Annual Training plugin: a step-by-step guide

This guide walks you through trying the plugin yourself, the way a caregiver and an HR administrator would use it.
It is written so that anyone can follow it. A few setup steps need a technical helper (someone who can run a
command on the Moodle server); those are clearly marked **Technical helper**.

## What the plugin does, in plain words

Every caregiver must finish the same online training once a year, before their work anniversary. The plugin:

- **Opens the training 60 days before the due date** and shows each caregiver a banner with their due date,
  the days left, and how much training time they have done.
- **Tracks training time** only while the caregiver is actually working on the course (not when the tab is hidden
  or they walk away).
- **Issues a certificate and sends a "completed" email** when everything is done.
- **Keeps a permanent record** of each year's training before clearing the course for next year, so the evidence
  is never lost.
- **Gives HR a page** listing problems, current cycles and past records, with a spreadsheet (CSV) export.

A **cycle** is one caregiver's training for one due date. Each caregiver gets a new cycle every year.

## Before you start

| What | Where |
| --- | --- |
| Moodle site (local test copy) | <http://localhost:8080> |
| Test mailbox (sees every email the site sends; nothing reaches real people) | <http://localhost:8025> |
| An administrator login | Ask your technical helper |

> **Only test on the local test copy or a staging site.** Do not test on the live site with real caregivers: when
> a caregiver's new cycle opens, the plugin clears their previous progress in the training course (after saving a
> permanent copy). On a test site this is exactly what you want to see; on the live site it would affect real
> people.

---

## Part 1: One-time setup (about 20 minutes)

### Step 0. Start the local site with the latest plugin code — **Technical helper**

In the Moodle repository, with this plugin cloned next to it:

```sh
pnpm run local:up:plugin-dev
```

Plain `pnpm run local:up` installs the plugin version pinned in the Dockerfile, which may be older than the version
you want to test. After starting, visit **Site administration → Notifications** once to finish any plugin upgrade.

### Step 1. Create a test training course

1. Log in as an administrator.
2. Go to **Site administration → Courses → Add a new course**.
3. Name it something obvious, for example **"TEST – Annual caregiver training"**.
4. Under **Completion tracking**, set **Enable completion tracking** to **Yes**. Save.
5. Add a few activities (**Turn editing on → Add an activity or resource**):
   - one **Lesson** (or a Page), one **Quiz**, one **Feedback**, and
   - one **Custom certificate** (this is the certificate caregivers receive).
6. For each activity, open **Settings → Completion conditions** and choose
   **"Students must manually mark the activity as done"** (the simplest option for testing). Save.
7. Go to the course's **More → Course completion**, tick **all the activities** under
   **Condition: Activity completion**, and save. This tells Moodle "the course is complete when every activity is done".

### Step 2. Configure the plugin

Go to **Site administration → Plugins → Local plugins → Caregiver annual training** and set these, clicking **Save changes** on each
page:

| Page | Setting | What to choose for testing | Why |
| --- | --- | --- | --- |
| General settings | Annual training course | your test course (type its name) | The course the plugin manages. Save, so the certificate list fills in. |
| General settings | Learner role | **Default (Student)** | The role caregivers get in the course. |
| General settings | Certificate activity | your certificate | The certificate to issue. |
| Training time | How time is counted | **Time actually spent** | "Not decided yet" (the default) blocks completion. |
| Training time | Minimum training time | **10 minutes** | So you don't have to study for five hours. |
| Training time | Count every activity in the course | **ticked** | So time in any activity counts. |
| Notifications | Training open email, Completion email | ticked (default) | So you can see the emails. |
| Notifications | Reminder emails | **30,14** | Reminders 30 and 14 days before the due date. |
| Notifications | Copy HR on emails | a test address such as `hr-test@example.com` | Shows the HR blind copy in the test mailbox. Never use real HR addresses on a test site. |
| AlayaCare integration | Send completions to AlayaCare | leave **unticked** | The AlayaCare connection is not part of this test. |

### Step 3. Check the setup

Open **General settings** again. There should be no yellow warning at the top. If there is, it lists what is still
missing; fix those items and save.

- [ ] General settings shows no setup warning.

### Step 4. Create test caregivers

1. Go to **Site administration → Users → Add a new user**.
2. Create two users, for example **caregiver.one** and **caregiver.two**, with test email addresses
   (`caregiver.one@example.com`). Set a password you will remember.
3. Open each user's profile and note their **user ID** from the address bar (`user/profile.php?id=`**`101`**).

### Step 5. Give each caregiver a training cycle — **Technical helper**

Normally the AlayaCare adapter does this automatically. For testing, a helper runs one command per caregiver on the
Moodle server. From the folder containing the Moodle repository:

```sh
# Caregiver one: due in 30 days (training is open now)
docker compose --env-file docker/local/local.env exec moodle \
  php /var/www/html/public/local/caregivertraining/cli/create_test_cycle.php --userid=101

# Caregiver two: due yesterday (training is overdue)
docker compose --env-file docker/local/local.env exec moodle \
  php /var/www/html/public/local/caregivertraining/cli/create_test_cycle.php --userid=102 --anniversarydate=2026-10-07
```

To test the account setup email, add `--sendactivation` for a caregiver who has never logged in. The adapter
sends this email for new learners; creating a user by hand or running the command without the option never does.
The email arrives in the test mailbox after the next cron run (`pnpm run local:cron`).

Replace `101`/`102` with the real user IDs and the date with **yesterday's date** (format `YYYY-MM-DD`). The
command prints a line like *"Cycle TEST-101-2026-11-07 created: status open, opens …, due …"*. "status open"
means the caregiver can start training.

Useful variations:

| To test | Use `--anniversarydate=` |
| --- | --- |
| Training not open yet | a date **more than 60 days** from today |
| Due today | today's date |
| Overdue | any date in the past (up to 14 days ago, see "Known differences" below) |

---

## Part 2: Test scenarios

Do each scenario, then tick the box if what you see matches **"You should see"**. Use a private/incognito browser
window to log in as a caregiver while staying logged in as administrator in your normal window.

### Scenario A. The caregiver sees their due date

1. Log in as **caregiver.one** and open the test course.

**You should see** a banner at the top: *"Due November 7, 2026 · 30 days remaining"* (30 days from the day the
cycle was created), and below it
*"Course Total: 0 mins of 10 mins · 0%"* with a small round progress circle.

- [ ] Banner shows the right due date and days remaining.

### Scenario B. Training time is counted only while working

1. As caregiver.one, open the Lesson. Scroll or click inside it every minute or so.
2. After 2–3 minutes, look at the banner (it updates by itself every 30 seconds).
3. Now switch to a different browser tab for 2 minutes, then come back.
4. Then leave the page open without touching the mouse or keyboard for 3 minutes.

**You should see** the "completed" minutes go up while you are active, and **not** go up while the tab was hidden or
while you were idle for more than 2 minutes.

- [ ] Time increases while active.
- [ ] Time does not increase in a hidden tab or when idle.

### Scenario C. The "Next activity" button

1. As caregiver.one, look at the bottom of the course page and of each activity.

**You should see** a blue **Next activity** button at the bottom of each activity that takes you to the next
unfinished activity. On an activity that isn't finished yet it may say *"Complete this activity to unlock the next
one."* There is no button on the course page or on the last activity.

- [ ] The button leads to the next activity.
- [ ] No button on the course page or the last activity.

### Scenario D. Counting stops when the time requirement is met

1. Keep caregiver.one active in the course until the banner reaches 10 minutes.

**You should see** *"Course Total: 10 mins of 10 mins · 100%"*, and the number stops at 10 even
if you keep working.

- [ ] Time stops at the requirement and never shows more than 10 of 10.

### Scenario E. Completing the training

1. As caregiver.one, mark every activity as done (tick **Mark as done** on each one), including the certificate.
2. Wait a few minutes. Moodle confirms course completion and the plugin checks it on a schedule (every 15 minutes).
   To skip the wait, an administrator can go to **Site administration → Server → Tasks → Scheduled tasks** and click
   **Run now** next to **"Calculate regular completion data"** and then
   **"Start due caregiver cycles and reconcile completion"**. (If there is no *Run now* link, the technical helper
   can run every due job at once with `pnpm run local:cron`.)
3. Reload the course as caregiver.one.

**You should see** the banner change to *"Completed on"* followed by today's date, and a certificate in the Custom
certificate activity.

- [ ] Banner says "Completed on …".
- [ ] Certificate is available.

### Scenario F. Emails

1. As administrator, click **Run now** for **"Send caregiver training notifications"** (or wait up to 30 minutes).
2. Open the test mailbox at <http://localhost:8025>.

**You should see**:

- a *"Your annual caregiver training is now open"* email for each caregiver whose training is open,
- a *"Annual caregiver training completed"* email for caregiver.one with the certificate code and link,
- a copy of each email for your HR test address.

Running the task again must **not** send the same email twice.

- [ ] Window-open email received.
- [ ] Completion email received, with certificate code.
- [ ] HR blind copy (BCC) received, identical to the learner's email.
- [ ] No duplicates after running the task again.

### Scenario G. An overdue caregiver

1. Log in as **caregiver.two** (the one due yesterday) and open the course.

**You should see** *"Overdue by 1 day(s). The course remains open until …"* and the caregiver can still do the
training.

- [ ] Overdue message shown, and the course still opens.

### Scenario H. Caregivers only see their own information

1. As caregiver.two, look at the banner and around the course.

**You should see** only caregiver.two's own due date. There is no mention of caregiver.one, and no
**Training records** administration link.

- [ ] No other caregiver's information is visible.

### Scenario I. The HR administration page

1. As administrator, go to **Site administration → Plugins → Local plugins → Caregiver annual training →
   Training records**.
2. The page opens on **Employees**: both test caregivers with their status (Open / Overdue / Complete) and due date.
   Click a name to see all of that caregiver's cycles and the permanent copies of their completed training, then
   click **Back**.
3. Use the menu at the top to open **Exceptions** (problems that need HR or Engineering, e.g. duplicate emails) and
   **Blocked resets** (should say *"No blocked resets."*).
4. Below the employee list, choose **Comma separated values (.csv)** under **Download training history as**, click
   **Download** and open the file in Excel.

**You should see** caregiver.one as **Complete** with a certificate code, caregiver.two as **Overdue**, and the
same information in the spreadsheet.

- [ ] All three views open.
- [ ] Employees shows the right status for each caregiver.
- [ ] The download opens and matches.

### Scenario J. Next year's training (advanced) — **Technical helper**

This shows that last year's record is kept when the course is cleared for the new year.

1. The helper gives caregiver.one a new cycle with a **different due date** and a new name. In real life this would
   be next year's date; for testing, use a date about 45 days from today so it opens straight away (a date more than
   60 days away would only say "Opens on …"):

   ```sh
   docker compose --env-file docker/local/local.env exec moodle \
     php /var/www/html/public/local/caregivertraining/cli/create_test_cycle.php --userid=101 \
     --cycleid=TEST-101-NEXT --anniversarydate=2026-11-22
   ```

2. Log in as caregiver.one and open the course.

**You should see** the activities unticked again (fresh start) and a new due date in the banner. As administrator,
caregiver.one's page in **Training records** shows both cycles, with last year's record kept under
**Evidence kept**.

- [ ] Progress is cleared for the new year.
- [ ] Last year's snapshot is still listed.

Also try giving caregiver.one another cycle for the **same due date they already completed** (use the due date from
Scenario A):

```sh
docker compose --env-file docker/local/local.env exec moodle \
  php /var/www/html/public/local/caregivertraining/cli/create_test_cycle.php --userid=101 \
  --cycleid=TEST-101-AGAIN --anniversarydate=2026-11-07
```

It must refuse with *"The learner already completed the annual training due 2026-11-07 …"*.

- [ ] Duplicate year is refused.

---

## Known differences from the final HR rules

These are expected in the current version, so don't log them as new bugs:

- **14-day access limit.** The course closes 14 days after the due date and the overdue message mentions that date.
  HR has since decided there is **no grace period and access stays open**; this will be changed before release.
- **Five-hour rule.** You set "Time actually spent" and 10 minutes for testing. HR has not yet confirmed whether five
  hours means tracked time and which activities count.
- **Leave and termination.** What happens to course access for employees on leave or terminated is still an HR
  decision, so the plugin only records these and does not change access.

## Reporting a problem

For anything that does not match **"You should see"**, send:

1. the scenario letter and step number,
2. the caregiver's username and the time it happened,
3. a screenshot of the page, and
4. what you expected to see.

## Cleaning up after testing — **Technical helper**

On the local test copy you can simply reset the whole local database from the latest backup. On a staging site,
delete the test users and the test course; the cycle records stay in the plugin's tables (they are kept for the
three-year evidence period by design).
