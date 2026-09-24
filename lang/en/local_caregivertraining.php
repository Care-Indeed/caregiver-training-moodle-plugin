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
 * English strings.
 *
 * @package    local_caregivertraining
 * @copyright  2026 CI Institute of Nursing
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$string['activation_body'] = 'Hello {$a->firstname},

An account has been created for you on {$a->sitename} for caregiver annual training.

Username: {$a->username}

Choose your password using this link. For security it expires in {$a->expiry}:
{$a->link}

If the link has expired, use "Forgotten your username or password?" on the login page:
{$a->loginurl}';
$string['activation_subject'] = '{$a}: set up your training account';
$string['adapterenabled'] = 'Deliver events to the adapter';
$string['adaptersecret'] = 'Adapter signing secret';
$string['adapterurl'] = 'Adapter event endpoint';
$string['adminview'] = 'Caregiver training';
$string['archivecycle'] = 'Archive (hide)';
$string['banner_complete'] = 'Completed on {$a}';
$string['banner_daysremaining'] = '{$a} day(s) remaining';
$string['banner_due'] = 'Due on or before {$a}';
$string['banner_overdue'] = 'Overdue by {$a} day(s). The course remains open.';
$string['banner_scheduled'] = 'Opens on {$a}';
$string['banner_time'] = 'Approved countable time: {$a->approved} of {$a->required}. Remaining: {$a->remaining}.';
$string['banner_time_note'] = 'Time counts only while this page is visible and you are watching or interacting. Idle, hidden or duplicate tabs do not add time.';
$string['banner_time_unresolved'] = 'Recorded activity time: {$a}. HR has not yet approved how the five hours are counted, so this time is not final and completion is on hold.';
$string['banner_title'] = 'Annual caregiver training';
$string['caregivertraining:adapterapi'] = 'Call the caregiver training adapter web services';
$string['caregivertraining:export'] = 'Export caregiver training evidence';
$string['caregivertraining:managecycles'] = 'Retry blocked resets and archive caregiver training cycles';
$string['caregivertraining:recordtime'] = 'Record caregiver training time';
$string['caregivertraining:viewreports'] = 'View caregiver training exceptions, cycles and snapshots';
$string['certificatecmid'] = 'Custom certificate course module ID';
$string['certificatecmid_desc'] = 'Course module id of the existing Custom Certificate activity in the annual course.';
$string['col_actions'] = 'Actions';
$string['col_alayacareid'] = 'AlayaCare ID';
$string['col_certificate'] = 'Certificate';
$string['col_completed'] = 'Completed';
$string['col_compliance'] = 'Compliance';
$string['col_created'] = 'Created';
$string['col_cycleid'] = 'Cycle ID';
$string['col_details'] = 'Details';
$string['col_due'] = 'Due';
$string['col_employee'] = 'Employee';
$string['col_hash'] = 'SHA-256';
$string['col_open'] = 'Open';
$string['col_payrollid'] = 'Payroll';
$string['col_reason'] = 'Reason';
$string['col_status'] = 'Status';
$string['col_time'] = 'Approved time';
$string['col_type'] = 'Type';
$string['col_verified'] = 'Verified';
$string['compliance_complete'] = 'Complete';
$string['compliance_notapplicable'] = 'Not applicable';
$string['compliance_open'] = 'Open';
$string['compliance_overdue'] = 'Overdue (access open)';
$string['compliance_upcoming'] = 'Upcoming';
$string['compliancetimezone'] = 'Compliance timezone';
$string['compliancetimezone_desc'] = 'Timezone used to turn adapter dates into instants. Training is due on or before the due date, so the due instant is 23:59:59 on the due date in this timezone. Assumed California time until HR confirms.';
$string['countablecmids'] = 'Countable course module IDs';
$string['countablecmids_desc'] = 'Comma-separated course module ids whose time counts. Empty means no activity counts yet.';
$string['courseid'] = 'Annual course ID';
$string['courseid_desc'] = 'Moodle course id of the annual training course.';
$string['default_body_completion'] = 'Hello {firstname},

Thank you for completing your annual caregiver training.

Certificate code: {certificatecode}
Certificate: {certificateurl}';
$string['default_body_due'] = 'Hello {firstname},

Your annual caregiver training is due today, {duedate}.

Continue here: {courseurl}';
$string['default_body_overdue'] = 'Hello {firstname},

Your annual caregiver training was due on {duedate} and is not yet complete. The course remains available.

Continue here: {courseurl}';
$string['default_body_reminder'] = 'Hello {firstname},

Your annual caregiver training is due on or before {duedate}.

Continue here: {courseurl}';
$string['default_body_windowopen'] = 'Hello {firstname},

Your annual caregiver training is open. Please complete it on or before {duedate}.

Start here: {courseurl}';
$string['default_subject_completion'] = 'Annual caregiver training completed';
$string['default_subject_due'] = 'Annual caregiver training is due today';
$string['default_subject_overdue'] = 'Annual caregiver training is overdue';
$string['default_subject_reminder'] = 'Reminder: annual caregiver training due {duedate}';
$string['default_subject_windowopen'] = 'Your annual caregiver training is now open';
$string['error:ambiguousemail'] = 'More than one Moodle account uses this email. Resolve manually before provisioning.';
$string['error:bindingconflict'] = 'Binding conflict: {$a}';
$string['error:bindingmismatch'] = 'The Moodle user and AlayaCare employee do not match an existing binding.';
$string['error:cycleconflict'] = 'Cycle conflict: {$a}';
$string['error:cycleimmutable'] = 'Cycle {$a} is final and cannot be changed.';
$string['error:cyclenotfound'] = 'Cycle not found.';
$string['error:dateorder'] = 'The open date must be on or before the due date.';
$string['error:existingaccount'] = 'Moodle account {$a} already uses this email. Confirm it is the same person and call again with userid={$a}.';
$string['error:idempotencyconflict'] = 'The idempotency key was already used with a different request.';
$string['error:invaliddate'] = 'Invalid date "{$a}". Use YYYY-MM-DD.';
$string['error:invalidemail'] = 'Invalid email address.';
$string['error:notconfigured'] = 'The caregiver training plugin is not configured: {$a}';
$string['error:policyunresolved'] = 'HR policy not approved: {$a}';
$string['error:usernametaken'] = 'The username derived from this email belongs to a different account.';
$string['error:usernotfound'] = 'Moodle user not found or deleted.';
$string['event_access_updated'] = 'Caregiver cycle access updated';
$string['event_cycle_archived'] = 'Caregiver cycle archived';
$string['event_cycle_blocked'] = 'Caregiver cycle blocked for Engineering review';
$string['event_cycle_completed'] = 'Caregiver cycle completed';
$string['event_cycle_retried'] = 'Blocked caregiver cycle retried';
$string['event_cycle_started'] = 'Caregiver cycle started';
$string['event_cycle_upserted'] = 'Caregiver cycle created or updated';
$string['event_evidence_exported'] = 'Caregiver evidence exported';
$string['event_exception_resolved'] = 'Caregiver exception resolved';
$string['event_learner_provisioned'] = 'Caregiver learner provisioned';
$string['event_learner_reset'] = 'Caregiver learner progress reset after verified snapshot';
$string['export'] = 'Export CSV';
$string['exportfilter'] = 'Filter by AlayaCare ID, External ID, payroll number or Cycle ID';
$string['field_desc'] = 'Managed by the AlayaCare integration. Learners cannot edit this field.';
$string['field_externalid'] = 'AlayaCare External ID';
$string['field_hcanumber'] = 'HCA number';
$string['field_payrollid'] = 'Employee ID / payroll number';
$string['field_registrationdate'] = 'HCA registration date';
$string['heartbeatseconds'] = 'Heartbeat interval (seconds)';
$string['heartbeatseconds_desc'] = 'How often the browser reports activity. Gaps longer than the interval plus a small grace earn no credit.';
$string['hrccemails'] = 'HR copy recipients';
$string['hrccemails_desc'] = 'Comma-separated addresses that receive a copy of each learner notification.';
$string['hrcctypes'] = 'Copy HR on';
$string['idleseconds'] = 'Idle threshold (seconds)';
$string['idleseconds_desc'] = 'With no media playing, credit stops when the learner has not interacted for this long.';
$string['maxattempts'] = 'Maximum delivery attempts';
$string['messageprovider:completion'] = 'Annual training completed';
$string['messageprovider:due'] = 'Annual training due today';
$string['messageprovider:overdue'] = 'Annual training overdue';
$string['messageprovider:reminder'] = 'Annual training reminder';
$string['messageprovider:windowopen'] = 'Annual training window opened';
$string['nextactivity'] = 'Next activity';
$string['nominaldurations'] = 'Nominal durations';
$string['nominaldurations_desc'] = 'JSON object mapping course module id to approved nominal seconds, for example {"12": 1800}. Used only by the nominal policy.';
$string['nonextactivity'] = 'All available activities are complete.';
$string['nothingtodisplay'] = 'Nothing to display.';
$string['notify_completion'] = 'Send completion email';
$string['notify_due'] = 'Send due-date email';
$string['notify_overdue'] = 'Send overdue email';
$string['notify_windowopen'] = 'Send window-open email';
$string['pluginname'] = 'Caregiver annual training';
$string['privacy:metadata:adapter'] = 'Completion events are sent to the AlayaCare integration adapter.';
$string['privacy:metadata:adapter:alayacareid'] = 'AlayaCare employee id.';
$string['privacy:metadata:adapter:certificatecode'] = 'Certificate verification code.';
$string['privacy:metadata:adapter:completedat'] = 'Completion time.';
$string['privacy:metadata:adapter:cycleid'] = 'Cycle ID.';
$string['privacy:metadata:local_cgt_binding'] = 'Binding between the Moodle account and the AlayaCare employee record.';
$string['privacy:metadata:local_cgt_binding:alayacareid'] = 'AlayaCare employee id.';
$string['privacy:metadata:local_cgt_binding:externalid'] = 'AlayaCare external id.';
$string['privacy:metadata:local_cgt_binding:payrollid'] = 'Employee payroll number.';
$string['privacy:metadata:local_cgt_binding:userid'] = 'Moodle user id.';
$string['privacy:metadata:local_cgt_cycle'] = 'Annual training cycles and their status.';
$string['privacy:metadata:local_cgt_cycle:approvedseconds'] = 'Approved training seconds.';
$string['privacy:metadata:local_cgt_cycle:cycleid'] = 'Adapter Cycle ID.';
$string['privacy:metadata:local_cgt_cycle:duedate'] = 'Training due date.';
$string['privacy:metadata:local_cgt_cycle:timecompleted'] = 'When the cycle was completed.';
$string['privacy:metadata:local_cgt_cycle:userid'] = 'Moodle user id.';
$string['privacy:metadata:local_cgt_exception'] = 'Identity and cycle exceptions awaiting HR or Engineering review.';
$string['privacy:metadata:local_cgt_exception:alayacareid'] = 'AlayaCare employee id.';
$string['privacy:metadata:local_cgt_exception:details'] = 'Details of the conflict (identifiers involved).';
$string['privacy:metadata:local_cgt_exception:userid'] = 'Moodle user id.';
$string['privacy:metadata:local_cgt_notification'] = 'Notifications sent for a cycle.';
$string['privacy:metadata:local_cgt_notification:timesent'] = 'When it was sent.';
$string['privacy:metadata:local_cgt_notification:type'] = 'Notification type.';
$string['privacy:metadata:local_cgt_outbox'] = 'Completion events queued for the AlayaCare integration adapter.';
$string['privacy:metadata:local_cgt_outbox:payload'] = 'Event body with employee identifiers, dates and certificate code.';
$string['privacy:metadata:local_cgt_request'] = 'Stored responses used to make adapter requests idempotent.';
$string['privacy:metadata:local_cgt_request:response'] = 'Response returned to the adapter, including identifiers.';
$string['privacy:metadata:local_cgt_snapshot'] = 'Permanent evidence snapshots retained for the compliance period.';
$string['privacy:metadata:local_cgt_snapshot:payload'] = 'Captured completion, attempts, grades, feedback and certificate data.';
$string['privacy:metadata:local_cgt_snapshot:userid'] = 'Moodle user id.';
$string['privacy:metadata:local_cgt_timesession'] = 'Server-accounted training time per browser session.';
$string['privacy:metadata:local_cgt_timesession:creditedseconds'] = 'Seconds credited.';
$string['privacy:metadata:local_cgt_timesession:userid'] = 'Moodle user id.';
$string['privacy:retained'] = 'Records inside the retention period are kept to meet the compliance retention obligation.';
$string['profilecategory'] = 'Caregiver employment (managed by AlayaCare integration)';
$string['reminderoffsets'] = 'Reminder offsets (days before due)';
$string['reminderoffsets_desc'] = 'Comma-separated whole days before the due date, for example 30,14. Empty sends no reminders.';
$string['requiredseconds'] = 'Required seconds';
$string['requiredseconds_desc'] = 'Five hours is 18000 seconds.';
$string['resetfailed'] = 'Per-learner reset refused: {$a}';
$string['resolveexception'] = 'Mark resolved';
$string['retentionyears'] = 'Snapshot retention (years)';
$string['retentionyears_desc'] = 'Minimum three years. Snapshots are never deleted automatically in the MVP.';
$string['retryreset'] = 'Retry after review';
$string['roleid'] = 'Learner role';
$string['roleid_desc'] = 'Role assigned by the manual enrolment created for each cycle.';
$string['settings_adapter'] = 'Adapter write-back';
$string['settings_adapter_desc'] = 'Completion events are always recorded in the outbox. They are only delivered when enabled. Environment variables CAREGIVERTRAINING_ADAPTER_ENABLED, CAREGIVERTRAINING_ADAPTER_URL and CAREGIVERTRAINING_ADAPTER_SECRET override these values so secrets can come from AWS Secrets Manager.';
$string['settings_course'] = 'Annual course';
$string['settings_course_desc'] = 'Existing annual training course. The course and its content are never reset course-wide.';
$string['settings_notifications'] = 'Notifications';
$string['settings_notifications_desc'] = 'Emails go through Moodle messaging and the site SMTP configuration (SES in AWS, Mailpit locally). No reminder schedule is enabled by default because HR has not approved one.';
$string['settings_time'] = 'Five-hour time policy';
$string['settings_time_desc'] = 'HR has not approved whether five hours means tracked time or nominal duration, or which activities count. While the policy is "Unresolved", time is recorded for evidence only and course completion is blocked.';
$string['showarchived'] = 'Show archived';
$string['snapshotfailed'] = 'Evidence snapshot failed: {$a}';
$string['tab_blocked'] = 'Blocked resets';
$string['tab_cycles'] = 'Current cycles';
$string['tab_exceptions'] = 'Identity and data exceptions';
$string['tab_snapshots'] = 'Prior-cycle snapshots';
$string['task_deliver_outbox'] = 'Deliver caregiver training events to the adapter';
$string['task_process_cycles'] = 'Start due caregiver cycles and reconcile completion';
$string['task_send_notifications'] = 'Send caregiver training notifications';
$string['template_body'] = '{$a} body';
$string['template_desc'] = 'Placeholders: {firstname}, {lastname}, {opendate}, {duedate}, {courseurl}, {cycleid}, {certificatecode}, {certificateurl}.';
$string['template_subject'] = '{$a} subject';
$string['timepolicy'] = 'Time policy';
$string['timepolicy_desc'] = 'Unresolved blocks completion. Tracked uses server-accounted heartbeat time from the countable activities. Nominal credits the configured duration of each countable activity once it is complete.';
$string['timepolicy_nominal'] = 'Nominal activity duration';
$string['timepolicy_tracked'] = 'Tracked engaged time';
$string['timepolicy_unresolved'] = 'Unresolved (blocks completion)';
$string['type_completion'] = 'Completion';
$string['type_due'] = 'Due date';
$string['type_overdue'] = 'Overdue';
$string['type_reminder'] = 'Reminder';
$string['type_windowopen'] = 'Window open';
