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
use local_darimaa\local\at_risk;
use local_darimaa\local\link_manager;

/**
 * Shows a linked teacher the students of a course who need attention, with the reasons.
 *
 * The same list as the "Students who need attention" page, but only the students with at least
 * one warning sign, and without the estimated time, so that less personal data leaves Moodle.
 *
 * @package    local_darimaa
 * @copyright  2026 Khuygbaatar Ochirkhuyag
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class get_attention extends bot_function {
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
                'A student with no course access for this many days is away',
                VALUE_DEFAULT,
                at_risk::DEFAULT_DAYS
            ),
        ]);
    }

    /**
     * Returns the students with warning signs, those with the most signs first.
     *
     * @param string $channel The chat app.
     * @param string $externalid The account id in the chat app.
     * @param int $courseid The course.
     * @param int $days A student with no course access for this many days is away.
     * @return array
     */
    public static function execute(string $channel, string $externalid, int $courseid, int $days = at_risk::DEFAULT_DAYS): array {
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
                'quizzes' => 0,
                'total' => 0,
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

        $report = at_risk::get_report($course, $teacher, $days);
        $students = [];
        foreach ($report['students'] as $row) {
            if (!$row['signals']) {
                continue;
            }
            $students[] = [
                'code' => $row['code'],
                'fullname' => $row['fullname'],
                'dayssince' => $row['dayssince'],
                'missing' => $row['missing'],
                'notattempted' => $row['notattempted'],
                'quizaverage' => $row['quizaverage'] ?? -1.0,
                'weaktopics' => array_map(
                    static fn(array $topic): array => ['name' => $topic['name'], 'percent' => $topic['percent']],
                    $row['weaktopics']
                ),
                'signals' => $row['signals'],
            ];
        }

        return [
            'linked' => true,
            'coursename' => format_string($course->fullname, true, ['context' => $context, 'escape' => false]),
            'days' => $days,
            'lastaccessavailable' => $report['lastaccessavailable'],
            'quizzes' => $report['quizzes'],
            'total' => count($report['students']),
            'students' => $students,
        ];
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
            'days' => new external_value(PARAM_INT, 'The number of days used for "away"'),
            'lastaccessavailable' => new external_value(PARAM_BOOL, 'False when the site hides the last access from this user'),
            'quizzes' => new external_value(PARAM_INT, 'Number of quizzes looked at'),
            'total' => new external_value(PARAM_INT, 'Number of students looked at'),
            'students' => new external_multiple_structure(new external_single_structure([
                'code' => new external_value(PARAM_TEXT, 'Student code, from the field chosen in the plugin settings'),
                'fullname' => new external_value(PARAM_TEXT, 'Full name'),
                'dayssince' => new external_value(PARAM_INT, 'Whole days since the last access, -1 when never or hidden'),
                'missing' => new external_value(PARAM_INT, 'Assignments past their due date that the student has not submitted'),
                'notattempted' => new external_value(PARAM_INT, 'Closed quizzes the student did not attempt'),
                'quizaverage' => new external_value(PARAM_FLOAT, 'Average percentage in the quizzes, -1 when none'),
                'weaktopics' => new external_multiple_structure(new external_single_structure([
                    'name' => new external_value(PARAM_TEXT, 'Topic name'),
                    'percent' => new external_value(PARAM_FLOAT, 'Percentage in the topic'),
                ])),
                'signals' => new external_multiple_structure(
                    new external_value(PARAM_ALPHA, 'never, inactive, missing, notattempted, lowquiz or weaktopic')
                ),
            ])),
        ]);
    }
}
