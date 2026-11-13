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

namespace local_darimaa\external;

use core_external\external_api;
use local_darimaa\local\link_manager;
use local_darimaa\local\teacher_courses;
use mod_quiz\quiz_attempt;
use mod_quiz\quiz_settings;
use PHPUnit\Framework\Attributes\CoversClass;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/mod/quiz/locallib.php');

/**
 * Tests for the list of a teacher's courses and quizzes.
 *
 * @package    local_darimaa
 * @category   test
 * @copyright  2026 Khuygbaatar Ochirkhuyag
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversClass(get_courses::class)]
#[CoversClass(teacher_courses::class)]
final class get_courses_test extends \advanced_testcase {
    /**
     * Creates the bot's service account and makes it the current user.
     */
    private function act_as_bot(): void {
        $generator = $this->getDataGenerator();
        $bot = $generator->create_user(['username' => 'darimaabot']);
        $roleid = $generator->create_role(['shortname' => 'darimaabot']);
        $syscontext = \core\context\system::instance();
        assign_capability('local/darimaa:botaccess', CAP_ALLOW, $roleid, $syscontext->id);
        role_assign($roleid, $bot->id, $syscontext->id);
        $this->setUser($bot);
    }

    /**
     * Links a user to a chat account through the normal code flow.
     *
     * @param \stdClass $user The user.
     * @param string $externalid The chat account id.
     */
    private function link(\stdClass $user, string $externalid): void {
        link_manager::link('messenger', link_manager::create_code($user->id, 'messenger'), $externalid);
    }

    /**
     * Calls the function and checks its result against the declared return structure.
     *
     * @param string $externalid The chat account id.
     * @return array The cleaned result.
     */
    private function call(string $externalid): array {
        $result = get_courses::execute('messenger', $externalid);
        return external_api::clean_returnvalue(get_courses::execute_returns(), $result);
    }

    /**
     * Creates a quiz with one short answer question.
     *
     * @param \stdClass $course The course.
     * @param array $options Extra quiz settings.
     * @return \stdClass The quiz, with its course module id in cmid.
     */
    private function create_quiz(\stdClass $course, array $options = []): \stdClass {
        $generator = $this->getDataGenerator();
        $quiz = $generator->get_plugin_generator('mod_quiz')->create_instance($options + [
            'course' => $course->id,
            'sumgrades' => 1,
        ]);

        $questions = $generator->get_plugin_generator('core_question');
        $category = $questions->create_question_category([
            'contextid' => \core\context\module::instance($quiz->cmid)->id,
        ]);
        $question = $questions->create_question('shortanswer', null, ['category' => $category->id]);
        quiz_add_quiz_question($question->id, $quiz);

        return $quiz;
    }

    /**
     * Starts a quiz attempt for a student and, when responses are given, finishes it.
     *
     * @param \stdClass $quiz The quiz.
     * @param \stdClass $student The student.
     * @param array|null $responses Responses by slot, or null to leave the attempt in progress.
     */
    private function attempt(\stdClass $quiz, \stdClass $student, ?array $responses): void {
        $this->setUser($student);
        $attempt = quiz_prepare_and_start_new_attempt(quiz_settings::create($quiz->id, $student->id), 1, false);

        if ($responses !== null) {
            $attemptobj = quiz_attempt::create($attempt->id);
            $attemptobj->process_submitted_actions(time(), false, $responses);
            $attemptobj->process_submit(time(), false);
            $attemptobj->process_grade_submission(time());
        }
    }

    /**
     * Only courses where the user can send reminders are listed, with the quizzes they can see reports of.
     */
    public function test_lists_only_the_teachers_courses_and_reportable_quizzes(): void {
        global $DB;
        $this->resetAfterTest();
        $generator = $this->getDataGenerator();

        $biology = $generator->create_course(['fullname' => 'Biology 101', 'shortname' => 'BIO101']);
        $algebra = $generator->create_course(['fullname' => 'Algebra', 'shortname' => 'ALG']);
        $history = $generator->create_course(['fullname' => 'History', 'shortname' => 'HIS']);
        $chemistry = $generator->create_course(['fullname' => 'Chemistry', 'shortname' => 'CHE']);

        $teacher = $generator->create_and_enrol($biology, 'teacher', ['firstname' => 'Bat']);
        $generator->enrol_user($teacher->id, $algebra->id, 'teacher');
        // The teacher studies chemistry, and somebody else teaches history.
        $generator->enrol_user($teacher->id, $chemistry->id, 'student');
        $generator->create_and_enrol($history, 'editingteacher');

        $anu = $generator->create_and_enrol($biology, 'student', ['firstname' => 'Anu']);
        $bold = $generator->create_and_enrol($biology, 'student', ['firstname' => 'Bold']);
        $saraa = $generator->create_user(['firstname' => 'Saraa']);
        $generator->enrol_user($saraa->id, $biology->id, 'student', 'manual', 0, 0, ENROL_USER_SUSPENDED);

        $closes = time() + WEEKSECS;
        $quiz = $this->create_quiz($biology, ['name' => 'Cells', 'timeclose' => $closes]);
        // The teacher role may not see the reports of the second quiz.
        $closed = $this->create_quiz($biology, ['name' => 'Exam']);
        $roleid = $DB->get_field('role', 'id', ['shortname' => 'teacher'], MUST_EXIST);
        assign_capability('mod/quiz:viewreports', CAP_PROHIBIT, $roleid, \core\context\module::instance($closed->cmid)->id);

        // Anu finished the quiz, Bold is still working on it.
        $this->attempt($quiz, $anu, [1 => ['answer' => 'frog']]);
        $this->attempt($quiz, $bold, null);

        $this->link($teacher, 'psid-t');
        $this->act_as_bot();
        $result = $this->call('psid-t');

        $this->assertTrue($result['linked']);
        $this->assertSame(['Algebra', 'Biology 101'], array_column($result['courses'], 'fullname'));

        [$first, $second] = $result['courses'];
        $this->assertEquals($algebra->id, $first['id']);
        $this->assertSame('ALG', $first['shortname']);
        $this->assertSame(0, $first['students']);
        $this->assertSame([], $first['quizzes']);

        $this->assertEquals($biology->id, $second['id']);
        $this->assertSame('BIO101', $second['shortname']);
        $this->assertSame(2, $second['students']);
        $this->assertCount(1, $second['quizzes']);
        $this->assertEquals($quiz->cmid, $second['quizzes'][0]['cmid']);
        $this->assertSame('Cells', $second['quizzes'][0]['name']);
        $this->assertSame($closes, $second['quizzes'][0]['timeclose']);
        $this->assertSame(1, $second['quizzes'][0]['finished']);
    }

    /**
     * Students get an empty list, and unknown accounts are told they are not linked.
     */
    public function test_students_and_unknown_accounts_get_no_courses(): void {
        $this->resetAfterTest();
        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $generator->create_and_enrol($course, 'editingteacher');
        $student = $generator->create_and_enrol($course, 'student', ['firstname' => 'Anu']);
        $this->create_quiz($course);

        $this->link($student, 'psid-1');
        $this->act_as_bot();

        $this->assertSame(['linked' => true, 'courses' => []], $this->call('psid-1'));
        $this->assertSame(['linked' => false, 'courses' => []], $this->call('psid-404'));
    }

    /**
     * Only the bot's service account may call the function.
     */
    public function test_requires_bot_capability(): void {
        $this->resetAfterTest();
        $this->setUser($this->getDataGenerator()->create_user());

        $this->expectException(\required_capability_exception::class);
        get_courses::execute('messenger', 'psid-1');
    }
}
