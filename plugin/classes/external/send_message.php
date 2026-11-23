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

use core_external\external_function_parameters;
use core_external\external_single_structure;
use core_external\external_value;
use local_darimaa\local\link_manager;
use local_darimaa\local\student_messages;

/**
 * Sends a Moodle chat message from a linked teacher to a student of their course.
 *
 * The bot calls it only after the teacher has read the message and tapped "Yes, send".
 *
 * @package    local_darimaa
 * @copyright  2026 Khuygbaatar Ochirkhuyag
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class send_message extends bot_function {
    /**
     * Describes the parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters(self::account_parameters() + [
            'courseid' => new external_value(PARAM_INT, 'The course the student is in'),
            'userid' => new external_value(PARAM_INT, 'The student'),
            'message' => new external_value(PARAM_TEXT, 'The message, plain text'),
        ]);
    }

    /**
     * Sends the message in the linked teacher's name.
     *
     * @param string $channel The chat app.
     * @param string $externalid The account id in the chat app.
     * @param int $courseid The course the student is in.
     * @param int $userid The student.
     * @param string $message The message.
     * @return array
     */
    public static function execute(string $channel, string $externalid, int $courseid, int $userid, string $message): array {
        [
            'channel' => $channel,
            'externalid' => $externalid,
            'courseid' => $courseid,
            'userid' => $userid,
            'message' => $message,
        ] = self::validate_parameters(self::execute_parameters(), [
            'channel' => $channel,
            'externalid' => $externalid,
            'courseid' => $courseid,
            'userid' => $userid,
            'message' => $message,
        ]);
        self::require_bot($channel);

        $teacher = link_manager::get_user($channel, $externalid);
        if (!$teacher) {
            return ['linked' => false, 'sent' => false];
        }
        student_messages::send($teacher, get_course($courseid), $userid, $message);
        return ['linked' => true, 'sent' => true];
    }

    /**
     * Describes the result.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'linked' => new external_value(PARAM_BOOL, 'Whether the chat account is linked to an active user'),
            'sent' => new external_value(PARAM_BOOL, 'Whether the message was sent'),
        ]);
    }
}
