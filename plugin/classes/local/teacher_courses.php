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

/**
 * Lists the courses a teacher works in, with their quizzes.
 *
 * @package    local_darimaa
 * @copyright  2026 Khuygbaatar Ochirkhuyag
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class teacher_courses {
    /** @var string The quiz attempt state that counts as finished. */
    private const FINISHED = 'finished';

    /**
     * Returns the courses where a user can send reminders, by name.
     *
     * @param \stdClass $teacher The teacher.
     * @return array[] Rows with id, fullname, shortname, students and quizzes.
     */
    public static function get_overview(\stdClass $teacher): array {
        $rows = [];

        foreach (enrol_get_users_courses($teacher->id, true, 'id') as $enrolled) {
            $context = \core\context\course::instance($enrolled->id);
            if (!has_capability('local/darimaa:sendreminders', $context, $teacher)) {
                continue;
            }
            $course = get_course($enrolled->id);

            $rows[] = [
                'id' => (int) $course->id,
                'fullname' => format_string($course->fullname, true, ['context' => $context, 'escape' => false]),
                'shortname' => format_string($course->shortname, true, ['context' => $context, 'escape' => false]),
                'students' => course_students::count($context),
                'quizzes' => self::get_quizzes($course, $context, $teacher),
            ];
        }

        usort($rows, static fn(array $a, array $b): int => [$a['fullname'], $a['id']] <=> [$b['fullname'], $b['id']]);
        return $rows;
    }

    /**
     * Returns the quizzes of a course that a teacher can see reports of.
     *
     * @param \stdClass $course The course.
     * @param \core\context\course $context The course context.
     * @param \stdClass $teacher The teacher.
     * @return array[] Rows with cmid, name, timeclose and finished, in course order.
     */
    private static function get_quizzes(\stdClass $course, \core\context\course $context, \stdClass $teacher): array {
        global $DB;

        $quizzes = [];
        foreach (get_fast_modinfo($course, $teacher->id)->get_instances_of('quiz') as $cm) {
            if (!$cm->uservisible || !has_capability('mod/quiz:viewreports', $cm->context, $teacher)) {
                continue;
            }
            $quizzes[(int) $cm->instance] = [
                'cmid' => (int) $cm->id,
                'name' => format_string($cm->name, true, ['context' => $cm->context, 'escape' => false]),
                'timeclose' => 0,
                'finished' => 0,
            ];
        }
        if (!$quizzes) {
            return [];
        }

        [$insql, $params] = $DB->get_in_or_equal(array_keys($quizzes), SQL_PARAMS_NAMED, 'quiz');
        $closetimes = $DB->get_records_select_menu('quiz', "id $insql", $params, '', 'id, timeclose');
        foreach ($closetimes as $quizid => $timeclose) {
            $quizzes[$quizid]['timeclose'] = (int) $timeclose;
        }

        // One query counts, for every quiz of the course, the students with a finished attempt.
        $students = course_students::get_sql($context);
        if ($students) {
            [$studentsql, $studentparams] = $students;
            [$insql, $params] = $DB->get_in_or_equal(array_keys($quizzes), SQL_PARAMS_NAMED, 'quiz');
            $counts = $DB->get_records_sql_menu(
                "SELECT qa.quiz, COUNT(DISTINCT qa.userid) AS finishedcount
                   FROM {quiz_attempts} qa
                   JOIN ($studentsql) std ON std.userid = qa.userid
                  WHERE qa.quiz $insql AND qa.preview = 0 AND qa.state = :finished
               GROUP BY qa.quiz",
                $studentparams + $params + ['finished' => self::FINISHED]
            );
            foreach ($counts as $quizid => $finished) {
                $quizzes[$quizid]['finished'] = (int) $finished;
            }
        }

        return array_values($quizzes);
    }
}
