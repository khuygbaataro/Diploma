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
use local_darimaa\local\student_messages;

/**
 * Finds students of a linked teacher's courses by part of their name or student code.
 *
 * @package    local_darimaa
 * @copyright  2026 Khuygbaatar Ochirkhuyag
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class find_students extends bot_function {
    /**
     * Describes the parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters(self::account_parameters() + [
            'query' => new external_value(PARAM_TEXT, 'Part of a name or a student code, at least two characters'),
        ]);
    }

    /**
     * Returns at most ten matching students, with the course they were found in.
     *
     * @param string $channel The chat app.
     * @param string $externalid The account id in the chat app.
     * @param string $query Part of a name or a student code.
     * @return array
     */
    public static function execute(string $channel, string $externalid, string $query): array {
        [
            'channel' => $channel,
            'externalid' => $externalid,
            'query' => $query,
        ] = self::validate_parameters(self::execute_parameters(), [
            'channel' => $channel,
            'externalid' => $externalid,
            'query' => $query,
        ]);
        self::require_bot($channel);

        $teacher = link_manager::get_user($channel, $externalid);
        if (!$teacher) {
            return ['linked' => false, 'students' => []];
        }
        return ['linked' => true, 'students' => student_messages::find($teacher, $query)];
    }

    /**
     * Describes the result.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'linked' => new external_value(PARAM_BOOL, 'Whether the chat account is linked to an active user'),
            'students' => new external_multiple_structure(new external_single_structure([
                'userid' => new external_value(PARAM_INT, 'The student\'s user id, for sending a message'),
                'code' => new external_value(PARAM_TEXT, 'Student code, from the field chosen in the plugin settings'),
                'fullname' => new external_value(PARAM_TEXT, 'Full name'),
                'courseid' => new external_value(PARAM_INT, 'The course the student was found in'),
                'coursename' => new external_value(PARAM_TEXT, 'Course name'),
            ])),
        ]);
    }
}
