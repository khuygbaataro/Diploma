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

namespace local_darimaa\local;

use mod_quiz\quiz_attempt;
use mod_quiz\quiz_settings;
use PHPUnit\Framework\Attributes\CoversClass;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/mod/assign/locallib.php');
require_once($CFG->dirroot . '/mod/quiz/locallib.php');

/**
 * Tests for the list of students who need attention. All students are invented.
 *
 * @package    local_darimaa
 * @category   test
 * @copyright  2026 Khuygbaatar Ochirkhuyag
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversClass(at_risk::class)]
#[CoversClass(quiz_topics::class)]
#[CoversClass(inactive_students::class)]
final class at_risk_test extends \advanced_testcase {
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
     * @return \stdClass The quiz.
     */
    private function quiz(\stdClass $course, string $topic, array $answers, array $students): \stdClass {
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
        return $quiz;
    }

    /**
     * Each student gets the warning signs that apply, and those with the most signs come first.
     */
    public function test_report_lists_the_signs_of_each_student(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        $generator = $this->getDataGenerator();
        $now = time();

        $course = $generator->create_course(['startdate' => $now - 60 * DAYSECS]);
        $teacher = $generator->create_and_enrol($course, 'editingteacher', ['firstname' => 'Bat']);
        $people = [
            'anu' => ['Anu', 'Dorj'],
            'bold' => ['Bold', 'Bat'],
            'saraa' => ['Saraa', 'Tsetseg'],
            'tuya' => ['Tuya', 'Od'],
        ];
        $students = [];
        foreach ($people as $key => [$first, $last]) {
            $students[$key] = $generator->create_and_enrol($course, 'student', ['firstname' => $first, 'lastname' => $last]);
        }
        $byid = [];
        foreach ($students as $student) {
            $byid[$student->id] = $student;
        }

        // Anu and Tuya visit often, Saraa was last here ten days ago, Bold never came.
        $this->visit($course, $students['anu'], $now - HOURSECS);
        $this->visit($course, $students['tuya'], $now - DAYSECS);
        $this->visit($course, $students['saraa'], $now - 10 * DAYSECS);

        // An assignment past its due date: Anu and Tuya submitted it.
        $this->assignment($course, $now - 2 * DAYSECS, [$students['anu'], $students['tuya']]);

        // An open quiz: Anu answers well, Tuya badly. Nobody else has tried it yet.
        $this->quiz($course, 'Cells', [$students['anu']->id => 'frog', $students['tuya']->id => 'newt'], $byid);
        // A quiz that has closed: only Anu took it.
        $closed = $this->quiz($course, 'Ecology', [$students['anu']->id => 'frog'], $byid);
        $DB->set_field('quiz', 'timeclose', $now - DAYSECS, ['id' => $closed->id]);
        // A quiz nobody finished says nothing about anyone.
        $this->quiz($course, 'Genetics', [], $byid);

        $report = at_risk::get_report($course, $teacher, 7);

        $this->assertTrue($report['lastaccessavailable']);
        $this->assertSame(2, $report['quizzes']);
        $rows = [];
        foreach ($report['students'] as $row) {
            $rows[$row['fullname']] = $row;
        }
        // Three signs each come first, the longest away first (Bold never came); Anu, with none, last.
        $this->assertSame(['Bold Bat', 'Saraa Tsetseg', 'Tuya Od', 'Anu Dorj'], array_keys($rows));

        $this->assertSame(
            [at_risk::SIGNAL_NEVER, at_risk::SIGNAL_MISSING, at_risk::SIGNAL_NOTATTEMPTED],
            $rows['Bold Bat']['signals']
        );
        $this->assertSame(
            [at_risk::SIGNAL_INACTIVE, at_risk::SIGNAL_MISSING, at_risk::SIGNAL_NOTATTEMPTED],
            $rows['Saraa Tsetseg']['signals']
        );
        $this->assertSame(
            [at_risk::SIGNAL_NOTATTEMPTED, at_risk::SIGNAL_LOWQUIZ, at_risk::SIGNAL_WEAKTOPIC],
            $rows['Tuya Od']['signals']
        );
        $this->assertSame([], $rows['Anu Dorj']['signals']);

        // The numbers behind the signs.
        $this->assertSame(1, $rows['Bold Bat']['missing']);
        $this->assertSame(1, $rows['Bold Bat']['notattempted']);
        $this->assertNull($rows['Bold Bat']['quizaverage']);
        $this->assertSame(10, $rows['Saraa Tsetseg']['dayssince']);
        $this->assertSame(1, $rows['Tuya Od']['quizzes']);
        $this->assertEquals(0, $rows['Tuya Od']['quizaverage']);
        $this->assertCount(1, $rows['Tuya Od']['weaktopics']);
        $this->assertSame('Cells', $rows['Tuya Od']['weaktopics'][0]['name']);
        $this->assertSame(2, $rows['Anu Dorj']['quizzes']);
        $this->assertEquals(100, $rows['Anu Dorj']['quizaverage']);
        $this->assertSame((int) $students['anu']->id, $rows['Anu Dorj']['userid']);

        // With a longer threshold Saraa is no longer away, but still misses work.
        $report = at_risk::get_report($course, $teacher, 14);
        foreach ($report['students'] as $row) {
            if ($row['fullname'] === 'Saraa Tsetseg') {
                $this->assertSame([at_risk::SIGNAL_MISSING, at_risk::SIGNAL_NOTATTEMPTED], $row['signals']);
            }
        }
    }

    /**
     * A teacher only sees their own groups in separate groups mode, and quizzes without report access are left out.
     */
    public function test_groups_and_quiz_access_limit_the_report(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        $generator = $this->getDataGenerator();
        $course = $generator->create_course(['groupmode' => SEPARATEGROUPS, 'groupmodeforce' => 1]);
        $tutor = $generator->create_and_enrol($course, 'teacher', ['firstname' => 'Tuya']);
        $anu = $generator->create_and_enrol($course, 'student', ['firstname' => 'Anu']);
        $bold = $generator->create_and_enrol($course, 'student', ['firstname' => 'Bold']);
        $group = $generator->create_group(['courseid' => $course->id]);
        $generator->create_group_member(['groupid' => $group->id, 'userid' => $tutor->id]);
        $generator->create_group_member(['groupid' => $group->id, 'userid' => $anu->id]);

        $quiz = $this->quiz($course, 'Cells', [$anu->id => 'newt'], [$anu->id => $anu, $bold->id => $bold]);

        $report = at_risk::get_report($course, $tutor);
        $this->assertSame([(int) $anu->id], array_column($report['students'], 'userid'));
        $this->assertContains(at_risk::SIGNAL_LOWQUIZ, $report['students'][0]['signals']);

        // Without the right to see the quiz's reports, its results are not used.
        $roleid = $DB->get_field('role', 'id', ['shortname' => 'teacher'], MUST_EXIST);
        assign_capability('mod/quiz:viewreports', CAP_PROHIBIT, $roleid, \core\context\module::instance($quiz->cmid)->id);
        accesslib_clear_all_caches_for_unit_testing();

        $report = at_risk::get_report($course, $tutor);
        $this->assertSame(0, $report['quizzes']);
        $this->assertNotContains(at_risk::SIGNAL_LOWQUIZ, $report['students'][0]['signals']);
    }
}
