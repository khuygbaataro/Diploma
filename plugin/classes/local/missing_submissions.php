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
 * Finds students who have not submitted an assignment yet.
 *
 * @package    local_darimaa
 * @copyright  2026 Khuygbaatar Ochirkhuyag
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class missing_submissions {
    /**
     * Whether reminders can be used for this assignment.
     *
     * Reminders reveal who has not submitted, so they are switched off while
     * student identities are hidden (anonymous submissions).
     *
     * @param \assign $assign The assignment.
     * @return bool
     */
    public static function is_supported(\assign $assign): bool {
        $instance = $assign->get_instance();
        return empty($instance->blindmarking) || !empty($instance->revealidentities);
    }

    /**
     * Returns the group whose students the current user may see.
     *
     * @param \cm_info $cm The assignment course module.
     * @param bool $update Whether to read a newly chosen group from the page parameters.
     * @return int|null Group id, 0 for all participants, or null when the user cannot see any group.
     */
    public static function get_current_group(\cm_info $cm, bool $update = false): ?int {
        $groupid = groups_get_activity_group($cm, $update);
        $context = \core\context\module::instance($cm->id);

        if (
            empty($groupid)
            && groups_get_activity_groupmode($cm) == SEPARATEGROUPS
            && !has_capability('moodle/site:accessallgroups', $context)
        ) {
            return null;
        }
        return (int) $groupid;
    }

    /**
     * Returns the students in a group who are expected to submit but have not.
     *
     * Drafts and reopened submissions count as not submitted. In team
     * assignments each student is judged by their team's submission.
     *
     * @param \assign $assign The assignment.
     * @param int $groupid Group id, or 0 for all participants.
     * @return \stdClass[] User records keyed by user id, in the order of the participants list.
     */
    public static function get_missing_users(\assign $assign, int $groupid = 0): array {
        global $DB;

        $participants = $assign->list_participants($groupid, false);
        if (empty($participants)) {
            return [];
        }

        $missing = [];
        $instance = $assign->get_instance();

        if (!empty($instance->teamsubmission)) {
            foreach ($participants as $userid => $user) {
                $submission = $assign->get_group_submission($userid, 0, false);
                if (!$submission || $submission->status !== ASSIGN_SUBMISSION_STATUS_SUBMITTED) {
                    $missing[$userid] = $user;
                }
            }
            return $missing;
        }

        $submitted = $DB->get_fieldset_select(
            'assign_submission',
            'userid',
            'assignment = :assignment AND latest = 1 AND groupid = 0 AND status = :status',
            ['assignment' => $instance->id, 'status' => ASSIGN_SUBMISSION_STATUS_SUBMITTED]
        );
        $submitted = array_flip($submitted);

        foreach ($participants as $userid => $user) {
            if (!isset($submitted[$userid])) {
                $missing[$userid] = $user;
            }
        }
        return $missing;
    }

    /**
     * Returns the due date that applies to one student.
     *
     * Takes relative course dates, user and group overrides and extensions into account.
     *
     * @param \assign $assign The assignment.
     * @param int $userid The student.
     * @return int Timestamp, or 0 when there is no due date.
     */
    public static function get_user_duedate(\assign $assign, int $userid): int {
        $duedate = (int) $assign->get_instance($userid)->duedate;

        $override = $assign->override_exists($userid);
        if (!empty($override->duedate)) {
            $duedate = (int) $override->duedate;
        }

        $flags = $assign->get_user_flags($userid, false);
        if ($flags && !empty($flags->extensionduedate)) {
            $duedate = max($duedate, (int) $flags->extensionduedate);
        }
        return $duedate;
    }

    /**
     * Lists the assignments in a course with the number of missing submissions.
     *
     * Only assignments the current user can send reminders for are included.
     * Assignments are sorted by due date; those without one come last.
     *
     * @param \stdClass $course The course.
     * @return \stdClass[] Rows with cmid, name, duedate and missing (null when reminders are not available).
     */
    public static function get_course_summary(\stdClass $course): array {
        $rows = [];

        foreach (get_fast_modinfo($course)->get_instances_of('assign') as $cm) {
            if (!$cm->uservisible) {
                continue;
            }
            $context = \core\context\module::instance($cm->id);
            if (!has_capability('local/darimaa:sendreminders', $context)) {
                continue;
            }

            $assign = new \assign($context, $cm, $course);
            $missing = null;
            if (self::is_supported($assign)) {
                $groupid = self::get_current_group($cm);
                $missing = ($groupid === null) ? 0 : count(self::get_missing_users($assign, $groupid));
            }

            $rows[] = (object) [
                'cmid' => (int) $cm->id,
                'name' => $cm->get_formatted_name(),
                'duedate' => (int) $assign->get_instance()->duedate,
                'missing' => $missing,
            ];
        }

        usort($rows, static function (\stdClass $a, \stdClass $b): int {
            $adue = $a->duedate ?: PHP_INT_MAX;
            $bdue = $b->duedate ?: PHP_INT_MAX;
            return [$adue, $a->name] <=> [$bdue, $b->name];
        });

        return $rows;
    }

    /**
     * Returns the missing students a given teacher may see.
     *
     * Used when the teacher is not the current user, as with the chat bot. In separate
     * groups mode a teacher without access to all groups only sees their own groups.
     *
     * @param \assign $assign The assignment.
     * @param \stdClass $user The teacher.
     * @return \stdClass[] User records keyed by user id.
     */
    public static function get_missing_users_for(\assign $assign, \stdClass $user): array {
        $cm = $assign->get_course_module();

        if (
            groups_get_activity_groupmode($cm) != SEPARATEGROUPS
            || has_capability('moodle/site:accessallgroups', $assign->get_context(), $user)
        ) {
            return self::get_missing_users($assign, 0);
        }

        $missing = [];
        foreach (groups_get_activity_allowed_groups($cm, $user->id) as $group) {
            $missing += self::get_missing_users($assign, (int) $group->id);
        }
        return $missing;
    }

    /**
     * Lists a teacher's assignments, due between two times, that still have missing submissions.
     *
     * @param \stdClass $user The teacher.
     * @param int $from Earliest due date.
     * @param int $to Latest due date.
     * @return \stdClass[] Rows with cmid, name, coursename, duedate and missing, by due date.
     */
    public static function get_teacher_overview(\stdClass $user, int $from, int $to): array {
        $rows = [];

        foreach (enrol_get_users_courses($user->id, true, 'id') as $enrolled) {
            $coursecontext = \core\context\course::instance($enrolled->id);
            if (!has_capability('local/darimaa:sendreminders', $coursecontext, $user)) {
                continue;
            }
            $course = get_course($enrolled->id);
            $coursename = format_string($course->fullname, true, ['context' => $coursecontext, 'escape' => false]);

            foreach (get_fast_modinfo($course, $user->id)->get_instances_of('assign') as $cm) {
                $context = \core\context\module::instance($cm->id);
                if (!$cm->uservisible || !has_capability('local/darimaa:sendreminders', $context, $user)) {
                    continue;
                }

                $assign = new \assign($context, $cm, $course);
                $duedate = (int) $assign->get_instance()->duedate;
                if (!$duedate || $duedate < $from || $duedate > $to || !self::is_supported($assign)) {
                    continue;
                }

                $missing = count(self::get_missing_users_for($assign, $user));
                if ($missing === 0) {
                    continue;
                }

                $rows[] = (object) [
                    'cmid' => (int) $cm->id,
                    'name' => format_string($cm->name, true, ['context' => $context, 'escape' => false]),
                    'coursename' => $coursename,
                    'duedate' => $duedate,
                    'missing' => $missing,
                ];
            }
        }

        usort($rows, static fn(\stdClass $a, \stdClass $b): int => [$a->duedate, $a->name] <=> [$b->duedate, $b->name]);
        return $rows;
    }
}
