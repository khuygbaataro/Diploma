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

/**
 * Tests for finding the students of a course.
 *
 * @package    local_darimaa
 * @category   test
 * @copyright  2026 Khuygbaatar Ochirkhuyag
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversClass(course_students::class)]
final class course_students_test extends \advanced_testcase {
    /**
     * Students have an active enrolment and a gradebook role.
     */
    public function test_students_are_active_users_with_a_gradebook_role(): void {
        global $CFG;
        $this->resetAfterTest();
        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $context = \core\context\course::instance($course->id);

        $generator->create_and_enrol($course, 'editingteacher');
        $anu = $generator->create_and_enrol($course, 'student', ['firstname' => 'Anu', 'lastname' => 'Dorj']);
        $bold = $generator->create_and_enrol($course, 'student', ['firstname' => 'Bold', 'lastname' => 'Bat']);
        // A suspended enrolment does not count.
        $saraa = $generator->create_user(['firstname' => 'Saraa']);
        $generator->enrol_user($saraa->id, $course->id, 'student', 'manual', 0, 0, ENROL_USER_SUSPENDED);
        // A student of another course does not count.
        $generator->create_and_enrol($generator->create_course(), 'student');

        $this->assertSame(2, course_students::count($context));
        $users = course_students::get_users($context);
        $this->assertEquals([$bold->id, $anu->id], array_keys($users));
        $this->assertSame('Bold', $users[$bold->id]->firstname);
        $this->assertSame('Bat', $users[$bold->id]->lastname);

        // Without gradebook roles nobody is a student.
        $CFG->gradebookroles = '';
        $this->assertSame(0, course_students::count($context));
        $this->assertSame([], course_students::get_users($context));
    }

    /**
     * In separate groups mode a teacher without access to all groups is limited to their own groups.
     */
    public function test_separate_groups_limit_what_a_teacher_sees(): void {
        $this->resetAfterTest();
        $generator = $this->getDataGenerator();
        $course = $generator->create_course(['groupmode' => SEPARATEGROUPS, 'groupmodeforce' => 1]);
        $context = \core\context\course::instance($course->id);

        $editing = $generator->create_and_enrol($course, 'editingteacher');
        $tutor = $generator->create_and_enrol($course, 'teacher');
        $outsider = $generator->create_and_enrol($course, 'teacher');
        $anu = $generator->create_and_enrol($course, 'student', ['firstname' => 'Anu']);
        $bold = $generator->create_and_enrol($course, 'student', ['firstname' => 'Bold']);

        $groupa = $generator->create_group(['courseid' => $course->id]);
        $groupb = $generator->create_group(['courseid' => $course->id]);
        $generator->create_group_member(['groupid' => $groupa->id, 'userid' => $tutor->id]);
        $generator->create_group_member(['groupid' => $groupa->id, 'userid' => $anu->id]);
        $generator->create_group_member(['groupid' => $groupb->id, 'userid' => $bold->id]);

        // Editing teachers see all groups. A teacher in no group sees nobody.
        $this->assertNull(course_students::get_course_scope($course, $editing));
        $this->assertEquals([$groupa->id], course_students::get_course_scope($course, $tutor));
        $this->assertSame([], course_students::get_course_scope($course, $outsider));

        $this->assertEqualsCanonicalizing([$anu->id, $bold->id], array_keys(course_students::get_users($context)));
        $scope = course_students::get_course_scope($course, $tutor);
        $this->assertEquals([$anu->id], array_keys(course_students::get_users($context, $scope)));
        $this->assertSame(1, course_students::count($context, $scope));
        $this->assertSame([], course_students::get_users($context, []));
        $this->assertSame(0, course_students::count($context, []));

        // The same limit applies inside an activity.
        $assign = $generator->create_module('assign', ['course' => $course->id]);
        $cm = get_fast_modinfo($course)->get_cm($assign->cmid);
        $this->assertNull(course_students::get_activity_scope($cm, $editing));
        $this->assertEquals([$groupa->id], course_students::get_activity_scope($cm, $tutor));
        $this->assertSame([], course_students::get_activity_scope($cm, $outsider));

        // Without separate groups nobody is limited.
        $open = $generator->create_course(['groupmode' => VISIBLEGROUPS]);
        $generator->enrol_user($tutor->id, $open->id, 'teacher');
        $this->assertNull(course_students::get_course_scope($open, $tutor));
    }
}
