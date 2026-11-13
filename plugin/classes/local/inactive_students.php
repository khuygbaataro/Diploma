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
require_once($CFG->dirroot . '/mod/assign/locallib.php');

/**
 * Shows a teacher how active the students of a course are.
 *
 * For every student: the last access to the course, an estimate of the time spent in it and
 * the number of assignments that are past their due date and not submitted. Each of these
 * is read for the whole course at once.
 *
 * @package    local_darimaa
 * @copyright  2026 Khuygbaatar Ochirkhuyag
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class inactive_students {
    /**
     * Whether a teacher may see when students last accessed a course.
     *
     * Sites can hide the last access field. Then only users who may see hidden user fields get it.
     *
     * @param \core\context\course $context The course context.
     * @param \stdClass $teacher The teacher.
     * @return bool
     */
    public static function can_see_last_access(\core\context\course $context, \stdClass $teacher): bool {
        global $CFG;

        $hiddenfields = array_flip(explode(',', $CFG->hiddenuserfields ?? ''));
        return !isset($hiddenfields['lastaccess'])
            || has_capability('moodle/course:viewhiddenuserfields', $context, $teacher);
    }

    /**
     * Builds the report for the students a teacher may see.
     *
     * @param \stdClass $course The course.
     * @param \stdClass $teacher The teacher.
     * @param int $days A student with no course access for this many days is inactive.
     * @return array With lastaccessavailable, timeavailable and students. Each student has code, fullname,
     *               lastaccess, dayssince, minutes, missing and inactive. Inactive students come first.
     */
    public static function get_report(\stdClass $course, \stdClass $teacher, int $days): array {
        global $DB;

        $context = \core\context\course::instance($course->id);
        $now = time();

        $students = course_students::get_users($context, course_students::get_course_scope($course, $teacher));
        $userids = array_keys($students);

        $lastaccessavailable = self::can_see_last_access($context, $teacher);
        $lastaccess = [];
        if ($lastaccessavailable && $userids) {
            $lastaccess = $DB->get_records_menu('user_lastaccess', ['courseid' => $course->id], '', 'userid, timeaccess');
        }

        $timeavailable = time_estimate::is_available();
        $seconds = [];
        if ($timeavailable && $userids) {
            $seconds = time_estimate::get_seconds((int) $course->id, (int) $course->startdate);
        }

        $missing = $userids ? self::count_missing($course, $teacher, $userids, $now) : [];
        $codes = student_code::get_for_users($userids);
        $viewfullnames = has_capability('moodle/site:viewfullnames', $context, $teacher);

        $rows = [];
        foreach ($students as $userid => $student) {
            $last = $lastaccessavailable ? (int) ($lastaccess[$userid] ?? 0) : 0;
            $rows[] = [
                'code' => $codes[$userid],
                'fullname' => fullname($student, $viewfullnames),
                'lastaccess' => $last,
                'dayssince' => $last ? intdiv(max(0, $now - $last), DAYSECS) : -1,
                'minutes' => $timeavailable ? (int) round(($seconds[$userid] ?? 0) / MINSECS) : -1,
                'missing' => $missing[$userid],
                'inactive' => $lastaccessavailable && (!$last || $now - $last >= $days * DAYSECS),
            ];
        }

        // Inactive students first: those who never came, then the longest away. The rest stay in name order.
        usort($rows, static function (array $a, array $b): int {
            return [!$a['inactive'], $a['inactive'] ? $a['lastaccess'] : 0, $a['fullname']]
                <=> [!$b['inactive'], $b['inactive'] ? $b['lastaccess'] : 0, $b['fullname']];
        });

        return [
            'lastaccessavailable' => $lastaccessavailable,
            'timeavailable' => $timeavailable,
            'students' => $rows,
        ];
    }

    /**
     * Counts, for each student, the assignments that are past their due date and not submitted.
     *
     * Assignments with hidden student identities are left out, as for reminders. A student with
     * an extension or a user override that is still running is not counted for that assignment.
     *
     * @param \stdClass $course The course.
     * @param \stdClass $teacher The teacher, who only sees their own groups in separate groups mode.
     * @param int[] $userids The students.
     * @param int $now The current time.
     * @return int[] Number of assignments keyed by user id.
     */
    private static function count_missing(\stdClass $course, \stdClass $teacher, array $userids, int $now): array {
        $counts = array_fill_keys($userids, 0);

        foreach (get_fast_modinfo($course, $teacher->id)->get_instances_of('assign') as $cm) {
            if (!$cm->uservisible) {
                continue;
            }
            $assign = new \assign($cm->context, $cm, $course);
            $duedate = (int) $assign->get_instance()->duedate;
            if (!$duedate || $duedate > $now || !missing_submissions::is_supported($assign)) {
                continue;
            }

            $later = missing_submissions::get_users_with_later_duedate($assign, $now);
            foreach (array_keys(missing_submissions::get_missing_users_for($assign, $teacher)) as $userid) {
                if (isset($counts[$userid]) && !isset($later[$userid])) {
                    $counts[$userid]++;
                }
            }
        }
        return $counts;
    }
}
