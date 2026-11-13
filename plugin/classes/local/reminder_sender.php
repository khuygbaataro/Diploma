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

use core\output\html_writer;
use local_darimaa\event\reminders_sent;

/**
 * Sends reminder notifications to students.
 *
 * @package    local_darimaa
 * @copyright  2026 Khuygbaatar Ochirkhuyag
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class reminder_sender {
    /**
     * Sends one reminder to each student and logs the action.
     *
     * The template can contain {firstname}, {lastname}, {assignment}, {course},
     * {duedate} and {link}. Dates are shown in each student's time zone.
     *
     * @param \assign $assign The assignment.
     * @param \stdClass[] $users Full user records of the students to remind.
     * @param string $template The message text written by the teacher.
     * @param \stdClass $from The user who sends the reminders.
     * @return int Number of reminders that were sent.
     */
    public static function send(\assign $assign, array $users, string $template, \stdClass $from): int {
        $cm = $assign->get_course_module();
        $course = $assign->get_course();
        $context = $assign->get_context();
        $coursecontext = \core\context\course::instance($course->id);

        $link = new \core\url('/mod/assign/view.php', ['id' => $cm->id]);
        $assignname = format_string($assign->get_instance()->name, true, ['context' => $context, 'escape' => false]);
        $coursename = format_string($course->fullname, true, ['context' => $coursecontext, 'escape' => false]);
        $strings = get_string_manager();

        $sent = 0;
        foreach ($users as $user) {
            $lang = !empty($user->lang) ? $user->lang : null;

            $duedate = missing_submissions::get_user_duedate($assign, $user->id);
            if ($duedate) {
                $format = $strings->get_string('strftimedatetime', 'langconfig', null, $lang);
                $duestr = userdate($duedate, $format, \core_date::get_user_timezone($user));
            } else {
                $duestr = $strings->get_string('noduedate', 'local_darimaa', null, $lang);
            }

            $values = [
                '{firstname}' => $user->firstname,
                '{lastname}' => $user->lastname,
                '{assignment}' => $assignname,
                '{course}' => $coursename,
                '{duedate}' => $duestr,
                '{link}' => $link->out(false),
            ];
            $htmlvalues = array_map('s', $values);
            $htmlvalues['{link}'] = html_writer::link($link, s($link->out(false)));

            $subject = $strings->get_string('remindersubject', 'local_darimaa', $assignname, $lang);

            $message = new \core\message\message();
            $message->component = 'local_darimaa';
            $message->name = 'reminder';
            $message->userfrom = $from;
            $message->userto = $user;
            $message->subject = $subject;
            $message->fullmessage = strtr($template, $values);
            $message->fullmessageformat = FORMAT_PLAIN;
            $message->fullmessagehtml = nl2br(strtr(s($template), $htmlvalues));
            $message->smallmessage = $subject;
            $message->notification = 1;
            $message->contexturl = $link->out(false);
            $message->contexturlname = $assignname;
            $message->courseid = $course->id;

            if (message_send($message)) {
                $sent++;
            }
        }

        if ($sent > 0) {
            reminders_sent::create([
                'context' => $context,
                'userid' => $from->id,
                'other' => ['count' => $sent],
            ])->trigger();
        }

        return $sent;
    }
}
