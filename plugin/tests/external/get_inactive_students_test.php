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
use local_darimaa\local\inactive_students;
use local_darimaa\local\link_manager;
use local_darimaa\local\missing_submissions;
use PHPUnit\Framework\Attributes\CoversClass;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/mod/assign/locallib.php');

/**
 * Tests for the report of inactive students in a course.
 *
 * @package    local_darimaa
 * @category   test
 * @copyright  2026 Khuygbaatar Ochirkhuyag
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversClass(get_inactive_students::class)]
#[CoversClass(inactive_students::class)]
#[CoversClass(missing_submissions::class)]
final class get_inactive_students_test extends \advanced_testcase {
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
     * @param int $days The number of days for "inactive".
     * @return array The cleaned result.
     */
    private function call(string $externalid, int $courseid, int $days = 7): array {
        $result = get_inactive_students::execute('messenger', $externalid, $courseid, $days);
        return external_api::clean_returnvalue(get_inactive_students::execute_returns(), $result);
    }

    /**
     * Creates a course that started two months ago, with a teacher and three students.
     *
     * @param array $options Extra course settings.
     * @return array The course, the teacher and the students keyed by first name in small letters.
     */
    private function create_course(array $options = []): array {
        $generator = $this->getDataGenerator();
        $course = $generator->create_course($options + [
            'fullname' => 'Biology 101',
            'startdate' => time() - 60 * DAYSECS,
        ]);
        $teacher = $generator->create_and_enrol($course, 'editingteacher', ['firstname' => 'Bat', 'lastname' => 'Bold']);
        $students = [
            'anu' => $generator->create_and_enrol($course, 'student', [
                'firstname' => 'Anu',
                'lastname' => 'Dorj',
                'username' => 'anu01',
                'idnumber' => 'ID-001',
            ]),
            'bold' => $generator->create_and_enrol($course, 'student', [
                'firstname' => 'Bold',
                'lastname' => 'Bat',
                'username' => 'bold02',
                'idnumber' => 'ID-002',
            ]),
            'saraa' => $generator->create_and_enrol($course, 'student', [
                'firstname' => 'Saraa',
                'lastname' => 'Tsetseg',
                'username' => 'saraa03',
                'idnumber' => 'ID-003',
            ]),
        ];
        return [$course, $teacher, $students];
    }

    /**
     * Records when a user last accessed a course.
     *
     * @param \stdClass $course The course.
     * @param \stdClass $user The user.
     * @param int $time The access time.
     */
    private function access(\stdClass $course, \stdClass $user, int $time): void {
        global $DB;
        $DB->insert_record('user_lastaccess', (object) [
            'userid' => $user->id,
            'courseid' => $course->id,
            'timeaccess' => $time,
        ]);
    }

    /**
     * Writes one of the user's own actions in the course to the standard log.
     *
     * @param \stdClass $course The course.
     * @param \stdClass $user The user.
     * @param int $time The time of the action.
     */
    private function log(\stdClass $course, \stdClass $user, int $time): void {
        global $DB;
        $context = \core\context\course::instance($course->id);
        $DB->insert_record('logstore_standard_log', (object) [
            'eventname' => '\core\event\course_viewed',
            'component' => 'core',
            'action' => 'viewed',
            'target' => 'course',
            'crud' => 'r',
            'edulevel' => 2,
            'contextid' => $context->id,
            'contextlevel' => CONTEXT_COURSE,
            'contextinstanceid' => $course->id,
            'userid' => $user->id,
            'courseid' => $course->id,
            'anonymous' => 0,
            'timecreated' => $time,
            'origin' => 'web',
            'realuserid' => null,
        ]);
    }

    /**
     * Creates an assignment with online text.
     *
     * @param \stdClass $course The course.
     * @param int $duedate The due date.
     * @return \assign
     */
    private function create_assignment(\stdClass $course, int $duedate): \assign {
        $instance = $this->getDataGenerator()->create_module('assign', [
            'course' => $course->id,
            'assignsubmission_onlinetext_enabled' => 1,
            'duedate' => $duedate,
        ]);
        $cm = get_coursemodule_from_instance('assign', $instance->id);
        return new \assign(\core\context\module::instance($cm->id), $cm, $course);
    }

    /**
     * Submits an online text answer for a student.
     *
     * @param \assign $assign The assignment.
     * @param \stdClass $student The student.
     */
    private function submit(\assign $assign, \stdClass $student): void {
        $this->getDataGenerator()->get_plugin_generator('mod_assign')->create_submission([
            'userid' => $student->id,
            'cmid' => $assign->get_course_module()->id,
            'onlinetext' => 'My answer',
        ]);
        $this->setUser($student);
        $notices = [];
        $assign->submit_for_grading((object) ['userid' => $student->id], $notices);
    }

    /**
     * The report shows last access, time spent and missing work, with inactive students first.
     */
    public function test_report_shows_access_time_and_missing_work(): void {
        global $DB;
        $this->resetAfterTest();
        [$course, $teacher, $students] = $this->create_course();
        $now = time();

        // Anu came an hour ago, Bold ten days ago, Saraa never.
        $this->access($course, $students['anu'], $now - HOURSECS);
        $this->access($course, $students['bold'], $now - 10 * DAYSECS);
        // Three days ago Anu worked for ten minutes.
        $this->log($course, $students['anu'], $now - 3 * DAYSECS);
        $this->log($course, $students['anu'], $now - 3 * DAYSECS + 10 * MINSECS);

        // Two assignments are past their due date and one is still open. Anu submitted the first.
        $first = $this->create_assignment($course, $now - 2 * DAYSECS);
        $second = $this->create_assignment($course, $now - DAYSECS);
        $this->create_assignment($course, $now + DAYSECS);
        $this->submit($first, $students['anu']);
        // Saraa has an extension for the second assignment.
        $DB->insert_record('assign_user_flags', (object) [
            'userid' => $students['saraa']->id,
            'assignment' => $second->get_instance()->id,
            'locked' => 0,
            'mailed' => 0,
            'extensionduedate' => $now + DAYSECS,
            'workflowstate' => '',
            'allocatedmarker' => 0,
        ]);

        set_config('enabled_stores', 'logstore_standard', 'tool_log');
        $this->link($teacher, 'psid-t');
        $this->act_as_bot();
        $result = $this->call('psid-t', $course->id, 7);

        $this->assertTrue($result['linked']);
        $this->assertSame('Biology 101', $result['coursename']);
        $this->assertSame(7, $result['days']);
        $this->assertTrue($result['lastaccessavailable']);
        $this->assertTrue($result['timeavailable']);
        $this->assertSame(['Saraa Tsetseg', 'Bold Bat', 'Anu Dorj'], array_column($result['students'], 'fullname'));

        [$saraa, $bold, $anu] = $result['students'];
        $this->assertSame([
            'code' => 'saraa03',
            'fullname' => 'Saraa Tsetseg',
            'lastaccess' => 0,
            'dayssince' => -1,
            'minutes' => 0,
            'missing' => 1,
            'inactive' => true,
        ], $saraa);
        $this->assertSame([
            'code' => 'bold02',
            'fullname' => 'Bold Bat',
            'lastaccess' => $now - 10 * DAYSECS,
            'dayssince' => 10,
            'minutes' => 0,
            'missing' => 2,
            'inactive' => true,
        ], $bold);
        $this->assertSame([
            'code' => 'anu01',
            'fullname' => 'Anu Dorj',
            'lastaccess' => $now - HOURSECS,
            'dayssince' => 0,
            'minutes' => 10,
            'missing' => 1,
            'inactive' => false,
        ], $anu);

        // With a threshold of two weeks only Saraa is inactive. The others follow in name order.
        $result = $this->call('psid-t', $course->id, 14);
        $this->assertSame(14, $result['days']);
        $this->assertSame(['Saraa Tsetseg', 'Anu Dorj', 'Bold Bat'], array_column($result['students'], 'fullname'));
        $this->assertSame([true, false, false], array_column($result['students'], 'inactive'));

        // The student code follows the plugin setting.
        set_config('studentcodefield', 'idnumber', 'local_darimaa');
        $result = $this->call('psid-t', $course->id, 14);
        $this->assertSame(['ID-003', 'ID-001', 'ID-002'], array_column($result['students'], 'code'));

        // Without the standard log store there is no time estimate.
        set_config('enabled_stores', '', 'tool_log');
        $result = $this->call('psid-t', $course->id, 14);
        $this->assertFalse($result['timeavailable']);
        $this->assertSame([-1, -1, -1], array_column($result['students'], 'minutes'));
    }

    /**
     * When the site hides the last access, only users who may see hidden fields get it.
     */
    public function test_hidden_last_access_stays_hidden(): void {
        global $CFG, $DB;
        $this->resetAfterTest();
        [$course, $teacher, $students] = $this->create_course();
        $this->access($course, $students['bold'], time() - 10 * DAYSECS);
        $this->link($teacher, 'psid-t');
        $this->act_as_bot();

        // Editing teachers may see hidden user fields.
        $CFG->hiddenuserfields = 'lastaccess';
        $result = $this->call('psid-t', $course->id);
        $this->assertTrue($result['lastaccessavailable']);
        $this->assertSame([true, true, true], array_column($result['students'], 'inactive'));

        // Without that permission the last access is not given and nobody is called inactive.
        $roleid = $DB->get_field('role', 'id', ['shortname' => 'editingteacher'], MUST_EXIST);
        $context = \core\context\course::instance($course->id);
        assign_capability('moodle/course:viewhiddenuserfields', CAP_PROHIBIT, $roleid, $context->id);

        $result = $this->call('psid-t', $course->id);
        $this->assertFalse($result['lastaccessavailable']);
        $this->assertSame([0, 0, 0], array_column($result['students'], 'lastaccess'));
        $this->assertSame([-1, -1, -1], array_column($result['students'], 'dayssince'));
        $this->assertSame([false, false, false], array_column($result['students'], 'inactive'));
        $this->assertSame(['Anu Dorj', 'Bold Bat', 'Saraa Tsetseg'], array_column($result['students'], 'fullname'));
    }

    /**
     * In separate groups mode a teacher sees only the students of their own groups.
     */
    public function test_separate_groups_limit_the_report(): void {
        $this->resetAfterTest();
        $generator = $this->getDataGenerator();
        [$course, $teacher, $students] = $this->create_course(['groupmode' => SEPARATEGROUPS, 'groupmodeforce' => 1]);
        $tutor = $generator->create_and_enrol($course, 'teacher', ['firstname' => 'Tuya']);
        $outsider = $generator->create_and_enrol($course, 'teacher', ['firstname' => 'Gerel']);

        $groupa = $generator->create_group(['courseid' => $course->id]);
        $groupb = $generator->create_group(['courseid' => $course->id]);
        $generator->create_group_member(['groupid' => $groupa->id, 'userid' => $tutor->id]);
        $generator->create_group_member(['groupid' => $groupa->id, 'userid' => $students['anu']->id]);
        $generator->create_group_member(['groupid' => $groupb->id, 'userid' => $students['bold']->id]);
        $this->create_assignment($course, time() - DAYSECS);

        $this->link($teacher, 'psid-t');
        $this->link($tutor, 'psid-a');
        $this->link($outsider, 'psid-o');
        $this->act_as_bot();

        // The editing teacher has access to all groups.
        $this->assertCount(3, $this->call('psid-t', $course->id)['students']);

        $result = $this->call('psid-a', $course->id);
        $this->assertSame(['Anu Dorj'], array_column($result['students'], 'fullname'));
        $this->assertSame(1, $result['students'][0]['missing']);

        // A teacher who is in no group sees nobody.
        $this->assertSame([], $this->call('psid-o', $course->id)['students']);
    }

    /**
     * A student cannot ask for the report of their own course.
     */
    public function test_students_are_refused(): void {
        $this->resetAfterTest();
        [$course, , $students] = $this->create_course();
        $this->link($students['anu'], 'psid-1');
        $this->act_as_bot();

        $this->expectException(\required_capability_exception::class);
        get_inactive_students::execute('messenger', 'psid-1', $course->id);
    }

    /**
     * A teacher cannot ask for the report of a course they do not teach.
     */
    public function test_teachers_of_other_courses_are_refused(): void {
        $this->resetAfterTest();
        [$course] = $this->create_course();
        $othercourse = $this->getDataGenerator()->create_course();
        $stranger = $this->getDataGenerator()->create_and_enrol($othercourse, 'editingteacher');
        $this->link($stranger, 'psid-x');
        $this->act_as_bot();

        $this->expectException(\required_capability_exception::class);
        get_inactive_students::execute('messenger', 'psid-x', $course->id);
    }

    /**
     * Unknown accounts are told they are not linked, and only the bot may call the function.
     */
    public function test_unknown_accounts_and_other_callers(): void {
        $this->resetAfterTest();
        [$course] = $this->create_course();
        $this->act_as_bot();

        $result = $this->call('psid-404', $course->id, 0);
        $this->assertFalse($result['linked']);
        $this->assertSame(1, $result['days']);
        $this->assertSame([], $result['students']);

        $this->setUser($this->getDataGenerator()->create_user());
        $this->expectException(\required_capability_exception::class);
        get_inactive_students::execute('messenger', 'psid-404', $course->id);
    }
}
