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

$string['activation_heading'] = 'Welcome to your annual caregiver training';
$string['activation_intro'] = 'Hello {$a->firstname},

An account has been created for you on {$a->sitename} for your annual caregiver training. Set your password to get started.';
$string['activation_note'] = 'For security, the link expires in {$a->expiry}. If it has expired, use "Forgotten your username or password?" on the login page: {$a->loginurl}';
$string['activation_subject'] = '{$a}: set up your training account';
$string['adapterenabled'] = 'Deliver events to the adapter';
$string['adaptersecret'] = 'Adapter signing secret';
$string['adapterurl'] = 'Adapter event endpoint';
$string['adapterurl_desc'] = 'Must start with https://. Plain http:// is only accepted for localhost, for local testing. Events are not delivered to any other URL.';
$string['adminview'] = 'Caregiver training';
$string['archivecycle'] = 'Archive (hide)';
$string['banner_complete'] = 'Completed on {$a}';
$string['banner_dayremaining'] = '{$a} day remaining';
$string['banner_daysremaining'] = '{$a} days remaining';
$string['banner_due'] = 'Due {$a}';
$string['banner_overdue'] = 'Overdue by {$a->days} day(s). The course remains open until {$a->accessend}.';
$string['banner_percent'] = 'About {$a}%';
$string['banner_scheduled'] = 'Opens on {$a}';
$string['banner_time'] = '{$a->approved} completed of {$a->required} · {$a->remaining} remaining';
$string['banner_time_unresolved'] = 'Recorded activity time: {$a}. HR has not yet approved how the five hours are counted, so this time is not final and completion is on hold.';
$string['banner_title'] = 'Annual Caregiver Training';
$string['caregivertraining:adapterapi'] = 'Call the caregiver training adapter web services';
$string['caregivertraining:export'] = 'Export caregiver training evidence';
$string['caregivertraining:managecycles'] = 'Retry blocked resets and archive caregiver training cycles';
$string['caregivertraining:recordtime'] = 'Record caregiver training time';
$string['caregivertraining:viewreports'] = 'View caregiver training exceptions, cycles and snapshots';
$string['certificatecmid'] = 'Custom certificate course module ID';
$string['certificatecmid_desc'] = 'Course module id of the existing Custom Certificate activity in the annual course.';
$string['certificatefingerprint'] = 'Required certificate design';
$string['certificatefingerprint_desc'] = 'Fingerprint of the approved certificate design. When set, certificates are only issued while the configured certificate has exactly this layout and images; otherwise completions wait and an exception is raised. Text wording is not part of the fingerprint. The environment variable CAREGIVERTRAINING_CERTIFICATE_FINGERPRINT overrides this setting.<br>Configured certificate: <strong>{$a->name}</strong>, fingerprint <code>{$a->fingerprint}</code>. Problems: {$a->problems}.';
$string['col_accessend'] = 'Access ends';
$string['col_actions'] = 'Actions';
$string['col_alayacareid'] = 'AlayaCare ID';
$string['col_certificate'] = 'Certificate';
$string['col_completed'] = 'Completed';
$string['col_compliance'] = 'Compliance';
$string['col_created'] = 'Created';
$string['col_cycleid'] = 'Cycle ID';
$string['col_details'] = 'Details';
$string['col_due'] = 'Due (anniversary)';
$string['col_employee'] = 'Employee';
$string['col_hash'] = 'SHA-256';
$string['col_reason'] = 'Reason';
$string['col_status'] = 'Status';
$string['col_time'] = 'Approved time';
$string['col_type'] = 'Type';
$string['col_verified'] = 'Verified';
$string['compliance_complete'] = 'Complete';
$string['compliance_notapplicable'] = 'Not applicable';
$string['compliance_open'] = 'Open';
$string['compliance_overdue'] = 'Overdue';
$string['compliance_upcoming'] = 'Upcoming';
$string['compliancetimezone'] = 'Compliance timezone';
$string['compliancetimezone_desc'] = 'Timezone used to turn adapter dates into instants. Training is due on or before the anniversary date, so the due instant is 23:59:59 on that date in this timezone. The window opens at 00:00 sixty days earlier and access ends at 23:59:59 fourteen days later. Assumed California time until HR confirms.';
$string['countablecmids'] = 'Countable course module IDs';
$string['countablecmids_desc'] = 'Comma-separated course module ids whose time counts. Empty means no activity counts yet.';
$string['countallactivities'] = 'Count every activity in the annual course';
$string['countallactivities_desc'] = 'When enabled, time in every activity of the annual course counts, including activities added later, and the list of countable course module ids is ignored. Leave off until HR confirms which activities count.';
$string['courseid'] = 'Annual course ID';
$string['courseid_desc'] = 'Moodle course id of the annual training course.';
$string['default_body_completion'] = 'Hello {firstname},

Thank you for completing your annual caregiver training. Your certificate is ready.';
$string['default_body_due'] = 'Hello {firstname},

Your annual caregiver training is due today, {duedate}. Please finish it before the end of the day.';
$string['default_body_overdue'] = 'Hello {firstname},

Your annual caregiver training was due on {duedate} and is not complete yet. You can still finish it until {accessenddate}, when your access to the course ends.';
$string['default_body_reminder'] = 'Hello {firstname},

This is a friendly reminder that your annual caregiver training is due on or before {duedate}. Please continue where you left off.';
$string['default_body_windowopen'] = 'Hello {firstname},

Your annual caregiver training is now open. Please complete it on or before {duedate}.';
$string['default_subject_completion'] = 'Annual caregiver training completed';
$string['default_subject_due'] = 'Annual caregiver training is due today';
$string['default_subject_overdue'] = 'Annual caregiver training is overdue';
$string['default_subject_reminder'] = 'Reminder: annual caregiver training due {duedate}';
$string['default_subject_windowopen'] = 'Your annual caregiver training is now open';
$string['duration_hr'] = '{$a} hr';
$string['duration_hrs'] = '{$a} hrs';
$string['duration_min'] = '{$a} min';
$string['duration_mins'] = '{$a} mins';
$string['email_certificate_attached'] = 'Your certificate is attached to this email as a PDF.';
$string['email_certificate_attached_image'] = 'Your certificate is attached to this email as an image. For a printable copy, open the PDF with the link above.';
$string['email_certificate_imagealt'] = 'Your certificate of completion';
$string['email_cta_activation'] = 'Set your password';
$string['email_cta_completion'] = 'View certificate';
$string['email_cta_due'] = 'Finish training';
$string['email_cta_overdue'] = 'Finish training';
$string['email_cta_reminder'] = 'Continue training';
$string['email_cta_windowopen'] = 'Start training';
$string['email_detail_accessend'] = 'Course open until';
$string['email_detail_certificatecode'] = 'Certificate code';
$string['email_detail_completeby'] = 'Complete by';
$string['email_detail_completedon'] = 'Completed on';
$string['email_detail_username'] = 'Username';
$string['email_detail_wasdue'] = 'Was due';
$string['email_fallback'] = 'Button not working? Copy this link into your browser:';
$string['email_footer'] = '{$a} · Annual caregiver training. This is an automated message; replies are not monitored.';
$string['email_heading_completion'] = 'Congratulations on completing your annual training';
$string['email_heading_due'] = 'Your annual training is due today';
$string['email_heading_overdue'] = 'Your annual training is overdue';
$string['email_heading_reminder'] = 'Your annual training is due soon';
$string['email_heading_windowopen'] = 'Your annual training is open';
$string['email_hrcopy'] = 'HR copy of the email sent to {$a->name} ({$a->email}).';
$string['email_label_activation'] = 'Account created';
$string['email_label_completion'] = 'Completed';
$string['email_label_due'] = 'Due today';
$string['email_label_overdue'] = 'Overdue';
$string['email_label_reminder'] = 'Reminder';
$string['email_label_windowopen'] = 'Training open';
$string['email_preheader_activation'] = 'Set your password to get started.';
$string['email_preheader_completion'] = 'Your certificate is ready.';
$string['email_preheader_due'] = 'Due today, {$a}.';
$string['email_preheader_overdue'] = 'It was due on {$a}. You can still finish it.';
$string['email_preheader_reminder'] = 'Due on or before {$a}.';
$string['email_preheader_windowopen'] = 'Complete it on or before {$a}.';
$string['error:alreadycompleted'] = 'The learner already completed the annual training due {$a->anniversarydate} in cycle {$a->cycleid}. A new cycle needs a different anniversary date.';
$string['error:ambiguousemail'] = 'More than one Moodle account uses this email. Resolve manually before provisioning.';
$string['error:bindingconflict'] = 'Binding conflict: {$a}';
$string['error:bindingmismatch'] = 'The Moodle user and AlayaCare employee do not match an existing binding.';
$string['error:cycleconflict'] = 'Cycle conflict: {$a}';
$string['error:cycleimmutable'] = 'Cycle {$a} is final and cannot be changed.';
$string['error:cyclenotfound'] = 'Cycle not found.';
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
$string['excludedmodules'] = 'Excluded activity types';
$string['excludedmodules_desc'] = 'When every activity counts, time in activities of these types does not count, for example the certificate or the feedback survey.';
$string['export'] = 'Export CSV';
$string['exportfilter'] = 'Filter by AlayaCare ID, payroll number, HCA number or Cycle ID';
$string['field_alayacareid'] = 'AlayaCare ID';
$string['field_desc'] = 'Managed by the AlayaCare integration. Learners cannot edit this field.';
$string['field_hcanumber'] = 'HCA number';
$string['field_payrollnumber'] = 'Payroll number';
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
$string['nextactivity_locked'] = 'Complete this activity to unlock the next one.';
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
$string['privacy:metadata:local_cgt_binding:payrollnumber'] = 'Employee payroll number.';
$string['privacy:metadata:local_cgt_binding:userid'] = 'Moodle user id.';
$string['privacy:metadata:local_cgt_cycle'] = 'Annual training cycles and their status.';
$string['privacy:metadata:local_cgt_cycle:anniversarydate'] = 'Employment anniversary date the training is due on.';
$string['privacy:metadata:local_cgt_cycle:approvedseconds'] = 'Approved training seconds.';
$string['privacy:metadata:local_cgt_cycle:cycleid'] = 'Adapter Cycle ID.';
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
$string['privacy:metadata:s3'] = 'When certificate storage is configured, completed certificates are published to an S3 bucket at an unguessable public address.';
$string['privacy:metadata:s3:certificate'] = 'The certificate of completion as a PDF and an image, showing the learner\'s name, completion date and certificate code.';
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
$string['s3accesskey'] = 'S3 access key';
$string['s3accesskey_desc'] = 'Leave empty to use the server\'s AWS credentials, such as the ECS task role. Recommended in AWS.';
$string['s3bucket'] = 'S3 bucket';
$string['s3bucket_desc'] = 'Bucket for published certificates. Leave empty to keep certificates in Moodle only; the email then links to the certificate activity.';
$string['s3endpoint'] = 'S3 endpoint';
$string['s3endpoint_desc'] = 'Only for S3-compatible storage such as MinIO, for example http://minio:9000. Leave empty for AWS.';
$string['s3prefix'] = 'S3 key prefix';
$string['s3prefix_desc'] = 'Folder within the bucket. Default: certificates/';
$string['s3publicurl'] = 'Public base URL';
$string['s3publicurl_desc'] = 'Address the public links start with, such as a CloudFront domain. Default: https://BUCKET.s3.REGION.amazonaws.com';
$string['s3region'] = 'S3 region';
$string['s3region_desc'] = 'Default: us-west-1';
$string['s3secretkey'] = 'S3 secret key';
$string['s3secretkey_desc'] = 'Leave empty to use the server\'s AWS credentials.';
$string['settings_adapter'] = 'Adapter write-back';
$string['settings_adapter_desc'] = 'Completion events are always recorded in the outbox. They are only delivered when enabled. Environment variables CAREGIVERTRAINING_ADAPTER_ENABLED, CAREGIVERTRAINING_ADAPTER_URL and CAREGIVERTRAINING_ADAPTER_SECRET override these values so secrets can come from AWS Secrets Manager.';
$string['settings_course'] = 'Annual course';
$string['settings_course_desc'] = 'Existing annual training course. The course and its content are never reset course-wide.';
$string['settings_notifications'] = 'Notifications';
$string['settings_notifications_desc'] = 'Emails go through Moodle messaging and the site SMTP configuration (SES in AWS, Mailpit locally). No reminder schedule is enabled by default because HR has not approved one.';
$string['settings_storage'] = 'Certificate storage (S3)';
$string['settings_storage_desc'] = 'When a bucket is set, each completed certificate is uploaded as a PDF and a PNG. The completion email links to the public PDF and shows the PNG. Only the key prefix should be publicly readable, and bucket listing must stay private. Each setting can be overridden by an environment variable: CAREGIVERTRAINING_S3_BUCKET, _REGION, _ENDPOINT, _PUBLIC_URL, _PREFIX, _ACCESS_KEY and _SECRET_KEY.';
$string['settings_time'] = 'Five-hour time policy';
$string['settings_time_desc'] = 'HR has not approved whether five hours means tracked time or nominal duration, or which activities count. While the policy is "Unresolved", time is recorded for evidence only and course completion is blocked.';
$string['showarchived'] = 'Show archived';
$string['snapshotfailed'] = 'Evidence snapshot failed: {$a}';
$string['strftimedue'] = '%B %d, %Y';
$string['tab_blocked'] = 'Blocked resets';
$string['tab_cycles'] = 'Current cycles';
$string['tab_exceptions'] = 'Identity and data exceptions';
$string['tab_snapshots'] = 'Prior-cycle snapshots';
$string['task_deliver_outbox'] = 'Deliver caregiver training events to the adapter';
$string['task_process_cycles'] = 'Start due caregiver cycles and reconcile completion';
$string['task_send_notifications'] = 'Send caregiver training notifications';
$string['template_body'] = '{$a} body';
$string['template_desc'] = 'Placeholders: {firstname}, {lastname}, {opendate} (60 days before the anniversary), {duedate} (the anniversary date), {accessenddate} (14 days after the anniversary), {courseurl}, {cycleid}, {certificatecode}, {certificateurl}.';
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
