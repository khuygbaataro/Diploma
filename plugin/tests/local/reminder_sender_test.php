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
}
