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

$string['adapterenabled'] = 'Send completions to AlayaCare';
$string['adapterenabled_desc'] = 'Leave off until the adapter address and secret below are set and tested.';
$string['adaptersecret'] = 'Signing secret';
$string['adaptersecret_desc'] = 'Shared with the adapter so it can check that events really come from Moodle.';
$string['adapterurl'] = 'Adapter address';
$string['adapterurl_desc'] = 'Must start with https://. http:// is only accepted for localhost, for local testing.';
$string['adminview'] = 'Training records';
$string['allstatuses'] = 'All statuses';
$string['apply'] = 'Apply';
$string['archivecycle'] = 'Archive';
$string['archived'] = 'Archived';
$string['banner_complete'] = 'Completed on {$a}';
$string['banner_dayremaining'] = '{$a} day remaining';
$string['banner_daysremaining'] = '{$a} days remaining';
$string['banner_due'] = 'Due {$a}';
$string['banner_overdue'] = 'Overdue by {$a->days} day(s). The course remains open until {$a->accessend}.';
$string['banner_percent'] = 'About {$a}%';
$string['banner_scheduled'] = 'Opens on {$a}';
$string['banner_time'] = 'Course Total: {$a->approved} of {$a->required}';
$string['banner_time_unresolved'] = 'Recorded activity time: {$a}. HR has not yet approved how the five hours are counted, so this time is not final and completion is on hold.';
$string['banner_title'] = 'Annual Caregiver Training';
$string['caregivertraining:adapterapi'] = 'Call the caregiver training adapter web services';
$string['caregivertraining:export'] = 'Export caregiver training evidence';
$string['caregivertraining:managecycles'] = 'Retry blocked resets and archive caregiver training cycles';
$string['caregivertraining:recordtime'] = 'Record caregiver training time';
$string['caregivertraining:viewreports'] = 'View caregiver training exceptions and employee training history';
$string['certificatecmid'] = 'Certificate activity';
$string['certificatecmid_choose'] = 'The annual training course changed, so choose its certificate activity.';
$string['certificatecmid_desc'] = 'The Custom certificate activity in the annual course. The list follows the course chosen above; when the course has only one certificate, it is chosen for you.';
$string['certificatecmid_none'] = 'The annual training course has no Custom certificate activity. Add one to the course, then choose it here.';
$string['certificatefingerprint'] = 'Locked certificate design (optional)';
$string['certificatefingerprint_desc'] = 'Paste the fingerprint below to lock the approved design. While locked, certificates are only issued if the layout and images still match; otherwise completions wait and an exception is listed in the Caregiver training report. Wording changes are allowed. Leave empty to accept any complete design.<br>Current certificate: <strong>{$a->name}</strong>, fingerprint <code>{$a->fingerprint}</code>. Problems: {$a->problems}.';
$string['certificatepdf'] = 'Certificate PDF';
$string['clearfilters'] = 'Clear filters';
$string['col_accessend'] = 'Access ends';
$string['col_alayacareid'] = 'AlayaCare ID';
$string['col_certificate'] = 'Certificate';
$string['col_completed'] = 'Completed';
$string['col_coursetotal'] = 'Course total';
$string['col_cycleid'] = 'Cycle ID';
$string['col_cycles'] = 'Cycles';
$string['col_details'] = 'Details';
$string['col_due'] = 'Due date';
$string['col_employee'] = 'Employee';
$string['col_evidence'] = 'Evidence kept';
$string['col_latestcycle'] = 'Latest cycle';
$string['col_reason'] = 'Reason';
$string['col_status'] = 'Status';
$string['col_time'] = 'Approved time';
$string['col_trainingyear'] = 'Year';
$string['col_type'] = 'Type';
$string['col_updated'] = 'Last updated';
$string['compliance_complete'] = 'Complete';
$string['compliance_notapplicable'] = 'Not applicable';
$string['compliance_open'] = 'Open';
$string['compliance_overdue'] = 'Overdue';
$string['compliance_upcoming'] = 'Upcoming';
$string['compliancetimezone'] = 'Compliance timezone';
$string['compliancetimezone_desc'] = 'Due dates are counted in this timezone. Training is due by 23:59 on the anniversary date, opens 60 days before, and stays available for 14 days after.';
$string['confirm_archive'] = 'Archive cycle {$a}? It stays in the employee\'s history and is no longer shown in the learner\'s banner.';
$string['confirm_resolve'] = 'Mark this exception as resolved?';
$string['confirm_retry'] = 'Take the evidence snapshot and reset progress again for cycle {$a}?';
$string['countablecmids'] = 'Activities that count';
$string['countablecmids_desc'] = 'Hold Ctrl (Cmd on a Mac) to select several.';
$string['countallactivities'] = 'Count every activity in the course';
$string['countallactivities_desc'] = 'Includes activities added later. Turn off to pick the activities yourself.';
$string['courseid'] = 'Annual training course';
$string['courseid_desc'] = 'The existing course caregivers complete every year. Type to search.';
$string['csv_accessenddate'] = 'Access ends';
$string['csv_alayacareid'] = 'AlayaCare ID';
$string['csv_approvedhours'] = 'Approved training hours';
$string['csv_archived'] = 'Archived';
$string['csv_certificatecode'] = 'Certificate code';
$string['csv_certificateurl'] = 'Certificate verification link';
$string['csv_completedon'] = 'Completed on';
$string['csv_coursetotal'] = 'Course total grade';
$string['csv_cycleid'] = 'Cycle ID';
$string['csv_duedate'] = 'Due date (anniversary)';
$string['csv_employee'] = 'Employee name';
$string['csv_evidence'] = 'Evidence kept';
$string['csv_hcanumber'] = 'HCA number';
$string['csv_hiredate'] = 'Hire date';
$string['csv_ontime'] = 'Completed on time';
$string['csv_payrollnumber'] = 'Payroll number';
$string['csv_registrationdate'] = 'HCA registration date';
$string['csv_status'] = 'Status';
$string['csv_trainingyear'] = 'Training year';
$string['default_body_activation'] = 'Hello {firstname},

Welcome! We\'ve created your CI Institute of Nursing account so you can complete your various trainings.

Your username: {username}

To get started, choose your password using the link below. For your security, it expires in {expiry}.
{link}

If the link has expired, no problem. Go to the lost password page and enter your email to receive a new password reset link.
{lostpasswordurl}

We\'re glad to have you with us.
CI Institute of Nursing';
$string['default_body_completion'] = 'Hello {firstname},

Congratulations on completing your annual caregiver training! Thank you for your commitment to safe, quality care.

Your certificate is attached to this email. You can also view it anytime here:
Certificate code: {certificatecode}
Certificate: {certificateurl}

We appreciate you.
CI Institute of Nursing';
$string['default_body_due'] = 'Hello {firstname},

Today, {duedate}, is the due date for your annual caregiver training. If you haven\'t finished yet, there\'s still time today.

Continue here: {courseurl}

If something is getting in the way, reach out. We\'re happy to help.
CI Institute of Nursing';
$string['default_body_overdue'] = 'Hello {firstname},

Your annual caregiver training was due on {duedate} and isn\'t complete yet. The good news is that your course is still available until {accessenddate}, so you can finish it now.

Continue here: {courseurl}

If you\'re running into any trouble, please let us know. We\'re here to help you get it done.
CI Institute of Nursing';
$string['default_body_reminder'] = 'Hello {firstname},

Just a friendly reminder that your annual caregiver training is due on or before {duedate}.

Pick up where you left off: {courseurl}

Thank you for keeping your training up to date.
CI Institute of Nursing';
$string['default_body_windowopen'] = 'Hello {firstname},

Good news: your annual caregiver training is now open. You have plenty of time to complete it at your own pace. Please finish on or before {duedate}.

Start your training here: {courseurl}

Thank you for the care you give every day.
CI Institute of Nursing';
$string['default_subject_activation'] = 'Welcome: Set Up Your Training Account';
$string['default_subject_completion'] = 'Completed: Your Training Certificate Is Ready';
$string['default_subject_due'] = 'Due Today: Your Annual Training';
$string['default_subject_overdue'] = 'Overdue: Please Complete Your Annual Training';
$string['default_subject_reminder'] = 'Reminder: Your Annual Training Is Due {duedate}';
$string['default_subject_windowopen'] = 'Now Open: Your Annual Training, Due {duedate}';
$string['done_archive'] = 'Cycle archived.';
$string['done_resolve'] = 'Exception marked as resolved.';
$string['done_retry'] = 'Reset completed. The cycle is open.';
$string['done_retryfailed'] = 'The reset is still blocked: {$a}';
$string['downloadhistory'] = 'Download training history as';
$string['duration_hr'] = '{$a} hr';
$string['duration_hrs'] = '{$a} hrs';
$string['duration_min'] = '{$a} min';
$string['duration_mins'] = '{$a} mins';
$string['email_certificate_imagealt'] = 'Your certificate of completion';
$string['email_cta_activation'] = 'Set Your Password';
$string['email_cta_completion'] = 'View Certificate';
$string['email_cta_due'] = 'Continue Training';
$string['email_cta_overdue'] = 'Continue Training';
$string['email_cta_reminder'] = 'Continue Training';
$string['email_cta_windowopen'] = 'Continue Training';
$string['email_footer_guide'] = 'Our Quick Start Guide walks you through it:';
$string['email_footer_help'] = 'Need a hand?';
$string['email_footer_hr'] = 'HR Compliance:';
$string['email_footer_platform'] = 'Trouble logging in or using the platform?';
$string['email_footer_systems'] = 'CI-ION Systems:';
$string['email_footer_training'] = 'Questions about your training or due date?';
$string['email_label_activation'] = 'Account Setup';
$string['email_label_completion'] = 'Completed';
$string['email_label_due'] = 'Due Today';
$string['email_label_overdue'] = 'Overdue';
$string['email_label_reminder'] = 'Reminder';
$string['email_label_windowopen'] = 'Training is Open';
$string['email_preheader_activation'] = 'Set your password to get started.';
$string['email_preheader_completion'] = 'Your certificate is ready.';
$string['email_preheader_due'] = 'Due today, {$a}.';
$string['email_preheader_overdue'] = 'It was due on {$a}. You can still finish it.';
$string['email_preheader_reminder'] = 'Due on or before {$a}.';
$string['email_preheader_windowopen'] = 'Complete it on or before {$a}.';
$string['envoverride'] = 'The server environment variable <code>{$a}</code> is set, so it is used instead of the value saved here.';
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
$string['exception_open'] = 'Open';
$string['exception_resolved'] = 'Resolved';
$string['excludedmodules'] = 'Activity types that don\'t count';
$string['excludedmodules_desc'] = 'For example the certificate or a feedback survey.';
$string['field_alayacareid'] = 'AlayaCare ID';
$string['field_desc'] = 'Managed by the AlayaCare integration. Learners cannot edit this field.';
$string['field_hcanumber'] = 'HCA number';
$string['field_payrollnumber'] = 'Payroll number';
$string['field_registrationdate'] = 'HCA registration date';
$string['heartbeatseconds'] = 'Check-in interval';
$string['heartbeatseconds_desc'] = 'How often the learner\'s browser reports that they are still active (10 seconds to 5 minutes).';
$string['hrccemails'] = 'Copy HR on emails';
$string['hrccemails_desc'] = 'Addresses blind-copied (BCC) on every email sent to learners, separated by commas. Learners can\'t see them.';
$string['hrcctypes'] = 'Copy HR on';
$string['hremail'] = 'HR Compliance email';
$string['hrphone'] = 'HR Compliance phone';
$string['hrphone_desc'] = 'Shown as written and linked so it can be tapped to call, for example (650) 278-8013.';
$string['idleseconds'] = 'Idle after';
$string['idleseconds_desc'] = 'Time stops counting when the learner has not clicked, typed or scrolled for this long and no video is playing.';
$string['maxattempts'] = 'Retry limit';
$string['maxattempts_desc'] = 'How many times to try sending an event before listing it as an exception.';
$string['messageprovider:completion'] = 'Annual training completed';
$string['messageprovider:due'] = 'Annual training due today';
$string['messageprovider:overdue'] = 'Annual training overdue';
$string['messageprovider:reminder'] = 'Annual training reminder';
$string['messageprovider:windowopen'] = 'Annual training window opened';
$string['missingactivity'] = 'Activity {$a} (no longer in the annual course)';
$string['moodleaccount'] = 'Moodle account';
$string['nextactivity'] = 'Next activity';
$string['nextactivity_locked'] = 'Complete this activity to unlock the next one.';
$string['noblocked'] = 'No blocked resets.';
$string['nocourseactivities'] = 'Choose the annual training course in General settings to see its activities here.';
$string['nocycles'] = 'This employee has no training cycles yet.';
$string['noemployees'] = 'No employees are enrolled in the annual training yet.';
$string['noemployeesmatch'] = 'No employees match your search.';
$string['noexceptions'] = 'No open exceptions.';
$string['nominaldurations'] = 'Time per activity';
$string['nominaldurations_desc'] = 'One entry per activity, as activity number and seconds, for example <code>{"12": 1800, "13": 2700}</code> (1800 seconds = 30 minutes). The annual course\'s activity numbers:';
$string['nonextactivity'] = 'All available activities are complete.';
$string['notify_completion'] = 'Completion email';
$string['notify_completion_desc'] = 'Sent with the certificate when training is completed.';
$string['notify_due'] = 'Due today email';
$string['notify_due_desc'] = 'Sent on the due date if training isn\'t finished.';
$string['notify_overdue'] = 'Overdue email';
$string['notify_overdue_desc'] = 'Sent once after the due date if training isn\'t finished.';
$string['notify_windowopen'] = 'Training open email';
$string['notify_windowopen_desc'] = 'Sent when the training opens, 60 days before the due date.';
$string['notset'] = 'Not set';
$string['page_local_caregivertraining'] = 'General settings';
$string['page_local_caregivertraining_integration'] = 'AlayaCare integration';
$string['page_local_caregivertraining_notifications'] = 'Notifications';
$string['page_local_caregivertraining_storage'] = 'Certificate storage';
$string['page_local_caregivertraining_templates'] = 'Email templates';
$string['page_local_caregivertraining_time'] = 'Training time';
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
$string['privacy:metadata:preference:activationpending'] = 'Whether the account setup email is waiting for the learner\'s training to open.';
$string['privacy:metadata:s3'] = 'When certificate storage is configured, completed certificates are published to an S3 bucket at an unguessable public address.';
$string['privacy:metadata:s3:certificate'] = 'The certificate of completion as a PDF and an image, showing the learner\'s name, completion date and certificate code.';
$string['privacy:retained'] = 'Records inside the retention period are kept to meet the compliance retention obligation.';
$string['profilecategory'] = 'Caregiver employment (managed by AlayaCare integration)';
$string['quickstartguideurl'] = 'Quick Start Guide link';
$string['quickstartguideurl_desc'] = 'The guide linked under "Need a hand?". Leave empty until the guide is published.';
$string['reminderoffsets'] = 'Reminder emails';
$string['reminderoffsets_desc'] = 'Days before the due date to send a reminder if training isn\'t finished, separated by commas, for example 30,14,7. Leave empty for no reminders.';
$string['requiredseconds'] = 'Minimum training time';
$string['requiredseconds_desc'] = 'Set to 0 when completing the course is enough.';
$string['resetfailed'] = 'Per-learner reset refused: {$a}';
$string['resolveexception'] = 'Mark as resolved';
$string['retentionyears'] = 'Keep completion records for (years)';
$string['retentionyears_desc'] = 'At least 3. Records are never deleted automatically.';
$string['retryreset'] = 'Retry reset';
$string['roleid'] = 'Learner role';
$string['roleid_default'] = 'Default (Student)';
$string['roleid_desc'] = 'The role caregivers get in the course.';
$string['s3accesskey'] = 'S3 access key';
$string['s3accesskey_desc'] = 'Leave empty to use the server\'s AWS credentials, such as the ECS task role. Recommended in AWS.';
$string['s3bucket'] = 'S3 bucket';
$string['s3bucket_desc'] = 'Leave empty to keep certificates in Moodle only.';
$string['s3endpoint'] = 'S3 endpoint';
$string['s3endpoint_desc'] = 'Only for S3-compatible storage such as MinIO, for example http://minio:9000. Leave empty for AWS.';
$string['s3prefix'] = 'S3 key prefix';
$string['s3prefix_desc'] = 'Folder within the bucket.';
$string['s3publicurl'] = 'Public base URL';
$string['s3publicurl_desc'] = 'Leave empty to use https://BUCKET.s3.REGION.amazonaws.com, or enter a CloudFront address.';
$string['s3region'] = 'S3 region';
$string['s3region_desc'] = 'The AWS region of the bucket.';
$string['s3secretkey'] = 'S3 secret key';
$string['s3secretkey_desc'] = 'Leave empty to use the server\'s AWS credentials.';
$string['searchemployees'] = 'Search by name, AlayaCare ID, payroll or HCA number, Cycle ID or certificate code';
$string['settings_adapter_desc'] = 'Every completion is recorded in Moodle. When sending is switched on, it is also sent to the AlayaCare adapter, and failed sends are retried automatically.';
$string['settings_certificate'] = 'Certificate';
$string['settings_emailfooter'] = 'Email footer';
$string['settings_emailfooter_desc'] = 'Every email ends with the same help and contact details. Change them here to update all emails at once. Leave a field empty to leave that line out.';
$string['settings_storage_desc'] = 'Optional. Also publish each certificate as a public PDF and image in an S3 bucket, so the completion email can link to it. Only the key prefix should be publicly readable, and bucket listing must stay private.';
$string['settings_time_desc'] = 'Learners complete their training when they complete the course (set in the course\'s Course completion settings) and have spent the minimum time below.';
$string['setup_design'] = 'The certificate design is incomplete: {$a}';
$string['setup_noactivities'] = 'Choose the activities that count toward the training time.';
$string['setup_nocertificate'] = 'Choose the certificate activity.';
$string['setup_nocourse'] = 'Choose the annual training course.';
$string['setup_nopolicy'] = 'Choose how training time is counted.';
$string['setup_warnings'] = 'Learners can\'t complete training until these are fixed:';
$string['showopenonly'] = 'Show open only';
$string['showresolved'] = 'Show resolved too';
$string['snapshotfailed'] = 'Evidence snapshot failed: {$a}';
$string['snapshotlabel'] = '{$a->type}, {$a->date} ({$a->verified})';
$string['snapshottype_completion'] = 'At completion';
$string['snapshottype_preexisting'] = 'Progress before the first cycle';
$string['snapshottype_prereset'] = 'Before the yearly reset';
$string['snapshotunverified'] = 'not verified';
$string['snapshotverified'] = 'verified';
$string['status_blocked'] = 'Blocked (reset failed)';
$string['strftimedue'] = '%B %d, %Y';
$string['supportemail'] = 'Platform support email';
$string['systemroleid'] = 'Learner system role';
$string['systemroleid_desc'] = 'The site-level role caregivers get when the adapter creates or links their account, for example Home Care Aide. It is added, never removed.';
$string['systemroleid_none'] = 'None';
$string['task_deliver_outbox'] = 'Deliver caregiver training events to the adapter';
$string['task_process_cycles'] = 'Start due caregiver cycles and reconcile completion';
$string['task_send_notifications'] = 'Send caregiver training notifications';
$string['template_body'] = 'Message';
$string['template_desc'] = 'Change the wording of the emails. Empty a field to go back to the default wording. The main link in each message (for example {courseurl}) is shown as a button. Placeholders: {firstname}, {lastname}, {opendate}, {duedate}, {accessenddate}, {courseurl}, {cycleid}, {certificatecode}, {certificateurl}, {loginurl}, {lostpasswordurl}, {quickstartguideurl}. The account setup email also has {username}, {expiry} and {link}.';
$string['template_subject'] = 'Subject';
$string['timepolicy'] = 'How time is counted';
$string['timepolicy_desc'] = '<strong>Not decided yet</strong>: time is recorded but nobody can complete. <strong>Time actually spent</strong>: the time learners are active in the selected activities. <strong>Set time per activity</strong>: each selected activity is worth a fixed time once it is complete.';
$string['timepolicy_nominal'] = 'Set time per activity';
$string['timepolicy_tracked'] = 'Time actually spent';
$string['timepolicy_unresolved'] = 'Not decided yet (blocks completion)';
$string['traininghistory'] = 'Training history';
$string['type_activation'] = 'Account setup email';
$string['type_completion'] = 'Completion email';
$string['type_due'] = 'Due today email';
$string['type_overdue'] = 'Overdue email';
$string['type_reminder'] = 'Reminder email';
$string['type_windowopen'] = 'Training open email';
$string['view_blocked'] = 'Blocked resets ({$a})';
$string['view_employees'] = 'Employees';
$string['view_exceptions'] = 'Exceptions ({$a})';
