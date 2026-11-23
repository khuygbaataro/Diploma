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
    /** @var string A Moodle notification: the bell, and email when the student's settings allow it. */
    public const CHANNEL_NOTIFICATION = 'notification';

    /** @var string A Moodle chat message from the teacher, which the student can answer. */
    public const CHANNEL_CHAT = 'chat';

    /** @var string Both a Moodle notification and a Moodle chat message. */
    public const CHANNEL_BOTH = 'both';

    /** @var string Setting value: the teacher chooses the way on the reminder page. */
    public const CHANNEL_TEACHER = 'teacher';

    /** @var string Moodle took the notification and every delivery channel sent it. */
    public const DELIVERED = 'delivered';

    /** @var string Moodle saved the notification, so it shows in Moodle, but a channel such as email failed. */
    public const PARTLY_DELIVERED = 'partly';

    /** @var string Moodle did not take the notification at all. */
    public const NOT_DELIVERED = 'none';
    /**
     * Sends one reminder to each student and logs the action.
     *
     * The template can contain {firstname}, {lastname}, {assignment}, {course},
     * {duedate} and {link}. Dates are shown in each student's time zone.
     * Accounts that cannot log in (suspended, or of the type "nologin") are skipped.
     *
     * @param \assign $assign The assignment.
     * @param \stdClass[] $users Full user records of the students to remind.
     * @param string $template The message text written by the teacher.
     * @param \stdClass $from The user who sends the reminders.
     * @return int Number of students who got the reminder, at least as a Moodle notification.
     */
    public static function send(\assign $assign, array $users, string $template, \stdClass $from): int {
        return self::send_with_report($assign, $users, $template, $from)['sent'];
    }

    /**
     * Sends one reminder to each student, logs the action and says what reached the students.
     *
     * The reminder goes as a Moodle notification, as a Moodle chat message from the sender, or both.
     *
     * Moodle first saves a notification, which the student then sees in Moodle, and then passes
     * it to each delivery channel, such as email. When one channel fails, Moodle reports the whole
     * send as failed, although the notification is saved. Such a reminder counts as sent here, and
     * also as incomplete, so that nobody sends it again thinking it was lost.
     *
     * A chat message only goes where Moodle allows the sender to message the student. Otherwise the
     * student is counted in chatrefused.
     *
     * @param \assign $assign The assignment.
     * @param \stdClass[] $users Full user records of the students to remind.
     * @param string $template The message text written by the teacher.
     * @param \stdClass $from The user who sends the reminders.
     * @param string $channel One of the CHANNEL_ constants.
     * @return int[] With sent (students who got the reminder in at least one way), incomplete (students
     *               whose notification was saved but not sent by every channel, such as email), chat
     *               (chat messages sent) and chatrefused (students who could not get a chat message).
     */
    public static function send_with_report(
        \assign $assign,
        array $users,
        string $template,
        \stdClass $from,
        string $channel = self::CHANNEL_NOTIFICATION
    ): array {
        global $CFG;

        if (!in_array($channel, [self::CHANNEL_NOTIFICATION, self::CHANNEL_CHAT, self::CHANNEL_BOTH], true)) {
            throw new \core\exception\coding_exception('Unknown reminder channel: ' . $channel);
        }
        $cm = $assign->get_course_module();
        $course = $assign->get_course();
        $context = $assign->get_context();
        $coursecontext = \core\context\course::instance($course->id);

        $link = new \core\url('/mod/assign/view.php', ['id' => $cm->id]);
        $assignname = format_string($assign->get_instance()->name, true, ['context' => $context, 'escape' => false]);
        $coursename = format_string($course->fullname, true, ['context' => $coursecontext, 'escape' => false]);
        $strings = get_string_manager();

        $sent = 0;
        $incomplete = 0;
        $chat = 0;
        $chatrefused = 0;
        foreach ($users as $user) {
            // A suspended account, or one that can never log in, gets no reminder.
            if (!course_students::can_log_in($user)) {
                continue;
            }
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

            $reached = false;
            if ($channel !== self::CHANNEL_CHAT) {
                $status = self::delivery_status(message_send($message), $message);
                $reached = $status !== self::NOT_DELIVERED;
                if ($status === self::PARTLY_DELIVERED) {
                    $incomplete++;
                }
            }
            if ($channel !== self::CHANNEL_NOTIFICATION) {
                if (!empty($CFG->messaging) && self::send_chat($from, $user, $message->fullmessagehtml)) {
                    $chat++;
                    $reached = true;
                } else {
                    $chatrefused++;
                }
            }
            if ($reached) {
                $sent++;
            }
        }

        if ($sent > 0) {
            reminders_sent::create([
                'context' => $context,
                'userid' => $from->id,
                'other' => ['count' => $sent, 'channel' => $channel],
            ])->trigger();
        }

        return ['sent' => $sent, 'incomplete' => $incomplete, 'chat' => $chat, 'chatrefused' => $chatrefused];
    }

    /**
     * Returns the site setting for how reminders are sent.
     *
     * @return string CHANNEL_TEACHER, or one of the ways a reminder can go.
     */
    public static function get_channel_setting(): string {
        $value = (string) get_config('local_darimaa', 'reminderchannel');
        $known = [self::CHANNEL_TEACHER, self::CHANNEL_NOTIFICATION, self::CHANNEL_CHAT, self::CHANNEL_BOTH];
        return in_array($value, $known, true) ? $value : self::CHANNEL_TEACHER;
    }

    /**
     * Whether a user may send reminders as Moodle chat messages.
     *
     * Moodle's messaging must be on for the site, and the user must be allowed to send messages.
     *
     * @param \stdClass $user The sender.
     * @return bool
     */
    public static function can_chat(\stdClass $user): bool {
        global $CFG;

        return !empty($CFG->messaging)
            && has_capability('moodle/site:sendmessage', \core\context\system::instance(), $user);
    }

    /**
     * Decides how a reminder goes, from the site setting and what the teacher chose.
     *
     * When the setting leaves the choice to the teacher, their choice counts, and a notification
     * when they chose nothing. Otherwise the setting decides. When chat is not possible for the
     * sender, the reminder goes as a notification, so that it still reaches the students.
     *
     * @param string|null $chosen What the teacher chose on the reminder page, if anything.
     * @param bool $canchat Whether the sender may send chat messages.
     * @return string CHANNEL_NOTIFICATION, CHANNEL_CHAT or CHANNEL_BOTH.
     */
    public static function resolve_channel(?string $chosen, bool $canchat): string {
        $ways = [self::CHANNEL_NOTIFICATION, self::CHANNEL_CHAT, self::CHANNEL_BOTH];
        $setting = self::get_channel_setting();
        if ($setting === self::CHANNEL_TEACHER) {
            $channel = in_array($chosen, $ways, true) ? $chosen : self::CHANNEL_NOTIFICATION;
        } else {
            $channel = $setting;
        }
        return $canchat ? $channel : self::CHANNEL_NOTIFICATION;
    }

    /**
     * Says what happened to a notification, from what message_send() returned.
     *
     * message_send() returns false when one delivery channel fails, even after Moodle saved the
     * notification. The saved id tells the two cases apart.
     *
     * @param mixed $result What message_send() returned: a message id, or false.
     * @param \core\message\message $message The message that was sent.
     * @return string One of DELIVERED, PARTLY_DELIVERED and NOT_DELIVERED.
     */
    public static function delivery_status($result, \core\message\message $message): string {
        if ($result) {
            return self::DELIVERED;
        }
        return empty($message->savedmessageid) ? self::NOT_DELIVERED : self::PARTLY_DELIVERED;
    }

    /**
     * Sends a Moodle chat message from one user to another: a reminder, or a teacher's message from the bot.
     *
     * Moodle decides whether the sender may message the student: the sender needs the capability to
     * send messages, and the student's privacy settings and blocked users are respected.
     *
     * @param \stdClass $from The sender.
     * @param \stdClass $to The student.
     * @param string $html The message, as HTML.
     * @return bool Whether the message was sent.
     */
    public static function send_chat(\stdClass $from, \stdClass $to, string $html): bool {
        if (!\core_message\api::can_send_message((int) $to->id, (int) $from->id)) {
            return false;
        }
        $userids = [(int) $from->id, (int) $to->id];
        $conversationid = \core_message\api::get_conversation_between_users($userids);
        if (!$conversationid) {
            $conversationid = \core_message\api::create_conversation(
                \core_message\api::MESSAGE_CONVERSATION_TYPE_INDIVIDUAL,
                $userids
            )->id;
        }
        try {
            \core_message\api::send_message_to_conversation((int) $from->id, (int) $conversationid, $html, FORMAT_HTML);
        } catch (\moodle_exception $exception) {
            return false;
        }
        return true;
    }
}
