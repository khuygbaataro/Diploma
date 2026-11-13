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

use PHPUnit\Framework\Attributes\CoversClass;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/mod/assign/locallib.php');

/**
 * Tests for finding students who have not submitted.
 *
 * @package    local_darimaa
 * @category   test
 * @copyright  2026 Khuygbaatar Ochirkhuyag
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversClass(missing_submissions::class)]
final class missing_submissions_test extends \advanced_testcase {
    /**
     * Creates a course with a teacher, three students and one assignment.
     *
     * @param array $assignoptions Extra assignment settings.
     * @return array The course, the teacher, the students and the assignment.
     */
    private function create_course_with_assignment(array $assignoptions = []): array {
        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $teacher = $generator->create_and_enrol($course, 'editingteacher');
        $students = [
            $generator->create_and_enrol($course, 'student', ['firstname' => 'Anu']),
            $generator->create_and_enrol($course, 'student', ['firstname' => 'Bold']),
            $generator->create_and_enrol($course, 'student', ['firstname' => 'Saraa']),
        ];

        $instance = $generator->create_module('assign', array_merge([
            'course' => $course->id,
            'assignsubmission_onlinetext_enabled' => 1,
            'duedate' => time() + DAYSECS,
        ], $assignoptions));
        $cm = get_coursemodule_from_instance('assign', $instance->id);
        $assign = new \assign(\core\context\module::instance($cm->id), $cm, $course);

        return [$course, $teacher, $students, $assign];
    }

    /**
     * Saves an online text answer for a student, and optionally submits it.
     *
     * @param \assign $assign The assignment.
     * @param \stdClass $student The student.
     * @param bool $submit True to submit for grading, false to leave a draft.
     */
    private function answer(\assign $assign, \stdClass $student, bool $submit): void {
        $this->getDataGenerator()->get_plugin_generator('mod_assign')->create_submission([
            'userid' => $student->id,
            'cmid' => $assign->get_course_module()->id,
            'onlinetext' => 'My answer',
        ]);

        if ($submit) {
            $this->setUser($student);
            $notices = [];
            $assign->submit_for_grading((object) ['userid' => $student->id], $notices);
        }
    }

    /**
     * Drafts and students who never answered are both listed.
     */
    public function test_drafts_and_empty_answers_count_as_missing(): void {
        $this->resetAfterTest();
        [, $teacher, $students, $assign] = $this->create_course_with_assignment();

        $this->answer($assign, $students[0], true);
        $this->answer($assign, $students[1], false);

        $this->setUser($teacher);
        $missing = missing_submissions::get_missing_users($assign);

        $this->assertEqualsCanonicalizing([$students[1]->id, $students[2]->id], array_keys($missing));
    }

    /**
     * Choosing a group lists only that group's students.
     */
    public function test_group_limits_the_list(): void {
        $this->resetAfterTest();
        [$course, $teacher, $students, $assign] = $this->create_course_with_assignment(['groupmode' => SEPARATEGROUPS]);

        $generator = $this->getDataGenerator();
        $group = $generator->create_group(['courseid' => $course->id]);
        $generator->create_group_member(['groupid' => $group->id, 'userid' => $students[0]->id]);
        $generator->create_group_member(['groupid' => $group->id, 'userid' => $students[1]->id]);

        $this->setUser($teacher);
        $missing = missing_submissions::get_missing_users($assign, (int) $group->id);

        $this->assertEqualsCanonicalizing([$students[0]->id, $students[1]->id], array_keys($missing));
    }

    /**
     * A student's extension replaces the assignment due date.
     */
    public function test_due_date_includes_extension(): void {
        $this->resetAfterTest();
        $due = time() + DAYSECS;
        [, , $students, $assign] = $this->create_course_with_assignment(['duedate' => $due]);

        $extension = $due + WEEKSECS;
        $this->setAdminUser();
        $this->getDataGenerator()->get_plugin_generator('mod_assign')->create_extension([
            'cmid' => $assign->get_course_module()->id,
            'userid' => $students[0]->id,
            'extensionduedate' => $extension,
        ]);

        $this->assertSame($extension, missing_submissions::get_user_duedate($assign, $students[0]->id));
        $this->assertSame($due, missing_submissions::get_user_duedate($assign, $students[1]->id));
    }

    /**
     * Reminders are switched off while identities are hidden.
     */
    public function test_anonymous_submissions_are_not_supported(): void {
        $this->resetAfterTest();
        [, , , $assign] = $this->create_course_with_assignment();
        [, , , $anonymous] = $this->create_course_with_assignment(['blindmarking' => 1]);

        $this->assertTrue(missing_submissions::is_supported($assign));
        $this->assertFalse(missing_submissions::is_supported($anonymous));
    }

    /**
     * The overview counts missing students and is empty for students.
     */
    public function test_course_summary_counts_and_respects_capability(): void {
        $this->resetAfterTest();
        [$course, $teacher, $students, $assign] = $this->create_course_with_assignment();
        $this->answer($assign, $students[0], true);

        $this->setUser($teacher);
        $rows = missing_submissions::get_course_summary($course);
        $this->assertCount(1, $rows);
        $this->assertSame((int) $assign->get_course_module()->id, $rows[0]->cmid);
        $this->assertSame(2, $rows[0]->missing);

        $this->setUser($students[1]);
        $this->assertSame([], missing_submissions::get_course_summary($course));
    }
}
