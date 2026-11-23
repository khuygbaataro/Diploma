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

/**
 * Finds a teacher's students by name and sends them a Moodle chat message from the teacher.
 *
 * The bot calls this only after the teacher has read the message and tapped "Yes, send".
 *
 * @package    local_darimaa
 * @copyright  2026 Khuygbaatar Ochirkhuyag
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class student_messages {
    /** @var int The longest message, in characters. */
    public const MAX_LENGTH = 2000;

    /** @var int The most students a search returns. */
    public const MAX_FOUND = 10;

    /**
     * Finds the students whose name or student code contains the given text, in the teacher's courses.
     *
     * Only courses where the teacher can send reminders count, and separate groups are respected.
     *
     * @param \stdClass $teacher The teacher.
     * @param string $query Part of a name or a student code, at least two characters.
     * @return array[] Rows with userid, code, fullname, courseid and coursename, by name.
     */
    public static function find(\stdClass $teacher, string $query): array {
        $query = \core_text::strtolower(trim($query));
        if (\core_text::strlen($query) < 2) {
            return [];
        }

        $rows = [];
        foreach (enrol_get_users_courses($teacher->id, true, 'id') as $enrolled) {
            $context = \core\context\course::instance($enrolled->id);
            if (!has_capability('local/darimaa:sendreminders', $context, $teacher)) {
                continue;
            }
            $course = get_course($enrolled->id);
            $students = course_students::get_users($context, course_students::get_course_scope($course, $teacher));
            $codes = student_code::get_for_users(array_keys($students));
            $coursename = format_string($course->fullname, true, ['context' => $context, 'escape' => false]);

            foreach ($students as $student) {
                $fullname = fullname($student);
                $code = $codes[$student->id] ?? '';
                $haystack = \core_text::strtolower($fullname . ' ' . $code);
                if (!str_contains($haystack, $query)) {
                    continue;
                }
                $rows[] = [
                    'userid' => (int) $student->id,
                    'code' => $code,
                    'fullname' => $fullname,
                    'courseid' => (int) $course->id,
                    'coursename' => $coursename,
                ];
            }
        }

        usort($rows, static fn(array $a, array $b): int =>
            [$a['fullname'], $a['coursename'], $a['userid']] <=> [$b['fullname'], $b['coursename'], $b['userid']]);
        return array_slice($rows, 0, self::MAX_FOUND);
    }

    /**
     * Sends a Moodle chat message from a teacher to one of the students of a course.
     *
     * @param \stdClass $teacher The teacher.
     * @param \stdClass $course The course the student is in.
     * @param int $userid The student.
     * @param string $text The message, plain text.
     * @throws \core\exception\required_capability_exception When the teacher may not send reminders in the course.
     * @throws \core\exception\moodle_exception When the message cannot be sent.
     */
    public static function send(\stdClass $teacher, \stdClass $course, int $userid, string $text): void {
        $context = \core\context\course::instance($course->id);
        if (!has_capability('local/darimaa:sendreminders', $context, $teacher)) {
            throw new \core\exception\required_capability_exception($context, 'local/darimaa:sendreminders', 'nopermissions', '');
        }
        $text = trim($text);
        if ($text === '' || \core_text::strlen($text) > self::MAX_LENGTH) {
            throw new \core\exception\moodle_exception('messagelength', 'local_darimaa', '', self::MAX_LENGTH);
        }
        if (!reminder_sender::can_chat($teacher)) {
            throw new \core\exception\moodle_exception('chatnotpossible', 'local_darimaa');
        }
        $students = course_students::get_users($context, course_students::get_course_scope($course, $teacher));
        if (!isset($students[$userid])) {
            throw new \core\exception\moodle_exception('notyourstudent', 'local_darimaa');
        }
        if (!reminder_sender::send_chat($teacher, $students[$userid], nl2br(s($text), false))) {
            throw new \core\exception\moodle_exception('messagerefused', 'local_darimaa');
        }
    }
}
