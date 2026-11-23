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
use local_darimaa\local\student_messages;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Tests for finding students by name and sending them a teacher's message through the bot.
 *
 * @package    local_darimaa
 * @category   test
 * @copyright  2026 Khuygbaatar Ochirkhuyag
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversClass(find_students::class)]
#[CoversClass(send_message::class)]
#[CoversClass(student_messages::class)]
final class student_messages_test extends \advanced_testcase {
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
        link_manager::link('telegram', link_manager::create_code($user->id, 'telegram'), $externalid);
    }

    /**
     * Creates two courses taught by the same teacher, and a third course of someone else.
     *
     * @return array The teacher, the courses and the students keyed by first name in small letters.
     */
    private function create_courses(): array {
        $generator = $this->getDataGenerator();
        $biology = $generator->create_course(['fullname' => 'Biology 101']);
        $chemistry = $generator->create_course(['fullname' => 'Chemistry']);
        $other = $generator->create_course(['fullname' => 'History']);
        $teacher = $generator->create_and_enrol($biology, 'editingteacher', ['firstname' => 'Bat', 'lastname' => 'Bold']);
        $generator->enrol_user($teacher->id, $chemistry->id, 'editingteacher');

        $students = [
            'khatnaa' => $generator->create_and_enrol($biology, 'student', [
                'firstname' => 'Khatnaa',
                'lastname' => 'Bat',
                'username' => 'sw26b022',
            ]),
            'anu' => $generator->create_and_enrol($chemistry, 'student', [
                'firstname' => 'Anu',
                'lastname' => 'Dorj',
                'username' => 'sw26b001',
            ]),
            'khongor' => $generator->create_and_enrol($other, 'student', [
                'firstname' => 'Khongor',
                'lastname' => 'Od',
                'username' => 'sw26b099',
            ]),
        ];
        return [$teacher, ['biology' => $biology, 'chemistry' => $chemistry, 'other' => $other], $students];
    }

    /**
     * Calls find_students and checks the result against the declared return structure.
     *
     * @param string $externalid The chat account id.
     * @param string $query Part of a name or code.
     * @return array
     */
    private function find(string $externalid, string $query): array {
        $result = find_students::execute('telegram', $externalid, $query);
        return external_api::clean_returnvalue(find_students::execute_returns(), $result);
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
     * Students are found by part of the name or the code, only in the teacher's own courses.
     */
    public function test_find_by_name_or_code_in_own_courses(): void {
        $this->resetAfterTest();
        [$teacher, $courses, $students] = $this->create_courses();
        $this->link($teacher, 'tg-teacher');
        $this->act_as_bot();

        $result = $this->find('tg-teacher', 'khat');
        $this->assertTrue($result['linked']);
        $this->assertSame([[
            'userid' => (int) $students['khatnaa']->id,
            'code' => 'sw26b022',
            'fullname' => 'Khatnaa Bat',
            'courseid' => (int) $courses['biology']->id,
            'coursename' => 'Biology 101',
        ]], $result['students']);

        // Case does not matter, and the code counts too.
        $this->assertSame(['Anu Dorj'], array_column($this->find('tg-teacher', 'SW26B001')['students'], 'fullname'));
        // A student of someone else's course is never found.
        $this->assertSame([], $this->find('tg-teacher', 'Khongor')['students']);
        // The text "kh" matches Khatnaa only; the History student stays hidden.
        $this->assertSame(['Khatnaa Bat'], array_column($this->find('tg-teacher', 'kh')['students'], 'fullname'));
        // One letter is too little.
        $this->assertSame([], $this->find('tg-teacher', 'k')['students']);
    }

    /**
     * In separate groups mode a teacher finds only the students of their own groups.
     */
    public function test_find_respects_separate_groups(): void {
        $this->resetAfterTest();
        $generator = $this->getDataGenerator();
        $course = $generator->create_course(['groupmode' => SEPARATEGROUPS, 'groupmodeforce' => 1]);
        $tutor = $generator->create_and_enrol($course, 'teacher');
        $mine = $generator->create_and_enrol($course, 'student', ['firstname' => 'Saraa', 'lastname' => 'One']);
        $generator->create_and_enrol($course, 'student', ['firstname' => 'Saraa', 'lastname' => 'Two']);
        $group = $generator->create_group(['courseid' => $course->id]);
        $generator->create_group_member(['groupid' => $group->id, 'userid' => $tutor->id]);
        $generator->create_group_member(['groupid' => $group->id, 'userid' => $mine->id]);
        $this->link($tutor, 'tg-tutor');
        $this->act_as_bot();

        $this->assertSame(['Saraa One'], array_column($this->find('tg-tutor', 'saraa')['students'], 'fullname'));
    }

    /**
     * The teacher's message reaches the student as a Moodle chat message from the teacher.
     */
    public function test_send_message_to_own_student(): void {
        $this->resetAfterTest();
        [$teacher, $courses, $students] = $this->create_courses();
        $this->link($teacher, 'tg-teacher');
        $this->act_as_bot();
        $this->redirectMessages();

        $result = send_message::execute(
            'telegram',
            'tg-teacher',
            $courses['biology']->id,
            $students['khatnaa']->id,
            "Сайн байна уу!\nХичээлдээ суугаарай."
        );
        $result = external_api::clean_returnvalue(send_message::execute_returns(), $result);

        $this->assertSame(['linked' => true, 'sent' => true], $result);
        $messages = $this->chat_messages($teacher, $students['khatnaa']);
        $this->assertCount(1, $messages);
        // Both lines arrive; Moodle decides how to store the line break.
        $this->assertStringContainsString('Сайн байна уу!', $messages[0]->fullmessage);
        $this->assertStringContainsString('Хичээлдээ суугаарай.', $messages[0]->fullmessage);
    }

    /**
     * A student of another course, or of the right course but another teacher, cannot get a message.
     */
    public function test_send_message_only_to_own_students_of_that_course(): void {
        $this->resetAfterTest();
        [$teacher, $courses, $students] = $this->create_courses();
        $this->link($teacher, 'tg-teacher');
        $this->act_as_bot();

        // Anu is the teacher's student, but in Chemistry, not in Biology.
        try {
            send_message::execute('telegram', 'tg-teacher', $courses['biology']->id, $students['anu']->id, 'Hi');
            $this->fail('A student of another course got a message');
        } catch (\core\exception\moodle_exception $exception) {
            $this->assertSame('notyourstudent', $exception->errorcode);
        }

        // A course the teacher does not teach.
        $this->expectException(\core\exception\required_capability_exception::class);
        send_message::execute('telegram', 'tg-teacher', $courses['other']->id, $students['khongor']->id, 'Hi');
    }

    /**
     * Empty or too long messages are refused, and so is sending when site messaging is off.
     */
    public function test_send_message_checks_the_text_and_messaging(): void {
        $this->resetAfterTest();
        [$teacher, $courses, $students] = $this->create_courses();
        $this->link($teacher, 'tg-teacher');
        $this->act_as_bot();
        $this->redirectMessages();

        $send = fn(string $text) => send_message::execute(
            'telegram',
            'tg-teacher',
            $courses['biology']->id,
            $students['khatnaa']->id,
            $text
        );
        foreach (['   ', str_repeat('a', student_messages::MAX_LENGTH + 1)] as $text) {
            try {
                $send($text);
                $this->fail('A message of the wrong length was sent');
            } catch (\core\exception\moodle_exception $exception) {
                $this->assertSame('messagelength', $exception->errorcode);
            }
        }

        set_config('messaging', 0);
        try {
            $send('Hi');
            $this->fail('A message was sent with messaging off');
        } catch (\core\exception\moodle_exception $exception) {
            $this->assertSame('chatnotpossible', $exception->errorcode);
        }
        $this->assertSame([], $this->chat_messages($teacher, $students['khatnaa']));
    }

    /**
     * A student cannot use the functions, unknown accounts are told they are not linked, and only the bot may call.
     */
    public function test_students_unknown_accounts_and_other_callers(): void {
        $this->resetAfterTest();
        [, $courses, $students] = $this->create_courses();
        $this->link($students['anu'], 'tg-anu');
        $this->act_as_bot();

        // A student finds nobody: they teach no course.
        $this->assertSame([], $this->find('tg-anu', 'khat')['students']);
        $this->assertSame(['linked' => false, 'students' => []], $this->find('tg-404', 'khat'));
        $this->assertSame(
            ['linked' => false, 'sent' => false],
            send_message::execute('telegram', 'tg-404', $courses['biology']->id, $students['khatnaa']->id, 'Hi')
        );

        $this->setUser($this->getDataGenerator()->create_user());
        $this->expectException(\core\exception\required_capability_exception::class);
        find_students::execute('telegram', 'tg-404', 'khat');
    }
}
