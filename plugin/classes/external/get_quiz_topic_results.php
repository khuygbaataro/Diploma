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
use local_darimaa\local\quiz_topics;

/**
 * Shows a linked teacher the results of one quiz by topic.
 *
 * @package    local_darimaa
 * @copyright  2026 Khuygbaatar Ochirkhuyag
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class get_quiz_topic_results extends bot_function {
    /**
     * Describes the parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters(self::account_parameters() + [
            'cmid' => new external_value(PARAM_INT, 'Course module id of the quiz'),
        ]);
    }

    /**
     * Returns the marks of each student and the group averages, by topic.
     *
     * @param string $channel The chat app.
     * @param string $externalid The account id in the chat app.
     * @param int $cmid Course module id of the quiz.
     * @return array
     */
    public static function execute(string $channel, string $externalid, int $cmid): array {
        [
            'channel' => $channel,
            'externalid' => $externalid,
            'cmid' => $cmid,
        ] = self::validate_parameters(self::execute_parameters(), [
            'channel' => $channel,
            'externalid' => $externalid,
            'cmid' => $cmid,
        ]);
        self::require_bot($channel);

        $teacher = link_manager::get_user($channel, $externalid);
        if (!$teacher) {
            return [
                'linked' => false,
                'quizname' => '',
                'coursename' => '',
                'topicsource' => '',
                'notattempted' => 0,
                'topics' => [],
                'students' => [],
            ];
        }

        // Every check is made for the linked user, not for the bot's own account.
        [$course, $cm] = get_course_and_cm_from_cmid($cmid, 'quiz', 0, $teacher->id);
        $context = $cm->context;
        if (!$cm->uservisible || !has_capability('mod/quiz:viewreports', $context, $teacher)) {
            throw new \core\exception\required_capability_exception($context, 'mod/quiz:viewreports', 'nopermissions', '');
        }
        $coursecontext = \core\context\course::instance($course->id);

        return [
            'linked' => true,
            'quizname' => format_string($cm->name, true, ['context' => $context, 'escape' => false]),
            'coursename' => format_string($course->fullname, true, ['context' => $coursecontext, 'escape' => false]),
        ] + quiz_topics::get_report($cm, $teacher);
    }

    /**
     * Describes the result.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'linked' => new external_value(PARAM_BOOL, 'Whether the chat account is linked to an active user'),
            'quizname' => new external_value(PARAM_TEXT, 'Quiz name'),
            'coursename' => new external_value(PARAM_TEXT, 'Course name'),
            'topicsource' => new external_value(
                PARAM_ALPHA,
                'What a topic is: "gradeitems" (quiz grade items) or "categories" (question categories)'
            ),
            'notattempted' => new external_value(PARAM_INT, 'Number of students without a finished attempt'),
            'topics' => new external_multiple_structure(new external_single_structure([
                'name' => new external_value(PARAM_TEXT, 'Topic name'),
                'maxmark' => new external_value(PARAM_FLOAT, 'Highest possible mark for the topic'),
                'average' => new external_value(PARAM_FLOAT, 'Average percentage of the students who attempted'),
            ])),
            'students' => new external_multiple_structure(new external_single_structure([
                'code' => new external_value(PARAM_TEXT, 'Student code, from the field chosen in the plugin settings'),
                'fullname' => new external_value(PARAM_TEXT, 'Full name'),
                'percent' => new external_value(PARAM_FLOAT, 'Percentage for the whole quiz, in the best finished attempt'),
                'ungraded' => new external_value(
                    PARAM_INT,
                    'Questions that wait for manual grading. They are left out of marks and maximums'
                ),
                'topics' => new external_multiple_structure(new external_single_structure([
                    'name' => new external_value(PARAM_TEXT, 'Topic name'),
                    'mark' => new external_value(PARAM_FLOAT, 'Mark of the student in the topic'),
                    'maxmark' => new external_value(PARAM_FLOAT, 'Highest possible mark of the graded questions'),
                    'percent' => new external_value(PARAM_FLOAT, 'Mark as a percentage'),
                ])),
            ])),
        ]);
    }
}
