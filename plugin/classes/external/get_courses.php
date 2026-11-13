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
use core_external\external_multiple_structure;
use core_external\external_single_structure;
use core_external\external_value;
use local_darimaa\local\link_manager;
use local_darimaa\local\teacher_courses;

/**
 * Lists the courses and quizzes of a linked teacher.
 *
 * @package    local_darimaa
 * @copyright  2026 Khuygbaatar Ochirkhuyag
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class get_courses extends bot_function {
    /**
     * Describes the parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters(self::account_parameters());
    }

    /**
     * Returns the courses where the linked user can send reminders, with the quizzes they can see reports of.
     *
     * @param string $channel The chat app.
     * @param string $externalid The account id in the chat app.
     * @return array
     */
    public static function execute(string $channel, string $externalid): array {
        [
            'channel' => $channel,
            'externalid' => $externalid,
        ] = self::validate_parameters(self::execute_parameters(), [
            'channel' => $channel,
            'externalid' => $externalid,
        ]);
        self::require_bot($channel);

        $user = link_manager::get_user($channel, $externalid);
        if (!$user) {
            return ['linked' => false, 'courses' => []];
        }

        return ['linked' => true, 'courses' => teacher_courses::get_overview($user)];
    }

    /**
     * Describes the result.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'linked' => new external_value(PARAM_BOOL, 'Whether the chat account is linked to an active user'),
            'courses' => new external_multiple_structure(new external_single_structure([
                'id' => new external_value(PARAM_INT, 'Course id'),
                'fullname' => new external_value(PARAM_TEXT, 'Full name of the course'),
                'shortname' => new external_value(PARAM_TEXT, 'Short name of the course'),
                'students' => new external_value(PARAM_INT, 'Number of students: active enrolments with a gradebook role'),
                'quizzes' => new external_multiple_structure(new external_single_structure([
                    'cmid' => new external_value(PARAM_INT, 'Course module id of the quiz'),
                    'name' => new external_value(PARAM_TEXT, 'Quiz name'),
                    'timeclose' => new external_value(PARAM_INT, 'When the quiz closes, 0 when it does not close'),
                    'finished' => new external_value(PARAM_INT, 'Number of students with a finished attempt'),
                ])),
            ])),
        ]);
    }
}
