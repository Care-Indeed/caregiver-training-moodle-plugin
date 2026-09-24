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

use local_caregivertraining\local\binding_manager;

/**
 * Synthetic fixtures: an annual course with Lesson, Quiz, Feedback and Custom certificate, and
 * learners with real evidence created through the module APIs.
 *
 * @package    local_caregivertraining
 * @copyright  2026 CI Institute of Nursing
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class local_caregivertraining_generator extends component_generator_base {
    /** @var int */
    protected $learnercount = 0;

    /**
     * Create and configure the annual course.
     *
     * @return stdClass {course, lesson, lessonpage, quiz, feedback, feedbackitem, customcert, cms}
     */
    public function create_annual_course(): stdClass {
        global $CFG;
        require_once($CFG->dirroot . '/mod/quiz/locallib.php');
        $dg = $this->datagenerator;
        $CFG->enablecompletion = 1;

        $course = $dg->create_course([
            'fullname' => 'Synthetic annual caregiver training',
            'shortname' => 'CGT-SYN-' . random_string(6),
            'enablecompletion' => 1,
        ]);
        $manual = ['completion' => COMPLETION_TRACKING_MANUAL];

        $lesson = $dg->create_module('lesson', ['course' => $course->id] + $manual);
        $lessongen = $dg->get_plugin_generator('mod_lesson');
        $lessonpage = $lessongen->create_question_truefalse($lesson);

        $quiz = $dg->create_module('quiz', ['course' => $course->id, 'grade' => 10, 'sumgrades' => 1] + $manual);
        $qgen = $dg->get_plugin_generator('core_question');
        $category = $qgen->create_question_category(['contextid' => context_module::instance($quiz->cmid)->id]);
        $question = $qgen->create_question('truefalse', null, ['category' => $category->id]);
        quiz_add_quiz_question($question->id, $quiz, 0, 1);

        $feedback = $dg->create_module('feedback', ['course' => $course->id, 'anonymous' => 2] + $manual);
        $itemname = 'cgt-q-' . random_string(8);
        $dg->get_plugin_generator('mod_feedback')->create_item_numeric($feedback, ['name' => $itemname, 'rangefrom' => 1,
            'rangeto' => 5]);

        $customcert = $dg->create_module('customcert', ['course' => $course->id] + $manual);

        set_config('courseid', $course->id, 'local_caregivertraining');
        set_config('certificatecmid', $customcert->cmid, 'local_caregivertraining');

        return (object) [
            'course' => $course,
            'lesson' => $lesson,
            'lessonpage' => $lessonpage,
            'quiz' => $quiz,
            'feedback' => $feedback,
            'feedbackitem' => $itemname,
            'customcert' => $customcert,
            'cms' => [$lesson->cmid, $quiz->cmid, $feedback->cmid, $customcert->cmid],
        ];
    }

    /**
     * Provision a bound learner through the plugin API.
     *
     * @param array $overrides
     * @return stdClass {user, binding, alayacareid}
     */
    public function create_learner(array $overrides = []): stdClass {
        global $DB;
        $n = ++$this->learnercount;
        $suffix = random_string(6);
        $params = $overrides + [
            'alayacareid' => 'AC' . $n . $suffix,
            'externalid' => 'EXT-' . $n . $suffix,
            'payrollid' => 'PR-' . $n . $suffix,
            'email' => "learner{$n}.{$suffix}@example.com",
            'firstname' => 'Synthetic',
            'lastname' => 'Learner ' . $n,
            'hcanumber' => 'HCA' . $n . $suffix,
            'registrationdate' => '2025-01-15',
            'createifmissing' => 1,
            'sendactivation' => 0,
        ];
        $result = binding_manager::provision($params);
        $user = $DB->get_record('user', ['id' => $result['userid']], '*', MUST_EXIST);
        $binding = $DB->get_record('local_cgt_binding', ['id' => $result['bindingid']], '*', MUST_EXIST);
        return (object) ['user' => $user, 'binding' => $binding, 'alayacareid' => $binding->alayacareid];
    }

    /**
     * Give a learner evidence in every resettable activity plus completion and a certificate.
     *
     * @param stdClass $fixture from create_annual_course()
     * @param int $userid
     * @param bool $complete also mark activity and course completion
     */
    public function create_evidence(stdClass $fixture, int $userid, bool $complete = true): void {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/mod/lesson/lib.php');
        require_once($CFG->libdir . '/completionlib.php');
        $dg = $this->datagenerator;

        $dg->get_plugin_generator('mod_lesson')->create_attempt(['lessonid' => $fixture->lesson->id, 'userid' => $userid,
            'pageid' => $fixture->lessonpage->id, 'correct' => 1, 'grade' => 100]);
        $lesson = $DB->get_record('lesson', ['id' => $fixture->lesson->id], '*', MUST_EXIST);
        $lesson->cmidnumber = '';
        lesson_update_grades($lesson, $userid);

        global $USER;
        $previoususer = clone($USER);
        \core\session\manager::set_user(core_user::get_user($userid));
        $quizgen = $dg->get_plugin_generator('mod_quiz');
        $attempt = $quizgen->create_attempt($fixture->quiz->id, $userid);
        $quizgen->submit_responses($attempt->id, [1 => 'True'], false, true);
        \core\session\manager::set_user($previoususer);

        $dg->get_plugin_generator('mod_feedback')->create_response(['cmid' => $fixture->feedback->cmid, 'userid' => $userid,
            $fixture->feedbackitem => 4]);

        \mod_customcert\certificate::issue_certificate($fixture->customcert->id, $userid);

        if ($complete) {
            $completion = new completion_info($fixture->course);
            foreach ($fixture->cms as $cmid) {
                $cm = get_coursemodule_from_id('', $cmid, 0, false, MUST_EXIST);
                $completion->update_state($cm, COMPLETION_COMPLETE, $userid);
            }
            $ccompletion = new completion_completion(['userid' => $userid, 'course' => $fixture->course->id]);
            $ccompletion->mark_complete();
        }
    }
}
