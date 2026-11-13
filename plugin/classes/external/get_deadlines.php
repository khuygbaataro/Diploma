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
use local_darimaa\local\deadlines;
use local_darimaa\local\link_manager;

/**
 * Lists the activities a linked user still has to do.
 *
 * @package    local_darimaa
 * @copyright  2026 Khuygbaatar Ochirkhuyag
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class get_deadlines extends bot_function {
    /**
     * Describes the parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters(self::account_parameters() + [
            'days' => new external_value(PARAM_INT, 'How many days ahead to look', VALUE_DEFAULT, 7),
            'overduedays' => new external_value(PARAM_INT, 'How many days back to look for overdue work', VALUE_DEFAULT, 14),
        ]);
    }

    /**
     * Returns the open activities, overdue ones first, then by due date.
     *
     * @param string $channel The chat app.
     * @param string $externalid The account id in the chat app.
     * @param int $days How many days ahead to look.
     * @param int $overduedays How many days back to look for overdue work.
     * @return array
     */
    public static function execute(string $channel, string $externalid, int $days = 7, int $overduedays = 14): array {
        [
            'channel' => $channel,
            'externalid' => $externalid,
            'days' => $days,
            'overduedays' => $overduedays,
        ] = self::validate_parameters(self::execute_parameters(), [
            'channel' => $channel,
            'externalid' => $externalid,
            'days' => $days,
            'overduedays' => $overduedays,
        ]);
        self::require_bot($channel);

        $user = link_manager::get_user($channel, $externalid);
        if (!$user) {
            return ['linked' => false, 'items' => []];
        }

        $now = time();
        $days = max(1, min(60, $days));
        $overduedays = max(0, min(60, $overduedays));
        $rows = deadlines::get_for_user($user, $now - $overduedays * DAYSECS, $now + $days * DAYSECS, deadlines::MAX_ITEMS);

        $items = [];
        foreach ($rows as $row) {
            $items[] = (array) $row + ['overdue' => $row->timedue < $now];
        }
        return ['linked' => true, 'items' => $items];
    }

    /**
     * Describes the result.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'linked' => new external_value(PARAM_BOOL, 'Whether the chat account is linked to an active user'),
            'items' => new external_multiple_structure(new external_single_structure([
                'name' => new external_value(PARAM_TEXT, 'Activity name'),
                'coursename' => new external_value(PARAM_TEXT, 'Course name'),
                'modname' => new external_value(PARAM_PLUGIN, 'Activity type, for example assign'),
                'timedue' => new external_value(PARAM_INT, 'When it is due'),
                'url' => new external_value(PARAM_URL, 'Where to do it'),
                'actionname' => new external_value(PARAM_TEXT, 'What to do, for example "Add submission"'),
                'overdue' => new external_value(PARAM_BOOL, 'Whether the due time has passed'),
            ])),
        ]);
    }
}
