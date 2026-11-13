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
use local_darimaa\event\reminders_sent;
use local_darimaa\local\link_manager;
use PHPUnit\Framework\Attributes\CoversClass;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/mod/assign/locallib.php');

/**
 * Tests for the web service functions the chat bot calls.
 *
 * @package    local_darimaa
 * @category   test
 * @copyright  2026 Khuygbaatar Ochirkhuyag
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversClass(bot_function::class)]
#[CoversClass(get_user::class)]
#[CoversClass(link_account::class)]
#[CoversClass(unlink_account::class)]
#[CoversClass(get_deadlines::class)]
#[CoversClass(get_missing::class)]
#[CoversClass(send_reminders::class)]
#[CoversClass(\local_darimaa\local\deadlines::class)]
#[CoversClass(\local_darimaa\local\missing_submissions::class)]
final class bot_functions_test extends \advanced_testcase {
    /**
     * Creates the bot's service account and makes it the current user.
     *
     * @return \stdClass The bot user.
     */
    private function act_as_bot(): \stdClass {
        $generator = $this->getDataGenerator();
        $bot = $generator->create_user(['username' => 'darimaabot']);
        $roleid = $generator->create_role(['shortname' => 'darimaabot']);
        $syscontext = \core\context\system::instance();
        assign_capability('local/darimaa:botaccess', CAP_ALLOW, $roleid, $syscontext->id);
        role_assign($roleid, $bot->id, $syscontext->id);
        $this->setUser($bot);
        return $bot;
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
     * Creates a course with a teacher, two students and an assignment.
     *
     * @param int $duedate The assignment due date.
     * @return array The course, teacher, students, and the assignment's course module.
     */
    private function create_course(int $duedate): array {
        $generator = $this->getDataGenerator();
        $course = $generator->create_course(['fullname' => 'Biology 101']);
        $teacher = $generator->create_and_enrol($course, 'editingteacher', ['firstname' => 'Bat']);
        $students = [
            $generator->create_and_enrol($course, 'student', ['firstname' => 'Anu']),
            $generator->create_and_enrol($course, 'student', ['firstname' => 'Bold']),
        ];
        $instance = $generator->create_module('assign', [
            'course' => $course->id,
            'name' => 'Lab report',
            'assignsubmission_onlinetext_enabled' => 1,
            'duedate' => $duedate,
        ]);
        $cm = get_coursemodule_from_instance('assign', $instance->id);
        return [$course, $teacher, $students, $cm];
    }

    /**
     * Calls a function and checks its result against the declared return structure.
     *
     * @param string $classname The function class.
     * @param array $args The arguments.
     * @return array The cleaned result.
     */
    private function call(string $classname, array $args): array {
        $result = $classname::execute(...$args);
        return external_api::clean_returnvalue($classname::execute_returns(), $result);
    }

    /**
     * Only the bot's service account may call the functions.
     */
    public function test_requires_bot_capability(): void {
        $this->resetAfterTest();
        $this->setUser($this->getDataGenerator()->create_user());

        $this->expectException(\required_capability_exception::class);
        get_user::execute('messenger', 'psid-1');
    }

    /**
     * Unlinked accounts get the link page; linking with a code works through the API.
     */
    public function test_get_user_and_link_account(): void {
        $this->resetAfterTest();
        [, $teacher, $students] = $this->create_course(time() + DAYSECS);
        $this->act_as_bot();

        $result = $this->call(get_user::class, ['messenger', 'psid-1']);
        $this->assertFalse($result['linked']);
        $this->assertStringEndsWith('/local/darimaa/link.php', $result['linkurl']);

        $this->assertFalse($this->call(link_account::class, ['messenger', 'psid-1', 'WRONG123'])['linked']);

        $code = link_manager::create_code($students[0]->id, 'messenger');
        $result = $this->call(link_account::class, ['messenger', 'psid-1', $code]);
        $this->assertTrue($result['linked']);
        $this->assertSame('Anu', $result['firstname']);

        $result = $this->call(get_user::class, ['messenger', 'psid-1']);
        $this->assertTrue($result['linked']);
        $this->assertEquals($students[0]->id, $result['userid']);
        $this->assertFalse($result['isteacher']);

        $this->link($teacher, 'psid-2');
        $this->assertTrue($this->call(get_user::class, ['messenger', 'psid-2'])['isteacher']);

        $this->assertTrue($this->call(unlink_account::class, ['messenger', 'psid-1'])['unlinked']);
        $this->assertFalse($this->call(get_user::class, ['messenger', 'psid-1'])['linked']);
    }

    /**
     * Students see open work; it disappears once submitted.
     */
    public function test_get_deadlines(): void {
        $this->resetAfterTest();
        [, , $students, $cm] = $this->create_course(time() + 2 * DAYSECS);
        $this->link($students[0], 'psid-1');
        $bot = $this->act_as_bot();

        $result = $this->call(get_deadlines::class, ['messenger', 'psid-1', 7, 14]);
        $this->assertTrue($result['linked']);
        $this->assertCount(1, $result['items']);
        $this->assertSame('Lab report', $result['items'][0]['name']);
        $this->assertSame('Biology 101', $result['items'][0]['coursename']);
        $this->assertSame('assign', $result['items'][0]['modname']);
        $this->assertFalse($result['items'][0]['overdue']);
        $this->assertStringContainsString('/mod/assign/view.php?id=' . $cm->id, $result['items'][0]['url']);

        // Looking only one day ahead leaves it out.
        $this->assertCount(0, $this->call(get_deadlines::class, ['messenger', 'psid-1', 1, 0])['items']);

        // After submitting, there is nothing left to do.
        $this->setUser($students[0]);
        $this->getDataGenerator()->get_plugin_generator('mod_assign')->create_submission([
            'userid' => $students[0]->id,
            'cmid' => $cm->id,
            'onlinetext' => 'Done',
        ]);
        $assign = new \assign(\core\context\module::instance($cm->id), $cm, get_course($cm->course));
        $notices = [];
        $assign->submit_for_grading((object) ['userid' => $students[0]->id], $notices);
        $this->setUser($bot);
        $this->assertCount(0, $this->call(get_deadlines::class, ['messenger', 'psid-1', 7, 14])['items']);

        // Unknown accounts get nothing.
        $this->assertFalse($this->call(get_deadlines::class, ['messenger', 'psid-404', 7, 14])['linked']);
    }

    /**
     * Teachers see recent assignments with missing work and can send reminders.
     */
    public function test_get_missing_and_send_reminders(): void {
        global $DB;
        $this->resetAfterTest();
        [, $teacher, $students, $cm] = $this->create_course(time() - DAYSECS);
        // An old assignment is left out of the list.
        $this->getDataGenerator()->create_module('assign', [
            'course' => $cm->course,
            'name' => 'Old lab',
            'duedate' => time() - 90 * DAYSECS,
        ]);
        $this->link($teacher, 'psid-t');
        $bot = $this->act_as_bot();

        $result = $this->call(get_missing::class, ['messenger', 'psid-t', 30, 7]);
        $this->assertTrue($result['linked']);
        $this->assertCount(1, $result['assignments']);
        $this->assertEquals($cm->id, $result['assignments'][0]['cmid']);
        $this->assertSame(2, $result['assignments'][0]['missing']);

        set_config('defaultmessage', 'Hi {firstname}, please submit {assignment}.', 'local_darimaa');
        $messagesink = $this->redirectMessages();
        $eventsink = $this->redirectEvents();

        $result = $this->call(send_reminders::class, ['messenger', 'psid-t', $cm->id]);
        $this->assertSame(2, $result['sent']);
        $this->assertSame(2, $result['missing']);

        $messages = $messagesink->get_messages();
        $this->assertCount(2, $messages);
        foreach ($messages as $message) {
            $this->assertEquals($teacher->id, $message->useridfrom);
            $this->assertStringContainsString('please submit Lab report.', $message->fullmessage);
        }
        $this->assertEqualsCanonicalizing(
            [$students[0]->id, $students[1]->id],
            array_map(static fn($message) => $message->useridto, $messages)
        );

        $events = array_values(array_filter($eventsink->get_events(), static fn($e) => $e instanceof reminders_sent));
        $this->assertCount(1, $events);
        $this->assertEquals($teacher->id, $events[0]->userid);
        $this->assertNotEquals($bot->id, $events[0]->userid);
    }

    /**
     * A linked student cannot send reminders through the bot.
     */
    public function test_students_cannot_send_reminders(): void {
        $this->resetAfterTest();
        [, , $students, $cm] = $this->create_course(time() - DAYSECS);
        $this->link($students[0], 'psid-1');
        $this->act_as_bot();

        $this->assertCount(0, $this->call(get_missing::class, ['messenger', 'psid-1', 30, 7])['assignments']);

        $this->expectException(\required_capability_exception::class);
        send_reminders::execute('messenger', 'psid-1', $cm->id);
    }
}
