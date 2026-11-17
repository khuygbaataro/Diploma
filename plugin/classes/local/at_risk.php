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
 * Finds the students of a course who may need a teacher's attention, and says why.
 *
 * A student gets a warning sign for each of these: never visited the course, no visit for a
 * number of days, an assignment past its due date and not submitted, a closed quiz not
 * attempted, a low average in the quizzes, or a weak topic in a quiz. The signs are hints for a
 * conversation, not a judgement; there is no hidden score. Everything is read from the activity
 * and quiz reports, so the same rules apply: only the students the teacher may see, and no
 * suspended accounts or accounts that cannot log in.
 *
 * @package    local_darimaa
 * @copyright  2026 Khuygbaatar Ochirkhuyag
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class at_risk {
    /** @var int A student with no visit for this many days is away, unless the teacher picks another number. */
    public const DEFAULT_DAYS = 7;

    /** @var float A quiz average or a topic below this percentage is a warning sign. */
    public const WEAK_BELOW = 60.0;

    /** @var string The student never visited the course. */
    public const SIGNAL_NEVER = 'never';

    /** @var string The student has not visited the course for the given number of days. */
    public const SIGNAL_INACTIVE = 'inactive';

    /** @var string The student has an assignment past its due date and not submitted. */
    public const SIGNAL_MISSING = 'missing';

    /** @var string The student did not attempt a quiz that has closed. */
    public const SIGNAL_NOTATTEMPTED = 'notattempted';

    /** @var string The student's average in the quizzes is low. */
    public const SIGNAL_LOWQUIZ = 'lowquiz';

    /** @var string The student is weak in at least one quiz topic. */
    public const SIGNAL_WEAKTOPIC = 'weaktopic';

    /**
     * Builds the report for the students a teacher may see.
     *
     * Only quizzes the teacher may see reports of, and that at least one of their students has
     * finished, are looked at. A quiz counts as skipped only once it has closed.
     *
     * @param \stdClass $course The course.
     * @param \stdClass $teacher The teacher.
     * @param int $days A student with no visit for this many days is away.
     * @return array With lastaccessavailable, timeavailable, quizzes (number of quizzes looked at) and students.
     *               Each student has userid, code, fullname, lastaccess, dayssince, minutes, missing, inactive,
     *               quizzes (attempted), notattempted (closed quizzes skipped), quizaverage (null when none),
     *               weaktopics (name, percent and quiz of each topic below WEAK_BELOW) and signals. Students
     *               with the most signs come first.
     */
    public static function get_report(\stdClass $course, \stdClass $teacher, int $days = self::DEFAULT_DAYS): array {
        global $DB;

        $activity = inactive_students::get_rows_by_user($course, $teacher, $days);
        $rows = [];
        $percents = [];
        foreach ($activity['students'] as $userid => $row) {
            $rows[$userid] = ['userid' => (int) $userid] + $row + [
                'quizzes' => 0,
                'notattempted' => 0,
                'quizaverage' => null,
                'weaktopics' => [],
                'signals' => [],
            ];
            $percents[$userid] = [];
        }

        $quizzes = self::get_reportable_quizzes($course, $teacher);
        $closetimes = [];
        if ($quizzes) {
            $closetimes = $DB->get_records_list('quiz', 'id', array_column($quizzes, 'instance'), '', 'id, timeclose');
        }
        $now = time();
        $looked = 0;

        foreach ($quizzes as $cm) {
            $quiz = quiz_topics::get_results_by_user($cm, $teacher);
            if (!$quiz['results']) {
                // Nobody has finished it yet, so it says nothing about anyone.
                continue;
            }
            $looked++;
            $timeclose = (int) ($closetimes[$cm->instance]->timeclose ?? 0);
            $closed = $timeclose > 0 && $timeclose < $now;
            $quizname = $cm->get_formatted_name();

            foreach ($quiz['students'] as $userid) {
                if (!isset($rows[$userid])) {
                    continue;
                }
                $result = $quiz['results'][$userid] ?? null;
                if ($result === null) {
                    if ($closed) {
                        $rows[$userid]['notattempted']++;
                    }
                    continue;
                }
                $rows[$userid]['quizzes']++;
                if ($result['graded']) {
                    $percents[$userid][] = $result['percent'];
                }
                foreach ($result['topics'] as $name => $percent) {
                    if ($percent < self::WEAK_BELOW) {
                        $rows[$userid]['weaktopics'][] = ['name' => $name, 'percent' => $percent, 'quiz' => $quizname];
                    }
                }
            }
        }

        foreach (array_keys($rows) as $userid) {
            if ($percents[$userid]) {
                $rows[$userid]['quizaverage'] = round(array_sum($percents[$userid]) / count($percents[$userid]), 1);
            }
            $rows[$userid]['signals'] = self::signals($rows[$userid], $activity['lastaccessavailable']);
        }

        // The most signs first; then those away longest, with those who never came before everyone; then by name.
        $away = static fn(array $row): int => $row['lastaccess'] ? $row['dayssince'] : PHP_INT_MAX;
        $rows = array_values($rows);
        usort($rows, static function (array $a, array $b) use ($away): int {
            return [-count($a['signals']), -$away($a), $a['fullname']]
                <=> [-count($b['signals']), -$away($b), $b['fullname']];
        });

        return [
            'lastaccessavailable' => $activity['lastaccessavailable'],
            'timeavailable' => $activity['timeavailable'],
            'quizzes' => $looked,
            'students' => $rows,
        ];
    }

    /**
     * Lists the warning signs of one student.
     *
     * @param array $row The student's row.
     * @param bool $lastaccessavailable Whether the teacher may see the last access.
     * @return string[] SIGNAL_ constants, in a fixed order.
     */
    private static function signals(array $row, bool $lastaccessavailable): array {
        $signals = [];
        if ($lastaccessavailable && !$row['lastaccess']) {
            $signals[] = self::SIGNAL_NEVER;
        } else if ($row['inactive']) {
            $signals[] = self::SIGNAL_INACTIVE;
        }
        if ($row['missing'] > 0) {
            $signals[] = self::SIGNAL_MISSING;
        }
        if ($row['notattempted'] > 0) {
            $signals[] = self::SIGNAL_NOTATTEMPTED;
        }
        if ($row['quizaverage'] !== null && $row['quizaverage'] < self::WEAK_BELOW) {
            $signals[] = self::SIGNAL_LOWQUIZ;
        }
        if ($row['weaktopics']) {
            $signals[] = self::SIGNAL_WEAKTOPIC;
        }
        return $signals;
    }

    /**
     * Returns the quizzes of a course that a teacher may see reports of.
     *
     * @param \stdClass $course The course.
     * @param \stdClass $teacher The teacher.
     * @return \cm_info[] The quizzes, in course order.
     */
    private static function get_reportable_quizzes(\stdClass $course, \stdClass $teacher): array {
        $quizzes = [];
        foreach (get_fast_modinfo($course, $teacher->id)->get_instances_of('quiz') as $cm) {
            if ($cm->uservisible && has_capability('mod/quiz:viewreports', $cm->context, $teacher)) {
                $quizzes[] = $cm;
            }
        }
        return $quizzes;
    }
}
