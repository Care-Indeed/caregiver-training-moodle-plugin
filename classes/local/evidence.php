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

use core_privacy\local\request\approved_userlist;
use core_privacy\local\request\userlist;

/**
 * Per-learner evidence: snapshot capture/verification and the verified per-learner reset.
 *
 * The reset never touches core tables directly. It calls each component's privacy provider
 * (the supported per-user, per-context deletion API), then recomputes grades through the module
 * grade APIs, all inside one delegated transaction that is rolled back unless every
 * post-condition holds.
 *
 * @package    local_caregivertraining
 * @copyright  2026 CI Institute of Nursing
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class evidence {
    /** @var string Snapshot payload schema. */
    const SCHEMA = 'cgt.snapshot.v1';

    /** @var string[] Modules whose user data this plugin knows how to snapshot and reset. */
    const RESETTABLE_MODULES = ['lesson', 'quiz', 'feedback', 'customcert'];

    /** @var string[] Modules that keep no per-user data beyond completion/view state. */
    const NO_USERDATA_MODULES = ['page', 'url', 'resource', 'label', 'book', 'folder', 'subsection', 'qbank'];

    /**
     * Course-scoped count queries. Each returns rows for one learner (userid = :u) or for
     * everyone else (userid <> :u).
     *
     * @return array<string,array{0:string,1:string}> name => [from/where sql, user column]
     */
    private static function count_sources(): array {
        return [
            'coursecompletion' => ['{course_completions} x WHERE x.course = :c', 'x.userid'],
            'critcompl' => ['{course_completion_crit_compl} x WHERE x.course = :c', 'x.userid'],
            'cmcompletion' => ['{course_modules_completion} x JOIN {course_modules} cm ON cm.id = x.coursemoduleid
                WHERE cm.course = :c', 'x.userid'],
            'cmviewed' => ['{course_modules_viewed} x JOIN {course_modules} cm ON cm.id = x.coursemoduleid
                WHERE cm.course = :c', 'x.userid'],
            'lessonattempts' => ['{lesson_attempts} x JOIN {lesson} l ON l.id = x.lessonid WHERE l.course = :c', 'x.userid'],
            'lessongrades' => ['{lesson_grades} x JOIN {lesson} l ON l.id = x.lessonid WHERE l.course = :c', 'x.userid'],
            'lessontimer' => ['{lesson_timer} x JOIN {lesson} l ON l.id = x.lessonid WHERE l.course = :c', 'x.userid'],
            'lessonbranch' => ['{lesson_branch} x JOIN {lesson} l ON l.id = x.lessonid WHERE l.course = :c', 'x.userid'],
            'quizattempts' => ['{quiz_attempts} x JOIN {quiz} q ON q.id = x.quiz WHERE q.course = :c', 'x.userid'],
            'quizgrades' => ['{quiz_grades} x JOIN {quiz} q ON q.id = x.quiz WHERE q.course = :c', 'x.userid'],
            'feedbackcompleted' => ['{feedback_completed} x JOIN {feedback} f ON f.id = x.feedback WHERE f.course = :c',
                'x.userid'],
            'feedbackvalues' => ['{feedback_value} x JOIN {feedback_completed} fc ON fc.id = x.completed
                JOIN {feedback} f ON f.id = fc.feedback WHERE f.course = :c', 'fc.userid'],
            'modgrades' => ['{grade_grades} x JOIN {grade_items} gi ON gi.id = x.itemid
                WHERE gi.courseid = :c AND gi.itemtype = \'mod\' AND x.finalgrade IS NOT NULL', 'x.userid'],
            'certissues' => ['{customcert_issues} x JOIN {customcert} cc ON cc.id = x.customcertid WHERE cc.course = :c',
                'x.userid'],
        ];
    }

    /**
     * Live evidence counts for one learner.
     *
     * @param int $userid
     * @param int $courseid
     * @return array<string,int>
     */
    public static function live_counts(int $userid, int $courseid): array {
        global $DB;
        $counts = [];
        foreach (self::count_sources() as $name => [$from, $usercol]) {
            $counts[$name] = (int) $DB->count_records_sql(
                "SELECT COUNT(1) FROM {$from} AND {$usercol} = :u",
                ['c' => $courseid, 'u' => $userid]
            );
        }
        return $counts;
    }

    /**
     * Fingerprint (count and id sum) of every other learner's evidence, used to prove isolation.
     *
     * @param int $userid
     * @param int $courseid
     * @return array<string,string>
     */
    public static function others_fingerprint(int $userid, int $courseid): array {
        global $DB;
        $result = [];
        foreach (self::count_sources() as $name => [$from, $usercol]) {
            $row = $DB->get_record_sql(
                "SELECT COUNT(1) AS n, COALESCE(SUM(x.id), 0) AS s FROM {$from} AND {$usercol} <> :u",
                ['c' => $courseid, 'u' => $userid]
            );
            $result[$name] = $row->n . ':' . $row->s;
        }
        return $result;
    }

    /**
     * Whether any evidence exists.
     *
     * @param array $counts
     * @return bool
     */
    public static function has_evidence(array $counts): bool {
        return array_sum($counts) > 0;
    }

    /**
     * Fetch rows as plain arrays ordered by id.
     *
     * @param string $sql
     * @param array $params
     * @return array
     */
    private static function rows(string $sql, array $params): array {
        global $DB;
        return array_values(array_map(fn($r) => (array) $r, $DB->get_records_sql($sql, $params)));
    }

    /**
     * Capture the learner's full evidence for the course.
     *
     * @param int $userid
     * @param \stdClass $course
     * @param \stdClass|null $cycle cycle the evidence belongs to
     * @return array{payload: array, counts: array, pdfs: array<string,string>}
     */
    public static function capture(int $userid, \stdClass $course, ?\stdClass $cycle): array {
        global $CFG, $DB;
        $p = ['c' => $course->id, 'u' => $userid];

        $data = [
            'coursecompletion' => self::rows('SELECT id, timeenrolled, timestarted, timecompleted, reaggregate
                FROM {course_completions} WHERE course = :c AND userid = :u ORDER BY id', $p),
            'critcompl' => self::rows('SELECT id, criteriaid, gradefinal, unenroled, timecompleted
                FROM {course_completion_crit_compl} WHERE course = :c AND userid = :u ORDER BY id', $p),
            'cmcompletion' => self::rows('SELECT x.id, x.coursemoduleid, m.name AS modname, cm.instance, x.completionstate,
                    x.overrideby, x.timemodified
                FROM {course_modules_completion} x JOIN {course_modules} cm ON cm.id = x.coursemoduleid
                JOIN {modules} m ON m.id = cm.module
                WHERE cm.course = :c AND x.userid = :u ORDER BY x.id', $p),
            'cmviewed' => self::rows('SELECT x.id, x.coursemoduleid, x.timecreated
                FROM {course_modules_viewed} x JOIN {course_modules} cm ON cm.id = x.coursemoduleid
                WHERE cm.course = :c AND x.userid = :u ORDER BY x.id', $p),
            'lessonattempts' => self::rows('SELECT x.id, x.lessonid, x.pageid, x.answerid, x.retry, x.correct, x.useranswer,
                    x.timeseen
                FROM {lesson_attempts} x JOIN {lesson} l ON l.id = x.lessonid
                WHERE l.course = :c AND x.userid = :u ORDER BY x.id', $p),
            'lessongrades' => self::rows('SELECT x.id, x.lessonid, x.grade, x.late, x.completed
                FROM {lesson_grades} x JOIN {lesson} l ON l.id = x.lessonid
                WHERE l.course = :c AND x.userid = :u ORDER BY x.id', $p),
            'lessontimer' => self::rows('SELECT x.id, x.lessonid, x.starttime, x.lessontime, x.completed, x.timemodifiedoffline
                FROM {lesson_timer} x JOIN {lesson} l ON l.id = x.lessonid
                WHERE l.course = :c AND x.userid = :u ORDER BY x.id', $p),
            'lessonbranch' => self::rows('SELECT x.id, x.lessonid, x.pageid, x.retry, x.flag, x.timeseen, x.nextpageid
                FROM {lesson_branch} x JOIN {lesson} l ON l.id = x.lessonid
                WHERE l.course = :c AND x.userid = :u ORDER BY x.id', $p),
            'quizattempts' => self::rows('SELECT x.id, x.quiz, x.attempt, x.uniqueid, x.state, x.timestart, x.timefinish,
                    x.timemodified, x.sumgrades
                FROM {quiz_attempts} x JOIN {quiz} q ON q.id = x.quiz
                WHERE q.course = :c AND x.userid = :u ORDER BY x.id', $p),
            'quizquestions' => self::rows('SELECT qa.id, qza.id AS attemptid, qa.slot, qa.questionid, qa.maxmark,
                    qa.questionsummary, qa.rightanswer, qa.responsesummary, qa.timemodified
                FROM {question_attempts} qa
                JOIN {quiz_attempts} qza ON qza.uniqueid = qa.questionusageid
                JOIN {quiz} q ON q.id = qza.quiz
                WHERE q.course = :c AND qza.userid = :u ORDER BY qa.id', $p),
            'quizgrades' => self::rows('SELECT x.id, x.quiz, x.grade, x.timemodified
                FROM {quiz_grades} x JOIN {quiz} q ON q.id = x.quiz
                WHERE q.course = :c AND x.userid = :u ORDER BY x.id', $p),
            'feedbackcompleted' => self::rows('SELECT x.id, x.feedback, x.timemodified, x.anonymous_response
                FROM {feedback_completed} x JOIN {feedback} f ON f.id = x.feedback
                WHERE f.course = :c AND x.userid = :u ORDER BY x.id', $p),
            'feedbackvalues' => self::rows('SELECT x.id, x.completed, x.item, x.value
                FROM {feedback_value} x JOIN {feedback_completed} fc ON fc.id = x.completed
                JOIN {feedback} f ON f.id = fc.feedback
                WHERE f.course = :c AND fc.userid = :u ORDER BY x.id', $p),
            'grades' => self::rows('SELECT x.id, gi.id AS itemid, gi.itemtype, gi.itemmodule, gi.iteminstance, gi.itemname,
                    gi.grademax, x.rawgrade, x.finalgrade, x.overridden, x.timemodified
                FROM {grade_grades} x JOIN {grade_items} gi ON gi.id = x.itemid
                WHERE gi.courseid = :c AND x.userid = :u ORDER BY x.id', $p),
            'certissues' => self::rows('SELECT x.id, x.customcertid, x.code, x.emailed, x.timecreated
                FROM {customcert_issues} x JOIN {customcert} cc ON cc.id = x.customcertid
                WHERE cc.course = :c AND x.userid = :u ORDER BY x.id', $p),
        ];

        $certificates = [];
        $pdfs = [];
        foreach ($data['certissues'] as $issue) {
            $customcert = $DB->get_record('customcert', ['id' => $issue['customcertid']], '*', MUST_EXIST);
            $templaterecord = $DB->get_record('customcert_templates', ['id' => $customcert->templateid], '*', MUST_EXIST);
            $template = new \mod_customcert\template($templaterecord);
            $pdf = $template->generate_pdf(false, $userid, true);
            if (!is_string($pdf) || $pdf === '') {
                throw new \moodle_exception('snapshotfailed', config::COMPONENT, '', 'certificate PDF generation failed');
            }
            $filename = clean_param($issue['code'], PARAM_ALPHANUMEXT) . '.pdf';
            $pdfs[$filename] = $pdf;
            $certificates[] = ['issueid' => (int) $issue['id'], 'code' => $issue['code'],
                'timecreated' => (int) $issue['timecreated'], 'filename' => $filename, 'pdfsha256' => hash('sha256', $pdf),
                'note' => 'PDF re-rendered from the live issue at snapshot time'];
        }

        $sessions = $cycle ? self::rows('SELECT cmid, SUM(creditedseconds) AS seconds, SUM(beats) AS beats,
                SUM(rejectedbeats) AS rejected, MIN(timestarted) AS firstseen, MAX(timelastbeat) AS lastseen
            FROM {local_cgt_timesession} WHERE cycleid = :cycle GROUP BY cmid ORDER BY cmid', ['cycle' => $cycle->id]) : [];

        $counts = self::live_counts($userid, (int) $course->id);
        $payload = [
            'schema' => self::SCHEMA,
            'capturedat' => time(),
            'moodlerelease' => $CFG->release,
            'pluginversion' => get_config(config::COMPONENT, 'version'),
            'userid' => $userid,
            'courseid' => (int) $course->id,
            'cycle' => $cycle ? [
                'cycleid' => $cycle->cycleid,
                'anniversarydate' => $cycle->anniversarydate,
                'hiredate' => $cycle->hiredate,
                'status' => $cycle->status,
                'timecompleted' => $cycle->timecompleted ? (int) $cycle->timecompleted : null,
                'approvedseconds' => (int) $cycle->approvedseconds,
                'timepolicy' => $cycle->timepolicy,
                'certificatecode' => $cycle->certificatecode,
            ] : null,
            'time' => $sessions,
            'counts' => $counts,
            'data' => $data,
            'certificates' => $certificates,
        ];
        return ['payload' => $payload, 'counts' => $counts, 'pdfs' => $pdfs];
    }

    /**
     * Capture, store and verify a snapshot. Unverified rows are kept (never discard evidence).
     *
     * @param \stdClass $cycle cycle the evidence belongs to
     * @param string $type completion|prereset|preexisting
     * @param \stdClass $course
     * @return \stdClass verified snapshot record
     */
    public static function create_snapshot(\stdClass $cycle, string $type, \stdClass $course): \stdClass {
        global $DB;
        $userid = (int) $cycle->userid;
        $captured = self::capture($userid, $course, $cycle);
        $json = json_encode($captured['payload'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($json === false) {
            throw new \moodle_exception('snapshotfailed', config::COMPONENT, '', 'payload not encodable');
        }
        $now = time();
        $snapshot = (object) [
            'cycleid' => $cycle->id,
            'userid' => $userid,
            'courseid' => $course->id,
            'type' => $type,
            'payload' => $json,
            'payloadhash' => hash('sha256', $json),
            'counts' => json_encode($captured['counts']),
            'verified' => 0,
            'timeverified' => null,
            'retainuntil' => strtotime('+' . config::retention_years() . ' years', $now),
            'timecreated' => $now,
        ];
        $snapshot->id = $DB->insert_record('local_cgt_snapshot', $snapshot);

        $fs = get_file_storage();
        $context = \context_system::instance();
        foreach ($captured['pdfs'] as $filename => $content) {
            $fs->create_file_from_string([
                'contextid' => $context->id,
                'component' => config::COMPONENT,
                'filearea' => 'snapshotcert',
                'itemid' => $snapshot->id,
                'filepath' => '/',
                'filename' => $filename,
                'userid' => $userid,
            ], $content);
        }

        if (!self::verify_snapshot($snapshot, self::live_counts($userid, (int) $course->id))) {
            throw new \moodle_exception(
                'snapshotfailed',
                config::COMPONENT,
                '',
                'snapshot ' . $snapshot->id . ' failed verification'
            );
        }
        $DB->set_field('local_cgt_snapshot', 'verified', 1, ['id' => $snapshot->id]);
        $DB->set_field('local_cgt_snapshot', 'timeverified', time(), ['id' => $snapshot->id]);
        return $DB->get_record('local_cgt_snapshot', ['id' => $snapshot->id], '*', MUST_EXIST);
    }

    /**
     * Verify a stored snapshot against its hash, stored files and (optionally) live counts.
     *
     * @param \stdClass $snapshot
     * @param array|null $expectedcounts live counts that must match the captured counts
     * @return bool
     */
    public static function verify_snapshot(\stdClass $snapshot, ?array $expectedcounts = null): bool {
        global $DB;
        $stored = $DB->get_record('local_cgt_snapshot', ['id' => $snapshot->id]);
        if (!$stored || hash('sha256', $stored->payload) !== $stored->payloadhash) {
            return false;
        }
        $payload = json_decode($stored->payload, true);
        if (
            !is_array($payload) || ($payload['schema'] ?? '') !== self::SCHEMA
                || (int) $payload['userid'] !== (int) $stored->userid
        ) {
            return false;
        }
        if ($expectedcounts !== null && $payload['counts'] != $expectedcounts) {
            return false;
        }
        $fs = get_file_storage();
        $context = \context_system::instance();
        foreach ($payload['certificates'] as $certificate) {
            $file = $fs->get_file($context->id, config::COMPONENT, 'snapshotcert', $stored->id, '/', $certificate['filename']);
            if (!$file || hash('sha256', $file->get_content()) !== $certificate['pdfsha256']) {
                return false;
            }
        }
        return true;
    }

    /**
     * Modules in the course whose per-user data would survive a reset (blocks the reset).
     *
     * @param int $userid
     * @param \stdClass $course
     * @return string[] component names
     */
    public static function unsupported_evidence(int $userid, \stdClass $course): array {
        $blocking = [];
        foreach (get_fast_modinfo($course, -1)->get_cms() as $cm) {
            if (in_array($cm->modname, self::RESETTABLE_MODULES, true) || in_array($cm->modname, self::NO_USERDATA_MODULES, true)) {
                continue;
            }
            $component = 'mod_' . $cm->modname;
            $provider = "\\{$component}\\privacy\\provider";
            if (
                !class_exists($provider)
                    || !is_subclass_of($provider, \core_privacy\local\request\core_userlist_provider::class)
            ) {
                $blocking[] = $component . ' (no userlist provider)';
                continue;
            }
            $userlist = new userlist(\context_module::instance($cm->id), $component);
            $provider::get_users_in_context($userlist);
            if (in_array($userid, $userlist->get_userids())) {
                $blocking[] = $component;
            }
        }
        return array_values(array_unique($blocking));
    }

    /**
     * Reset one learner's progress in the course after a verified snapshot.
     * Throws (and rolls back) unless every post-condition holds.
     *
     * @param int $userid
     * @param \stdClass $course
     * @param \stdClass $snapshot verified snapshot of this learner's current evidence
     */
    public static function reset_learner(int $userid, \stdClass $course, \stdClass $snapshot): void {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/mod/lesson/lib.php');
        require_once($CFG->dirroot . '/mod/quiz/lib.php');
        require_once($CFG->libdir . '/gradelib.php');

        if (empty($snapshot->verified) || (int) $snapshot->userid !== $userid || (int) $snapshot->courseid !== (int) $course->id) {
            throw new \moodle_exception('resetfailed', config::COMPONENT, '', 'snapshot not verified for this learner');
        }
        $snapcounts = json_decode($snapshot->counts, true);
        if (!self::verify_snapshot($snapshot, $snapcounts)) {
            throw new \moodle_exception('resetfailed', config::COMPONENT, '', 'snapshot verification failed before reset');
        }
        if ($unsupported = self::unsupported_evidence($userid, $course)) {
            throw new \moodle_exception(
                'resetfailed',
                config::COMPONENT,
                '',
                'unsupported evidence in ' . implode(', ', $unsupported)
            );
        }

        $user = \core_user::get_user($userid, '*', MUST_EXIST);
        $othersbefore = self::others_fingerprint($userid, (int) $course->id);

        $transaction = $DB->start_delegated_transaction();
        try {
            if (self::live_counts($userid, (int) $course->id) != $snapcounts) {
                throw new \moodle_exception('resetfailed', config::COMPONENT, '', 'evidence changed after snapshot');
            }

            $cms = $DB->get_records_sql('SELECT cm.id, cm.instance, cm.idnumber, m.name AS modname
                FROM {course_modules} cm JOIN {modules} m ON m.id = cm.module
                WHERE cm.course = :c ORDER BY cm.id', ['c' => $course->id]);
            foreach ($cms as $cm) {
                $context = \context_module::instance($cm->id);
                if (in_array($cm->modname, self::RESETTABLE_MODULES, true)) {
                    $provider = "\\mod_{$cm->modname}\\privacy\\provider";
                    $provider::delete_data_for_users(new approved_userlist($context, 'mod_' . $cm->modname, [$userid]));
                }
                \core_completion\privacy\provider::delete_completion($user, null, (int) $cm->id);
            }
            \core_completion\privacy\provider::delete_completion($user, (int) $course->id);

            foreach ($cms as $cm) {
                if ($cm->modname === 'lesson') {
                    $lesson = $DB->get_record('lesson', ['id' => $cm->instance], '*', MUST_EXIST);
                    $lesson->cmidnumber = $cm->idnumber;
                    lesson_update_grades($lesson, $userid, true);
                } else if ($cm->modname === 'quiz') {
                    $quiz = $DB->get_record('quiz', ['id' => $cm->instance], '*', MUST_EXIST);
                    $quiz->cmidnumber = $cm->idnumber;
                    quiz_update_grades($quiz, $userid, true);
                }
            }

            $after = self::live_counts($userid, (int) $course->id);
            if (self::has_evidence($after)) {
                throw new \moodle_exception(
                    'resetfailed',
                    config::COMPONENT,
                    '',
                    'evidence remains after reset: ' . json_encode(array_filter($after))
                );
            }
            if (self::others_fingerprint($userid, (int) $course->id) !== $othersbefore) {
                throw new \moodle_exception('resetfailed', config::COMPONENT, '', 'other learners were affected');
            }
            if (!self::verify_snapshot($snapshot)) {
                throw new \moodle_exception('resetfailed', config::COMPONENT, '', 'snapshot changed during reset');
            }
            $transaction->allow_commit();
        } catch (\Throwable $e) {
            $transaction->rollback($e);
        }

        \cache::make('core', 'completion')->delete("{$userid}_{$course->id}");
        \cache::make('core', 'coursecompletion')->delete("{$userid}_{$course->id}");
    }
}
