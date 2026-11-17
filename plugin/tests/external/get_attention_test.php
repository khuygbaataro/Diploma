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
use local_darimaa\local\at_risk;
use local_darimaa\local\link_manager;
use mod_quiz\quiz_attempt;
use mod_quiz\quiz_settings;
use PHPUnit\Framework\Attributes\CoversClass;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/mod/assign/locallib.php');
require_once($CFG->dirroot . '/mod/quiz/locallib.php');

/**
 * Tests for the list of students who need attention, as the bot gets it.
 *
 * @package    local_darimaa
 * @category   test
 * @copyright  2026 Khuygbaatar Ochirkhuyag
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversClass(get_attention::class)]
final class get_attention_test extends \advanced_testcase {
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
     * @param int $courseid The course.
     * @param int $days The number of days for "away".
     * @return array The cleaned result.
     */
    private function call(string $externalid, int $courseid, int $days = 7): array {
        $result = get_attention::execute('messenger', $externalid, $courseid, $days);
        return external_api::clean_returnvalue(get_attention::execute_returns(), $result);
    }

    /**
     * Creates a course that started two months ago, with a teacher and three students.
     *
     * @return array The course, the teacher and the students keyed by first name in small letters.
     */
    private function create_course(): array {
        $generator = $this->getDataGenerator();
        $course = $generator->create_course(['fullname' => 'Biology 101', 'startdate' => time() - 60 * DAYSECS]);
        $teacher = $generator->create_and_enrol($course, 'editingteacher', ['firstname' => 'Bat', 'lastname' => 'Bold']);
        $students = [];
        foreach (['anu' => ['Anu', 'Dorj'], 'bold' => ['Bold', 'Bat'], 'tuya' => ['Tuya', 'Od']] as $key => [$first, $last]) {
            $students[$key] = $generator->create_and_enrol($course, 'student', [
                'firstname' => $first,
                'lastname' => $last,
                'username' => $key . '01',
            ]);
        }
        return [$course, $teacher, $students];
    }

    /**
     * Records when a user last visited a course.
     *
     * @param \stdClass $course The course.
     * @param \stdClass $user The user.
     * @param int $time The time of the visit.
     */
    private function visit(\stdClass $course, \stdClass $user, int $time): void {
        global $DB;
        $DB->insert_record('user_lastaccess', (object) ['userid' => $user->id, 'courseid' => $course->id, 'timeaccess' => $time]);
    }

    /**
     * Creates an assignment and lets some students submit it.
     *
     * @param \stdClass $course The course.
     * @param int $duedate The due date.
     * @param \stdClass[] $submitters The students who submit.
     */
    private function assignment(\stdClass $course, int $duedate, array $submitters): void {
        $generator = $this->getDataGenerator();
        $instance = $generator->create_module('assign', [
            'course' => $course->id,
            'assignsubmission_onlinetext_enabled' => 1,
            'duedate' => $duedate,
        ]);
        $cm = get_coursemodule_from_instance('assign', $instance->id);
        $assign = new \assign(\core\context\module::instance($cm->id), $cm, $course);
        foreach ($submitters as $student) {
            $generator->get_plugin_generator('mod_assign')->create_submission([
                'userid' => $student->id,
                'cmid' => $cm->id,
                'onlinetext' => 'My answer',
            ]);
            $this->setUser($student);
            $notices = [];
            $assign->submit_for_grading((object) ['userid' => $student->id], $notices);
        }
        $this->setAdminUser();
    }

    /**
     * Creates a quiz with one short answer question in a topic, and lets students answer it.
     *
     * The question accepts "frog" (full marks); any other answer gets nothing.
     *
     * @param \stdClass $course The course.
     * @param string $topic The question category, which is the topic.
     * @param array $answers Answers keyed by student id.
     * @param \stdClass[] $students The students by id.
     */
    private function quiz(\stdClass $course, string $topic, array $answers, array $students): void {
        $generator = $this->getDataGenerator();
        $quiz = $generator->get_plugin_generator('mod_quiz')->create_instance([
            'course' => $course->id,
            'sumgrades' => 1,
            'attempts' => 0,
        ]);
        $questions = $generator->get_plugin_generator('core_question');
        $category = $questions->create_question_category([
            'name' => $topic,
            'contextid' => \core\context\module::instance($quiz->cmid)->id,
        ]);
        quiz_add_quiz_question($questions->create_question('shortanswer', null, ['category' => $category->id])->id, $quiz, 0, 1);

        foreach ($answers as $userid => $answer) {
            $this->setUser($students[$userid]);
            $attempt = quiz_prepare_and_start_new_attempt(quiz_settings::create($quiz->id, $userid), 1, false);
            $attemptobj = quiz_attempt::create($attempt->id);
            $attemptobj->process_submitted_actions(time(), false, [1 => ['answer' => $answer]]);
            $attemptobj->process_submit(time(), false);
            $attemptobj->process_grade_submission(time());
        }
        $this->setAdminUser();
    }

    /**
     * Only the students with warning signs are listed, with the numbers behind the signs.
     */
    public function test_lists_only_students_with_signs(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        [$course, $teacher, $students] = $this->create_course();
        $now = time();
        $byid = [];
        foreach ($students as $student) {
            $byid[$student->id] = $student;
        }

        // Anu and Tuya visit often, Bold never came.
        $this->visit($course, $students['anu'], $now - HOURSECS);
        $this->visit($course, $students['tuya'], $now - DAYSECS);
        // An assignment past its due date: Anu and Tuya submitted it.
        $this->assignment($course, $now - 2 * DAYSECS, [$students['anu'], $students['tuya']]);
        // An open quiz: Anu answers well, Tuya badly.
        $this->quiz($course, 'Cells', [$students['anu']->id => 'frog', $students['tuya']->id => 'newt'], $byid);

        $this->link($teacher, 'psid-t');
        $this->act_as_bot();
        $result = $this->call('psid-t', $course->id);

        $this->assertTrue($result['linked']);
        $this->assertSame('Biology 101', $result['coursename']);
        $this->assertSame(7, $result['days']);
        $this->assertTrue($result['lastaccessavailable']);
        $this->assertSame(1, $result['quizzes']);
        // Three students were looked at; Anu has no sign and is not listed.
        $this->assertSame(3, $result['total']);
        $this->assertSame(['Bold Bat', 'Tuya Od'], array_column($result['students'], 'fullname'));

        [$bold, $tuya] = $result['students'];
        $this->assertSame([
            'code' => 'bold01',
            'fullname' => 'Bold Bat',
            'dayssince' => -1,
            'missing' => 1,
            'notattempted' => 0,
            'quizaverage' => -1.0,
            'weaktopics' => [],
            'signals' => [at_risk::SIGNAL_NEVER, at_risk::SIGNAL_MISSING],
        ], $bold);

        $this->assertSame('tuya01', $tuya['code']);
        $this->assertSame(1, $tuya['dayssince']);
        $this->assertSame(0, $tuya['missing']);
        $this->assertEquals(0, $tuya['quizaverage']);
        $this->assertCount(1, $tuya['weaktopics']);
        $this->assertSame('Cells', $tuya['weaktopics'][0]['name']);
        $this->assertEquals(0, $tuya['weaktopics'][0]['percent']);
        $this->assertSame([at_risk::SIGNAL_LOWQUIZ, at_risk::SIGNAL_WEAKTOPIC], $tuya['signals']);
    }

    /**
     * The number of days for "away" is kept between one day and a year.
     */
    public function test_days_are_kept_in_range(): void {
        $this->resetAfterTest();
        [$course, $teacher, $students] = $this->create_course();
        $this->visit($course, $students['anu'], time() - 10 * DAYSECS);
        $this->link($teacher, 'psid-t');
        $this->act_as_bot();

        $this->assertSame(1, $this->call('psid-t', $course->id, 0)['days']);
        $this->assertSame(365, $this->call('psid-t', $course->id, 1000)['days']);

        // Anu was here ten days ago: away after a week, not after two weeks.
        $codes = array_column($this->call('psid-t', $course->id, 7)['students'], 'code');
        $this->assertContains('anu01', $codes);
        $codes = array_column($this->call('psid-t', $course->id, 14)['students'], 'code');
        $this->assertNotContains('anu01', $codes);
    }

    /**
     * A student cannot ask for the list of their own course.
     */
    public function test_students_are_refused(): void {
        $this->resetAfterTest();
        [$course, , $students] = $this->create_course();
        $this->link($students['anu'], 'psid-1');
        $this->act_as_bot();

        $this->expectException(\required_capability_exception::class);
        get_attention::execute('messenger', 'psid-1', $course->id);
    }

    /**
     * A teacher cannot ask for the list of a course they do not teach.
     */
    public function test_teachers_of_other_courses_are_refused(): void {
        $this->resetAfterTest();
        [$course] = $this->create_course();
        $othercourse = $this->getDataGenerator()->create_course();
        $stranger = $this->getDataGenerator()->create_and_enrol($othercourse, 'editingteacher');
        $this->link($stranger, 'psid-x');
        $this->act_as_bot();

        $this->expectException(\required_capability_exception::class);
        get_attention::execute('messenger', 'psid-x', $course->id);
    }

    /**
     * Unknown accounts are told they are not linked, and only the bot may call the function.
     */
    public function test_unknown_accounts_and_other_callers(): void {
        $this->resetAfterTest();
        [$course] = $this->create_course();
        $this->act_as_bot();

        $result = $this->call('psid-404', $course->id);
        $this->assertFalse($result['linked']);
        $this->assertSame(0, $result['total']);
        $this->assertSame([], $result['students']);

        $this->setUser($this->getDataGenerator()->create_user());
        $this->expectException(\required_capability_exception::class);
        get_attention::execute('messenger', 'psid-404', $course->id);
    }
}
