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

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/question/engine/lib.php');

/**
 * Shows the results of a quiz by topic, for each student and for the whole group.
 *
 * A topic is a quiz grade item when the teacher set grade items up. Otherwise it is the
 * question category of the question the student was really asked, which matters for random
 * questions. For every student the finished attempt with the highest mark is used, counting
 * the questions that are already marked.
 *
 * The marks of all finished attempts are read with one call to the question engine, as the
 * quiz reports do, never one attempt at a time.
 *
 * @package    local_darimaa
 * @copyright  2026 Khuygbaatar Ochirkhuyag
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class quiz_topics {
    /** @var string Topics are the grade items of the quiz. */
    public const SOURCE_GRADEITEMS = 'gradeitems';

    /** @var string Topics are the categories of the questions that were asked. */
    public const SOURCE_CATEGORIES = 'categories';

    /** @var string The quiz attempt state that counts as finished. */
    private const FINISHED = 'finished';

    /** @var string The question state that waits for a teacher's mark. */
    private const NEEDS_GRADING = 'needsgrading';

    /**
     * Builds the report for the students a teacher may see.
     *
     * @param \cm_info $cm The quiz.
     * @param \stdClass $teacher The teacher, who only sees their own groups in separate groups mode.
     * @return array With topicsource, notattempted, topics (name, maxmark, average) and students
     *               (code, fullname, percent, ungraded, topics with name, mark, maxmark, percent).
     */
    public static function get_report(\cm_info $cm, \stdClass $teacher): array {
        global $DB;

        $context = $cm->context;
        $coursecontext = \core\context\course::instance($cm->course);
        $students = course_students::get_users($coursecontext, course_students::get_activity_scope($cm, $teacher));

        $slots = $DB->get_records('quiz_slots', ['quizid' => $cm->instance], 'slot', 'slot, maxmark, quizgradeitemid');
        $gradeitems = $DB->get_records('quiz_grade_items', ['quizid' => $cm->instance], 'sortorder, id', 'id, name');
        $usegradeitems = false;
        foreach ($slots as $slot) {
            if ($slot->quizgradeitemid && isset($gradeitems[$slot->quizgradeitemid])) {
                $usegradeitems = true;
                break;
            }
        }

        $attempts = self::get_finished_attempts((int) $cm->instance, array_keys($students));
        $steps = self::load_latest_steps((int) $cm->instance, array_keys($students), array_keys($slots));
        $usages = self::pick_best_attempts($attempts, $steps);
        // From here on only the best attempt of each student is looked at.
        $owners = array_flip($usages);
        $steps = array_filter($steps, static fn(\stdClass $step): bool => isset($owners[$step->questionusageid]));
        $categories = $usegradeitems ? [] : self::get_question_categories($steps);

        // Add up the marks of each student, for the whole quiz and for each topic.
        $results = [];
        foreach (array_keys($usages) as $userid) {
            $results[$userid] = ['mark' => 0.0, 'max' => 0.0, 'ungraded' => 0, 'topics' => []];
        }
        $names = [];
        foreach ($steps as $step) {
            $userid = $owners[$step->questionusageid] ?? null;
            $max = (float) $step->maxmark;
            if ($userid === null || $max <= 0) {
                continue;
            }

            $topic = null;
            if ($usegradeitems) {
                $itemid = $slots[$step->slot]->quizgradeitemid ?? null;
                if ($itemid && isset($gradeitems[$itemid])) {
                    $topic = (int) $itemid;
                    $names[$topic] = $gradeitems[$itemid]->name;
                }
            } else if (isset($categories[$step->questionid])) {
                $topic = (int) $categories[$step->questionid]->categoryid;
                $names[$topic] = $categories[$step->questionid]->name;
            }
            if ($topic !== null && !isset($results[$userid]['topics'][$topic])) {
                $results[$userid]['topics'][$topic] = ['mark' => 0.0, 'max' => 0.0, 'full' => 0.0];
            }
            if ($topic !== null) {
                $results[$userid]['topics'][$topic]['full'] += $max;
            }

            // A question that waits for manual grading is left out of marks and maximums.
            if ($step->state === self::NEEDS_GRADING) {
                $results[$userid]['ungraded']++;
                continue;
            }

            $mark = (float) $step->fraction * $max;
            $results[$userid]['mark'] += $mark;
            $results[$userid]['max'] += $max;
            if ($topic !== null) {
                $results[$userid]['topics'][$topic]['mark'] += $mark;
                $results[$userid]['topics'][$topic]['max'] += $max;
            }
        }

        // Grade items keep the order the teacher gave them. Categories are sorted by name.
        $names = array_map(
            static fn(string $name): string => format_string($name, true, ['context' => $context, 'escape' => false]),
            $names
        );
        if ($usegradeitems) {
            $order = array_values(array_intersect(array_keys($gradeitems), array_keys($names)));
        } else {
            asort($names);
            $order = array_keys($names);
        }

        $topics = [];
        foreach ($order as $topic) {
            $full = 0.0;
            $percents = [];
            foreach ($results as $result) {
                if (!isset($result['topics'][$topic])) {
                    continue;
                }
                $full = max($full, $result['topics'][$topic]['full']);
                if ($result['topics'][$topic]['max'] > 0) {
                    $percents[] = $result['topics'][$topic]['mark'] / $result['topics'][$topic]['max'] * 100;
                }
            }
            $topics[] = [
                'name' => $names[$topic],
                'maxmark' => round($full, 2),
                'average' => $percents ? round(array_sum($percents) / count($percents), 1) : 0.0,
            ];
        }

        $codes = student_code::get_for_users(array_keys($results));
        $viewfullnames = has_capability('moodle/site:viewfullnames', $context, $teacher);
        $rows = [];
        foreach ($students as $userid => $student) {
            if (!isset($results[$userid])) {
                continue;
            }
            $result = $results[$userid];
            $studenttopics = [];
            foreach ($order as $topic) {
                if (isset($result['topics'][$topic])) {
                    $studenttopics[] = [
                        'name' => $names[$topic],
                        'mark' => round($result['topics'][$topic]['mark'], 2),
                        'maxmark' => round($result['topics'][$topic]['max'], 2),
                        'percent' => self::percent($result['topics'][$topic]['mark'], $result['topics'][$topic]['max']),
                    ];
                }
            }
            $rows[] = [
                'code' => $codes[$userid],
                'fullname' => fullname($student, $viewfullnames),
                'percent' => self::percent($result['mark'], $result['max']),
                'ungraded' => $result['ungraded'],
                'topics' => $studenttopics,
            ];
        }
        // Students are listed in the order of the names that are shown.
        usort($rows, static fn(array $a, array $b): int => [$a['fullname'], $a['code']] <=> [$b['fullname'], $b['code']]);

        return [
            'topicsource' => $usegradeitems ? self::SOURCE_GRADEITEMS : self::SOURCE_CATEGORIES,
            'notattempted' => count($students) - count($results),
            'topics' => $topics,
            'students' => $rows,
        ];
    }

    /**
     * Builds the condition that selects the finished attempts of some students at a quiz.
     *
     * @param string $prefix Table alias with a dot, or an empty text.
     * @param int $quizid The quiz.
     * @param int[] $userids The students. Must not be empty.
     * @return array The SQL condition and its parameters.
     */
    private static function finished_attempts_where(string $prefix, int $quizid, array $userids): array {
        global $DB;

        [$insql, $params] = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED, 'attemptuser');
        $where = "{$prefix}quiz = :quizid AND {$prefix}preview = 0 AND {$prefix}state = :finished
                  AND {$prefix}userid $insql";

        return [$where, $params + ['quizid' => $quizid, 'finished' => self::FINISHED]];
    }

    /**
     * Lists the finished attempts of the students, each student's attempts in the order they were made.
     *
     * @param int $quizid The quiz.
     * @param int[] $userids The students.
     * @return int[] User ids keyed by the question usage id of the attempt.
     */
    private static function get_finished_attempts(int $quizid, array $userids): array {
        global $DB;

        if (!$userids) {
            return [];
        }
        [$where, $params] = self::finished_attempts_where('', $quizid, $userids);

        return $DB->get_records_select_menu('quiz_attempts', $where, $params, 'userid, attempt', 'uniqueid, userid');
    }

    /**
     * Loads the latest state and mark of every question in all finished attempts with one query.
     *
     * This is the bulk loader of the question engine that the quiz reports use.
     *
     * @param int $quizid The quiz.
     * @param int[] $userids The students.
     * @param int[] $slots The slot numbers of the quiz.
     * @return \stdClass[] Rows with questionusageid, slot, questionid, maxmark, state and fraction.
     */
    private static function load_latest_steps(int $quizid, array $userids, array $slots): array {
        if (!$userids || !$slots) {
            return [];
        }
        [$where, $params] = self::finished_attempts_where('quiza.', $quizid, $userids);
        $attempts = new \qubaid_join('{quiz_attempts} quiza', 'quiza.uniqueid', $where, $params);
        $fields = 'qas.id, qa.questionusageid, qa.slot, qa.questionid, qa.maxmark, qas.state, qas.fraction';
        $datamapper = new \question_engine_data_mapper();

        return $datamapper->load_questions_usages_latest_steps($attempts, $slots, $fields);
    }

    /**
     * Picks, for each student, the finished attempt with the highest mark.
     *
     * The marks of the questions are added up here, because Moodle leaves the total of an attempt
     * empty while one of its answers waits for manual grading. Such answers count as nothing yet.
     * When two attempts have the same mark, the earlier one is used.
     *
     * @param int[] $attempts User ids keyed by question usage id, from {@see self::get_finished_attempts()}.
     * @param \stdClass[] $steps Rows from {@see self::load_latest_steps()}.
     * @return int[] Question usage ids keyed by user id. Students without a finished attempt are not listed.
     */
    private static function pick_best_attempts(array $attempts, array $steps): array {
        $marks = array_fill_keys(array_keys($attempts), 0.0);
        foreach ($steps as $step) {
            if (isset($marks[$step->questionusageid]) && $step->state !== self::NEEDS_GRADING) {
                $marks[$step->questionusageid] += (float) $step->fraction * (float) $step->maxmark;
            }
        }

        $best = [];
        $usages = [];
        foreach ($attempts as $usageid => $userid) {
            if (!isset($usages[$userid]) || $marks[$usageid] > $best[$userid]) {
                $best[$userid] = $marks[$usageid];
                $usages[$userid] = (int) $usageid;
            }
        }
        return $usages;
    }

    /**
     * Finds the question category of every question that was asked.
     *
     * @param \stdClass[] $steps Rows from {@see self::load_latest_steps()}.
     * @return \stdClass[] Rows with categoryid and name, keyed by question id.
     */
    private static function get_question_categories(array $steps): array {
        global $DB;

        $questionids = array_unique(array_column($steps, 'questionid'));
        if (!$questionids) {
            return [];
        }
        [$insql, $params] = $DB->get_in_or_equal($questionids, SQL_PARAMS_NAMED, 'question');

        return $DB->get_records_sql(
            "SELECT qv.questionid, qc.id AS categoryid, qc.name
               FROM {question_versions} qv
               JOIN {question_bank_entries} qbe ON qbe.id = qv.questionbankentryid
               JOIN {question_categories} qc ON qc.id = qbe.questioncategoryid
              WHERE qv.questionid $insql",
            $params
        );
    }

    /**
     * Turns a mark into a percentage with one decimal.
     *
     * @param float $mark The mark.
     * @param float $max The highest possible mark.
     * @return float 0 when the maximum is 0.
     */
    private static function percent(float $mark, float $max): float {
        return $max > 0 ? round($mark / $max * 100, 1) : 0.0;
    }
}
