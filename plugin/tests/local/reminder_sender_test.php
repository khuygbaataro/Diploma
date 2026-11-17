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

use local_darimaa\event\reminders_sent;
use PHPUnit\Framework\Attributes\CoversClass;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/mod/assign/locallib.php');

/**
 * Tests for sending reminders.
 *
 * @package    local_darimaa
 * @category   test
 * @copyright  2026 Khuygbaatar Ochirkhuyag
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversClass(reminder_sender::class)]
#[CoversClass(reminders_sent::class)]
final class reminder_sender_test extends \advanced_testcase {
    /**
     * Each student gets a personal message and one event is logged.
     */
    public function test_send_personalises_each_message_and_logs_once(): void {
        $this->resetAfterTest();
        $generator = $this->getDataGenerator();
        $course = $generator->create_course(['fullname' => 'Biology 101']);
        $teacher = $generator->create_and_enrol($course, 'editingteacher');
        $anu = $generator->create_and_enrol($course, 'student', ['firstname' => 'Anu']);
        $bold = $generator->create_and_enrol($course, 'student', ['firstname' => 'Bold']);

        $instance = $generator->create_module('assign', ['course' => $course->id, 'name' => 'Lab report']);
        $cm = get_coursemodule_from_instance('assign', $instance->id);
        $assign = new \assign(\core\context\module::instance($cm->id), $cm, $course);

        $this->setUser($teacher);
        $messagesink = $this->redirectMessages();
        $eventsink = $this->redirectEvents();

        $template = "Hi {firstname},\nplease submit {assignment} for {course}: {link}\nDue: {duedate}";
        $sent = reminder_sender::send($assign, [$anu->id => $anu, $bold->id => $bold], $template, $teacher);

        $this->assertSame(2, $sent);

        $messages = $messagesink->get_messages();
        $this->assertCount(2, $messages);
        $link = (new \core\url('/mod/assign/view.php', ['id' => $cm->id]))->out(false);
        $texts = [];
        foreach ($messages as $message) {
            $this->assertSame('local_darimaa', $message->component);
            $this->assertSame('reminder', $message->eventtype);
            $this->assertEquals($teacher->id, $message->useridfrom);
            $this->assertSame('Reminder: Lab report', $message->subject);
            $this->assertStringContainsString('please submit Lab report for Biology 101: ' . $link, $message->fullmessage);
            $this->assertStringContainsString('Due: Not set', $message->fullmessage);
            $texts[$message->useridto] = $message->fullmessage;
        }
        $this->assertStringStartsWith('Hi Anu,', $texts[$anu->id]);
        $this->assertStringStartsWith('Hi Bold,', $texts[$bold->id]);

        $events = array_values(array_filter(
            $eventsink->get_events(),
            static fn($event) => $event instanceof reminders_sent
        ));
        $this->assertCount(1, $events);
        $this->assertSame(2, $events[0]->other['count']);
        $this->assertEquals($teacher->id, $events[0]->userid);
        $this->assertEquals($cm->id, $events[0]->contextinstanceid);
    }

    /**
     * Sending to nobody sends nothing and logs nothing.
     */
    public function test_send_to_nobody_logs_nothing(): void {
        $this->resetAfterTest();
        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $teacher = $generator->create_and_enrol($course, 'editingteacher');
        $instance = $generator->create_module('assign', ['course' => $course->id]);
        $cm = get_coursemodule_from_instance('assign', $instance->id);
        $assign = new \assign(\core\context\module::instance($cm->id), $cm, $course);

        $this->setUser($teacher);
        $eventsink = $this->redirectEvents();

        $this->assertSame(0, reminder_sender::send($assign, [], 'Hi {firstname}', $teacher));
        $this->assertCount(0, array_filter(
            $eventsink->get_events(),
            static fn($event) => $event instanceof reminders_sent
        ));
    }

    /**
     * Even when they are passed in, a suspended account and an account that cannot log in get no reminder.
     */
    public function test_send_skips_accounts_that_cannot_log_in(): void {
        $this->resetAfterTest();
        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $teacher = $generator->create_and_enrol($course, 'editingteacher');
        $anu = $generator->create_and_enrol($course, 'student', ['firstname' => 'Anu']);
        $suspended = $generator->create_and_enrol($course, 'student', ['firstname' => 'Tuya', 'suspended' => 1]);
        $nologin = $generator->create_and_enrol($course, 'student', ['firstname' => 'Gerel', 'auth' => 'nologin']);

        $instance = $generator->create_module('assign', ['course' => $course->id]);
        $cm = get_coursemodule_from_instance('assign', $instance->id);
        $assign = new \assign(\core\context\module::instance($cm->id), $cm, $course);

        $this->setUser($teacher);
        $messagesink = $this->redirectMessages();
        $users = [$anu->id => $anu, $suspended->id => $suspended, $nologin->id => $nologin];

        $this->assertSame(1, reminder_sender::send($assign, $users, 'Hi {firstname}', $teacher));
        $messages = $messagesink->get_messages();
        $this->assertCount(1, $messages);
        $this->assertEquals($anu->id, reset($messages)->useridto);
    }

    /**
     * Creates a course with an editing teacher, two students and an assignment.
     *
     * @return array The teacher, the two students and the assignment.
     */
    private function create_assignment_with_students(): array {
        $generator = $this->getDataGenerator();
        $course = $generator->create_course(['fullname' => 'Biology 101']);
        $teacher = $generator->create_and_enrol($course, 'editingteacher', ['firstname' => 'Bat']);
        $anu = $generator->create_and_enrol($course, 'student', ['firstname' => 'Anu']);
        $bold = $generator->create_and_enrol($course, 'student', ['firstname' => 'Bold']);
        $instance = $generator->create_module('assign', ['course' => $course->id, 'name' => 'Lab report']);
        $cm = get_coursemodule_from_instance('assign', $instance->id);
        $assign = new \assign(\core\context\module::instance($cm->id), $cm, $course);
        return [$teacher, $anu, $bold, $assign];
    }

    /**
     * Returns the chat messages one user sent to another.
     *
     * @param \stdClass $from The sender.
     * @param \stdClass $to The recipient.
     * @return \stdClass[] Message records, oldest first.
     */
    private function chat_messages(\stdClass $from, \stdClass $to): array {
        global $DB;

        $conversationid = \core_message\api::get_conversation_between_users([$from->id, $to->id]);
        if (!$conversationid) {
            return [];
        }
        return array_values($DB->get_records('messages', ['conversationid' => $conversationid, 'useridfrom' => $from->id], 'id'));
    }

    /**
     * A notification that Moodle saved counts as delivered, even when a channel such as email failed.
     *
     * Moodle's message_send() returns false in that case, although the student already sees the
     * notification. Counting it as not sent made teachers send the reminder again.
     */
    public function test_delivery_status_tells_a_saved_notification_from_a_lost_one(): void {
        $message = new \core\message\message();
        $this->assertSame(reminder_sender::DELIVERED, reminder_sender::delivery_status(42, $message));
        $this->assertSame(reminder_sender::NOT_DELIVERED, reminder_sender::delivery_status(false, $message));

        // Moodle saved the notification, and then a channel such as email failed.
        $message->savedmessageid = 42;
        $this->assertSame(reminder_sender::PARTLY_DELIVERED, reminder_sender::delivery_status(false, $message));
        $this->assertSame(reminder_sender::DELIVERED, reminder_sender::delivery_status(42, $message));
    }

    /**
     * A reminder can go as a Moodle chat message from the teacher instead of a notification.
     */
    public function test_send_as_chat_message(): void {
        global $DB;
        $this->resetAfterTest();
        [$teacher, $anu, $bold, $assign] = $this->create_assignment_with_students();
        $this->setUser($teacher);
        $this->redirectMessages();
        $eventsink = $this->redirectEvents();

        $users = [$anu->id => $anu, $bold->id => $bold];
        $template = "Hi {firstname},\nplease submit {assignment}.";
        $report = reminder_sender::send_with_report($assign, $users, $template, $teacher, reminder_sender::CHANNEL_CHAT);

        $this->assertSame(['sent' => 2, 'incomplete' => 0, 'chat' => 2, 'chatrefused' => 0], $report);
        $this->assertSame(0, $DB->count_records('notifications', ['component' => 'local_darimaa']));
        $messages = $this->chat_messages($teacher, $anu);
        $this->assertCount(1, $messages);
        $this->assertStringContainsString('Hi Anu', $messages[0]->fullmessage);
        $this->assertStringContainsString('please submit Lab report.', $messages[0]->fullmessage);
        $this->assertCount(1, $this->chat_messages($teacher, $bold));

        $events = array_values(array_filter($eventsink->get_events(), static fn($e) => $e instanceof reminders_sent));
        $this->assertCount(1, $events);
        $this->assertSame(2, $events[0]->other['count']);
        $this->assertSame('chat', $events[0]->other['channel']);
    }

    /**
     * Both ways at once: a notification and a chat message, and a later reminder joins the same chat.
     */
    public function test_send_both_ways(): void {
        global $DB;
        $this->resetAfterTest();
        [$teacher, $anu, $bold, $assign] = $this->create_assignment_with_students();
        $this->setUser($teacher);
        $this->redirectMessages();

        $users = [$anu->id => $anu, $bold->id => $bold];
        $report = reminder_sender::send_with_report($assign, $users, 'Hi {firstname}', $teacher, reminder_sender::CHANNEL_BOTH);
        $this->assertSame(['sent' => 2, 'incomplete' => 0, 'chat' => 2, 'chatrefused' => 0], $report);
        $this->assertSame(2, $DB->count_records('notifications', ['component' => 'local_darimaa']));

        $conversationid = \core_message\api::get_conversation_between_users([$teacher->id, $anu->id]);
        reminder_sender::send_with_report($assign, [$anu->id => $anu], 'Hi again', $teacher, reminder_sender::CHANNEL_CHAT);
        $this->assertSame($conversationid, \core_message\api::get_conversation_between_users([$teacher->id, $anu->id]));
        $this->assertCount(2, $this->chat_messages($teacher, $anu));
    }

    /**
     * Moodle decides who may get a chat message: those who cannot are counted, and the notification still goes.
     */
    public function test_chat_is_refused_when_moodle_does_not_allow_it(): void {
        global $DB;
        $this->resetAfterTest();
        [$teacher, $anu, $bold, $assign] = $this->create_assignment_with_students();
        $this->setUser($teacher);
        $this->redirectMessages();
        $users = [$anu->id => $anu, $bold->id => $bold];

        // Messaging is off on the site: nobody can get a chat message.
        set_config('messaging', 0);
        $report = reminder_sender::send_with_report($assign, $users, 'Hi {firstname}', $teacher, reminder_sender::CHANNEL_CHAT);
        $this->assertSame(['sent' => 0, 'incomplete' => 0, 'chat' => 0, 'chatrefused' => 2], $report);

        // Messaging is on, but the teacher may not send messages: the notifications still go.
        set_config('messaging', 1);
        $roleid = $DB->get_field('role', 'id', ['shortname' => 'user'], MUST_EXIST);
        assign_capability('moodle/site:sendmessage', CAP_PROHIBIT, $roleid, \core\context\system::instance()->id, true);
        accesslib_clear_all_caches_for_unit_testing();

        $report = reminder_sender::send_with_report($assign, $users, 'Hi {firstname}', $teacher, reminder_sender::CHANNEL_BOTH);
        $this->assertSame(['sent' => 2, 'incomplete' => 0, 'chat' => 0, 'chatrefused' => 2], $report);
        $this->assertSame([], $this->chat_messages($teacher, $anu));
    }

    /**
     * An unknown way of sending is a programming error.
     */
    public function test_unknown_channel_is_refused(): void {
        $this->resetAfterTest();
        [$teacher, $anu, , $assign] = $this->create_assignment_with_students();

        $this->expectException(\coding_exception::class);
        reminder_sender::send_with_report($assign, [$anu->id => $anu], 'Hi', $teacher, 'telegram');
    }
}
