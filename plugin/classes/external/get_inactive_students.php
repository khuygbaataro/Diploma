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
use local_darimaa\local\inactive_students;
use local_darimaa\local\link_manager;

/**
 * Shows a linked teacher how active the students of one course are.
 *
 * @package    local_darimaa
 * @copyright  2026 Khuygbaatar Ochirkhuyag
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class get_inactive_students extends bot_function {
    /** @var string[] What the linked user must be allowed to do in the course. */
    private const CAPABILITIES = ['moodle/course:viewparticipants', 'local/darimaa:sendreminders'];

    /**
     * Describes the parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters(self::account_parameters() + [
            'courseid' => new external_value(PARAM_INT, 'The course'),
            'days' => new external_value(
                PARAM_INT,
                'A student with no course access for this many days is inactive',
                VALUE_DEFAULT,
                7
            ),
        ]);
    }

    /**
     * Returns the students of a course with their last access, time spent and missing assignments.
     *
     * @param string $channel The chat app.
     * @param string $externalid The account id in the chat app.
     * @param int $courseid The course.
     * @param int $days A student with no course access for this many days is inactive.
     * @return array
     */
    public static function execute(string $channel, string $externalid, int $courseid, int $days = 7): array {
        [
            'channel' => $channel,
            'externalid' => $externalid,
            'courseid' => $courseid,
            'days' => $days,
        ] = self::validate_parameters(self::execute_parameters(), [
            'channel' => $channel,
            'externalid' => $externalid,
            'courseid' => $courseid,
            'days' => $days,
        ]);
        self::require_bot($channel);
        $days = max(1, min(365, $days));

        $teacher = link_manager::get_user($channel, $externalid);
        if (!$teacher) {
            return [
                'linked' => false,
                'coursename' => '',
                'days' => $days,
                'lastaccessavailable' => false,
                'timeavailable' => false,
                'students' => [],
            ];
        }

        // Every check is made for the linked user, not for the bot's own account.
        $course = get_course($courseid);
        $context = \core\context\course::instance($course->id);
        foreach (self::CAPABILITIES as $capability) {
            if (!has_capability($capability, $context, $teacher)) {
                throw new \core\exception\required_capability_exception($context, $capability, 'nopermissions', '');
            }
        }

        return [
            'linked' => true,
            'coursename' => format_string($course->fullname, true, ['context' => $context, 'escape' => false]),
            'days' => $days,
        ] + inactive_students::get_report($course, $teacher, $days);
    }

    /**
     * Describes the result.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'linked' => new external_value(PARAM_BOOL, 'Whether the chat account is linked to an active user'),
            'coursename' => new external_value(PARAM_TEXT, 'Course name'),
            'days' => new external_value(PARAM_INT, 'The number of days used for "inactive"'),
            'lastaccessavailable' => new external_value(
                PARAM_BOOL,
                'False when the site hides the last access from this user: lastaccess is then 0 and nobody is inactive'
            ),
            'timeavailable' => new external_value(
                PARAM_BOOL,
                'False when the standard log store is off: minutes is then -1'
            ),
            'students' => new external_multiple_structure(new external_single_structure([
                'code' => new external_value(PARAM_TEXT, 'Student code, from the field chosen in the plugin settings'),
                'fullname' => new external_value(PARAM_TEXT, 'Full name'),
                'lastaccess' => new external_value(PARAM_INT, 'Last access to the course, 0 when never'),
                'dayssince' => new external_value(PARAM_INT, 'Whole days since the last access, -1 when never'),
                'minutes' => new external_value(PARAM_INT, 'Estimated minutes in the course since its start date, -1 when unknown'),
                'missing' => new external_value(PARAM_INT, 'Assignments past their due date that the student has not submitted'),
                'inactive' => new external_value(PARAM_BOOL, 'No course access for the given number of days, or never'),
            ])),
        ]);
    }
}
