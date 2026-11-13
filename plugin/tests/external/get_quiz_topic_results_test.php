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
use local_darimaa\local\quiz_topics;
use mod_quiz\quiz_attempt;
use mod_quiz\quiz_settings;
use mod_quiz\structure;
use PHPUnit\Framework\Attributes\CoversClass;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/mod/quiz/locallib.php');

/**
 * Tests for the quiz results by topic.
 *
 * @package    local_darimaa
 * @category   test
 * @copyright  2026 Khuygbaatar Ochirkhuyag
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversClass(get_quiz_topic_results::class)]
#[CoversClass(quiz_topics::class)]
final class get_quiz_topic_results_test extends \advanced_testcase {
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
     * @param int $cmid Course module id of the quiz.
     * @return array The cleaned result.
     */
    private function call(string $externalid, int $cmid): array {
        $result = get_quiz_topic_results::execute('messenger', $externalid, $cmid);
        return external_api::clean_returnvalue(get_quiz_topic_results::execute_returns(), $result);
    }

    /**
     * Creates a course with an editing teacher and three students.
     *
     * @return array The course, the teacher and the students keyed by first name in small letters.
     */
    private function create_course(): array {
        $generator = $this->getDataGenerator();
        $course = $generator->create_course(['fullname' => 'Mathematics']);
        $teacher = $generator->create_and_enrol($course, 'editingteacher', ['firstname' => 'Bat', 'lastname' => 'Bold']);
        $students = [];
        foreach ([['Anu', 'Dorj', 'anu01'], ['Bold', 'Bat', 'bold02'], ['Saraa', 'Tsetseg', 'saraa03']] as $person) {
            $students[strtolower($person[0])] = $generator->create_and_enrol($course, 'student', [
                'firstname' => $person[0],
                'lastname' => $person[1],
                'username' => $person[2],
            ]);
        }
        return [$course, $teacher, $students];
    }

    /**
     * Creates an empty quiz that allows any number of attempts.
     *
     * @param \stdClass $course The course.
     * @param array $options Extra quiz settings.
     * @return \stdClass The quiz, with its course module id in cmid.
     */
    private function create_quiz(\stdClass $course, array $options = []): \stdClass {
        return $this->getDataGenerator()->get_plugin_generator('mod_quiz')->create_instance($options + [
            'course' => $course->id,
            'name' => 'Midterm',
            'attempts' => 0,
            'sumgrades' => 10,
            // All questions go on one page, so that slots keep the order in which they are added.
            'questionsperpage' => 0,
        ]);
    }

    /**
     * Creates a question category in the quiz.
     *
     * @param \stdClass $quiz The quiz.
     * @param string $name The category name.
     * @return \stdClass The category.
     */
    private function create_category(\stdClass $quiz, string $name): \stdClass {
        return $this->getDataGenerator()->get_plugin_generator('core_question')->create_question_category([
            'name' => $name,
            'contextid' => \core\context\module::instance($quiz->cmid)->id,
        ]);
    }

    /**
     * Creates a question in a category.
     *
     * The short answer question accepts "frog" (full mark) and "toad" (80%). The numerical
     * question accepts 3.14. The essay is marked by the teacher.
     *
     * @param \stdClass $category The category.
     * @param string $qtype The question type: shortanswer, numerical or essay.
     * @return \stdClass The question.
     */
    private function create_question(\stdClass $category, string $qtype): \stdClass {
        $questions = $this->getDataGenerator()->get_plugin_generator('core_question');
        return $questions->create_question($qtype, null, ['category' => $category->id]);
    }

    /**
     * Adds one random question from a category to the quiz.
     *
     * @param \stdClass $quiz The quiz.
     * @param \stdClass $category The category to pick from.
     */
    private function add_random_question(\stdClass $quiz, \stdClass $category): void {
        // Editing a quiz needs a user who may use the questions of the category.
        $this->setAdminUser();
        $structure = structure::create_for_quiz(quiz_settings::create($quiz->id));
        $structure->add_random_questions(1, 1, [
            'filter' => [
                'category' => [
                    'jointype' => \qbank_managecategories\category_condition::JOINTYPE_DEFAULT,
                    'values' => [$category->id],
                    'filteroptions' => ['includesubcategories' => false],
                ],
            ],
        ]);
    }

    /**
     * Makes a student finish a new attempt at a quiz.
     *
     * @param \stdClass $quiz The quiz.
     * @param \stdClass $student The student.
     * @param array $responses Responses by slot. Slots without a response are left unanswered.
     */
    private function attempt(\stdClass $quiz, \stdClass $student, array $responses): void {
        $this->setUser($student);
        $previous = quiz_get_user_attempts($quiz->id, $student->id, 'all', true);
        $attempt = quiz_prepare_and_start_new_attempt(
            quiz_settings::create($quiz->id, $student->id),
            count($previous) + 1,
            $previous ? end($previous) : false
        );

        $attemptobj = quiz_attempt::create($attempt->id);
        $attemptobj->process_submitted_actions(time(), false, $responses);
        $attemptobj->process_submit(time(), false);
        $attemptobj->process_grade_submission(time());
    }

    /**
     * Without grade items, topics are the categories of the questions that were asked.
     *
     * Covers a random question, an essay that waits for grading, the best of two attempts
     * and a student who did not attempt.
     */
    public function test_topics_from_question_categories(): void {
        global $DB;
        $this->resetAfterTest();
        [$course, $teacher, $students] = $this->create_course();
        $quiz = $this->create_quiz($course);

        $algebra = $this->create_category($quiz, 'Algebra');
        $writing = $this->create_category($quiz, 'Writing');
        $geometry = $this->create_category($quiz, 'Geometry');
        quiz_add_quiz_question($this->create_question($algebra, 'shortanswer')->id, $quiz, 0, 2);
        quiz_add_quiz_question($this->create_question($algebra, 'numerical')->id, $quiz, 0, 2);
        quiz_add_quiz_question($this->create_question($writing, 'essay')->id, $quiz, 0, 4);
        // The fourth question is picked at random from the geometry category, which has one question.
        $this->create_question($geometry, 'shortanswer');
        $this->add_random_question($quiz, $geometry);
        // The random question is the fourth slot and has no fixed question.
        $slots = $DB->get_records_menu('quiz_slots', ['quizid' => $quiz->id], 'slot', 'slot, maxmark');
        $this->assertEquals([1 => 2, 2 => 2, 3 => 4, 4 => 1], $slots);

        $essay = ['answer' => '<p>My essay about triangles.</p>', 'answerformat' => FORMAT_HTML];
        // Anu's first attempt is weak, the second one is the best. Her essay still waits for a mark.
        $this->attempt($quiz, $students['anu'], [
            1 => ['answer' => 'newt'],
            2 => ['answer' => '2'],
            3 => $essay,
            4 => ['answer' => 'frog'],
        ]);
        $this->attempt($quiz, $students['anu'], [
            1 => ['answer' => 'frog'],
            2 => ['answer' => '3.14'],
            3 => $essay,
            4 => ['answer' => 'frog'],
        ]);
        // Bold gets 80% for one question, and he wrote no essay. Saraa did not attempt.
        $this->attempt($quiz, $students['bold'], [
            1 => ['answer' => 'toad'],
            2 => ['answer' => '2'],
            4 => ['answer' => 'newt'],
        ]);

        $this->link($teacher, 'psid-t');
        $this->act_as_bot();
        $result = $this->call('psid-t', $quiz->cmid);

        $this->assertTrue($result['linked']);
        $this->assertSame('Midterm', $result['quizname']);
        $this->assertSame('Mathematics', $result['coursename']);
        $this->assertSame('categories', $result['topicsource']);
        $this->assertSame(1, $result['notattempted']);
        $this->assertEquals([
            ['name' => 'Algebra', 'maxmark' => 4, 'average' => 70],
            ['name' => 'Geometry', 'maxmark' => 1, 'average' => 50],
            ['name' => 'Writing', 'maxmark' => 4, 'average' => 0],
        ], $result['topics']);

        $this->assertSame(['Anu Dorj', 'Bold Bat'], array_column($result['students'], 'fullname'));
        [$anu, $bold] = $result['students'];
        $this->assertEquals([
            'code' => 'anu01',
            'fullname' => 'Anu Dorj',
            'percent' => 100,
            'ungraded' => 1,
            'topics' => [
                ['name' => 'Algebra', 'mark' => 4, 'maxmark' => 4, 'percent' => 100],
                ['name' => 'Geometry', 'mark' => 1, 'maxmark' => 1, 'percent' => 100],
                ['name' => 'Writing', 'mark' => 0, 'maxmark' => 0, 'percent' => 0],
            ],
        ], $anu);
        $this->assertEquals([
            'code' => 'bold02',
            'fullname' => 'Bold Bat',
            'percent' => 17.8,
            'ungraded' => 0,
            'topics' => [
                ['name' => 'Algebra', 'mark' => 1.6, 'maxmark' => 4, 'percent' => 40],
                ['name' => 'Geometry', 'mark' => 0, 'maxmark' => 1, 'percent' => 0],
                ['name' => 'Writing', 'mark' => 0, 'maxmark' => 4, 'percent' => 0],
            ],
        ], $bold);
    }

    /**
     * When the quiz has grade items, they are the topics, in the teacher's order.
     */
    public function test_topics_from_quiz_grade_items(): void {
        global $DB;
        $this->resetAfterTest();
        [$course, $teacher, $students] = $this->create_course();
        $quiz = $this->create_quiz($course);

        $category = $this->create_category($quiz, 'All questions');
        quiz_add_quiz_question($this->create_question($category, 'shortanswer')->id, $quiz, 0, 1);
        quiz_add_quiz_question($this->create_question($category, 'numerical')->id, $quiz, 0, 1);
        quiz_add_quiz_question($this->create_question($category, 'shortanswer')->id, $quiz, 0, 2);

        // The first and third question count for "Words", the second for "Numbers", which comes first.
        $words = $DB->insert_record('quiz_grade_items', (object) ['quizid' => $quiz->id, 'sortorder' => 2, 'name' => 'Words']);
        $numbers = $DB->insert_record('quiz_grade_items', (object) ['quizid' => $quiz->id, 'sortorder' => 1, 'name' => 'Numbers']);
        $DB->set_field('quiz_slots', 'quizgradeitemid', $words, ['quizid' => $quiz->id, 'slot' => 1]);
        $DB->set_field('quiz_slots', 'quizgradeitemid', $numbers, ['quizid' => $quiz->id, 'slot' => 2]);
        $DB->set_field('quiz_slots', 'quizgradeitemid', $words, ['quizid' => $quiz->id, 'slot' => 3]);

        $this->attempt($quiz, $students['anu'], [
            1 => ['answer' => 'frog'],
            2 => ['answer' => '2'],
            3 => ['answer' => 'toad'],
        ]);

        set_config('studentcodefield', 'idnumber', 'local_darimaa');
        $DB->set_field('user', 'idnumber', 'ID-001', ['id' => $students['anu']->id]);
        $this->link($teacher, 'psid-t');
        $this->act_as_bot();
        $result = $this->call('psid-t', $quiz->cmid);

        $this->assertSame('gradeitems', $result['topicsource']);
        $this->assertSame(2, $result['notattempted']);
        $this->assertEquals([
            ['name' => 'Numbers', 'maxmark' => 1, 'average' => 0],
            ['name' => 'Words', 'maxmark' => 3, 'average' => 86.7],
        ], $result['topics']);
        $this->assertEquals([
            [
                'code' => 'ID-001',
                'fullname' => 'Anu Dorj',
                'percent' => 65,
                'ungraded' => 0,
                'topics' => [
                    ['name' => 'Numbers', 'mark' => 0, 'maxmark' => 1, 'percent' => 0],
                    ['name' => 'Words', 'mark' => 2.6, 'maxmark' => 3, 'percent' => 86.7],
                ],
            ],
        ], $result['students']);
    }

    /**
     * In separate groups mode a teacher sees only the students of their own groups.
     */
    public function test_separate_groups_limit_the_report(): void {
        $this->resetAfterTest();
        $generator = $this->getDataGenerator();
        [$course, $teacher, $students] = $this->create_course();
        $tutor = $generator->create_and_enrol($course, 'teacher', ['firstname' => 'Tuya']);
        $outsider = $generator->create_and_enrol($course, 'teacher', ['firstname' => 'Gerel']);

        $groupa = $generator->create_group(['courseid' => $course->id]);
        $groupb = $generator->create_group(['courseid' => $course->id]);
        $generator->create_group_member(['groupid' => $groupa->id, 'userid' => $tutor->id]);
        $generator->create_group_member(['groupid' => $groupa->id, 'userid' => $students['anu']->id]);
        $generator->create_group_member(['groupid' => $groupa->id, 'userid' => $students['saraa']->id]);
        $generator->create_group_member(['groupid' => $groupb->id, 'userid' => $students['bold']->id]);

        $quiz = $this->create_quiz($course, ['groupmode' => SEPARATEGROUPS]);
        $category = $this->create_category($quiz, 'Animals');
        quiz_add_quiz_question($this->create_question($category, 'shortanswer')->id, $quiz, 0, 1);
        $this->attempt($quiz, $students['anu'], [1 => ['answer' => 'frog']]);
        $this->attempt($quiz, $students['bold'], [1 => ['answer' => 'toad']]);

        $this->link($teacher, 'psid-t');
        $this->link($tutor, 'psid-a');
        $this->link($outsider, 'psid-o');
        $this->act_as_bot();

        // The editing teacher has access to all groups.
        $result = $this->call('psid-t', $quiz->cmid);
        $this->assertSame(['Anu Dorj', 'Bold Bat'], array_column($result['students'], 'fullname'));
        $this->assertSame(1, $result['notattempted']);
        $this->assertEquals([['name' => 'Animals', 'maxmark' => 1, 'average' => 90]], $result['topics']);

        // The tutor sees group A: Anu attempted, Saraa did not.
        $result = $this->call('psid-a', $quiz->cmid);
        $this->assertSame(['Anu Dorj'], array_column($result['students'], 'fullname'));
        $this->assertSame(1, $result['notattempted']);
        $this->assertEquals([['name' => 'Animals', 'maxmark' => 1, 'average' => 100]], $result['topics']);

        // A teacher who is in no group sees nobody.
        $result = $this->call('psid-o', $quiz->cmid);
        $this->assertSame([], $result['students']);
        $this->assertSame(0, $result['notattempted']);
        $this->assertSame([], $result['topics']);
    }

    /**
     * A student cannot ask for the results of a quiz.
     */
    public function test_students_are_refused(): void {
        $this->resetAfterTest();
        [$course, , $students] = $this->create_course();
        $quiz = $this->create_quiz($course);
        $this->link($students['anu'], 'psid-1');
        $this->act_as_bot();

        $this->expectException(\required_capability_exception::class);
        get_quiz_topic_results::execute('messenger', 'psid-1', $quiz->cmid);
    }

    /**
     * A teacher cannot ask for the results of a quiz in a course they do not teach.
     */
    public function test_teachers_of_other_courses_are_refused(): void {
        $this->resetAfterTest();
        [$course] = $this->create_course();
        $quiz = $this->create_quiz($course);
        $othercourse = $this->getDataGenerator()->create_course();
        $stranger = $this->getDataGenerator()->create_and_enrol($othercourse, 'editingteacher');
        $this->link($stranger, 'psid-x');
        $this->act_as_bot();

        $this->expectException(\required_capability_exception::class);
        get_quiz_topic_results::execute('messenger', 'psid-x', $quiz->cmid);
    }

    /**
     * Unknown accounts are told they are not linked, and only the bot may call the function.
     */
    public function test_unknown_accounts_and_other_callers(): void {
        $this->resetAfterTest();
        [$course] = $this->create_course();
        $quiz = $this->create_quiz($course);
        $this->act_as_bot();

        $result = $this->call('psid-404', $quiz->cmid);
        $this->assertFalse($result['linked']);
        $this->assertSame([], $result['students']);

        $this->setUser($this->getDataGenerator()->create_user());
        $this->expectException(\required_capability_exception::class);
        get_quiz_topic_results::execute('messenger', 'psid-404', $quiz->cmid);
    }
}
