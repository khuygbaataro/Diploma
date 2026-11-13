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
use local_darimaa\local\missing_submissions;

/**
 * Lists recent assignments with missing submissions for a linked teacher.
 *
 * @package    local_darimaa
 * @copyright  2026 Khuygbaatar Ochirkhuyag
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class get_missing extends bot_function {
    /**
     * Describes the parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters(self::account_parameters() + [
            'pastdays' => new external_value(PARAM_INT, 'How many days back to look at due dates', VALUE_DEFAULT, 30),
            'days' => new external_value(PARAM_INT, 'How many days ahead to look at due dates', VALUE_DEFAULT, 7),
        ]);
    }

    /**
     * Returns the assignments with at least one missing submission, by due date.
     *
     * @param string $channel The chat app.
     * @param string $externalid The account id in the chat app.
     * @param int $pastdays How many days back to look at due dates.
     * @param int $days How many days ahead to look at due dates.
     * @return array
     */
    public static function execute(string $channel, string $externalid, int $pastdays = 30, int $days = 7): array {
        [
            'channel' => $channel,
            'externalid' => $externalid,
            'pastdays' => $pastdays,
            'days' => $days,
        ] = self::validate_parameters(self::execute_parameters(), [
            'channel' => $channel,
            'externalid' => $externalid,
            'pastdays' => $pastdays,
            'days' => $days,
        ]);
        self::require_bot($channel);

        $user = link_manager::get_user($channel, $externalid);
        if (!$user) {
            return ['linked' => false, 'assignments' => []];
        }

        $now = time();
        $pastdays = max(0, min(365, $pastdays));
        $days = max(0, min(60, $days));
        $rows = missing_submissions::get_teacher_overview($user, $now - $pastdays * DAYSECS, $now + $days * DAYSECS);

        return ['linked' => true, 'assignments' => array_map(static fn($row) => (array) $row, $rows)];
    }

    /**
     * Describes the result.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'linked' => new external_value(PARAM_BOOL, 'Whether the chat account is linked to an active user'),
            'assignments' => new external_multiple_structure(new external_single_structure([
                'cmid' => new external_value(PARAM_INT, 'Course module id of the assignment'),
                'name' => new external_value(PARAM_TEXT, 'Assignment name'),
                'coursename' => new external_value(PARAM_TEXT, 'Course name'),
                'duedate' => new external_value(PARAM_INT, 'Due date'),
                'missing' => new external_value(PARAM_INT, 'How many students have not submitted'),
            ])),
        ]);
    }
}
