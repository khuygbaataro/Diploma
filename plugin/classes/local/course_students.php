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
 * Finds the students of a course and the groups a teacher may see.
 *
 * A student is a user with an active enrolment and a gradebook role, as in the grader report.
 * Every method works on the whole course with one query, never one query per student.
 *
 * @package    local_darimaa
 * @copyright  2026 Khuygbaatar Ochirkhuyag
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class course_students {
    /**
     * Returns the groups a teacher is limited to in a course.
     *
     * @param \stdClass $course The course.
     * @param \stdClass $teacher The teacher.
     * @return int[]|null Group ids, or null when the teacher may see all students.
     *                    An empty list means the teacher may see nobody.
     */
    public static function get_course_scope(\stdClass $course, \stdClass $teacher): ?array {
        $context = \core\context\course::instance($course->id);
        if (
            groups_get_course_groupmode($course) != SEPARATEGROUPS
            || has_capability('moodle/site:accessallgroups', $context, $teacher)
        ) {
            return null;
        }
        $groups = groups_get_all_groups($course->id, $teacher->id, $course->defaultgroupingid, 'g.id');
        return array_map('intval', array_keys($groups));
    }

    /**
     * Returns the groups a teacher is limited to in an activity.
     *
     * @param \cm_info $cm The activity.
     * @param \stdClass $teacher The teacher.
     * @return int[]|null Group ids, or null when the teacher may see all students.
     *                    An empty list means the teacher may see nobody.
     */
    public static function get_activity_scope(\cm_info $cm, \stdClass $teacher): ?array {
        if (
            groups_get_activity_groupmode($cm) != SEPARATEGROUPS
            || has_capability('moodle/site:accessallgroups', $cm->context, $teacher)
        ) {
            return null;
        }
        return array_map('intval', array_keys(groups_get_activity_allowed_groups($cm, $teacher->id)));
    }

    /**
     * Builds the SQL that selects the user ids of the students of a course.
     *
     * The result has one column, userid. Build it once for each place it is used in a query,
     * because its parameter names are unique.
     *
     * @param \core\context\course $context The course context.
     * @param int[]|null $groupids Only members of these groups, or null for all students.
     * @return array|null The SQL and its parameters, or null when there can be no students.
     */
    public static function get_sql(\core\context\course $context, ?array $groupids = null): ?array {
        global $CFG, $DB;

        $roleids = array_filter(array_map('intval', explode(',', (string) ($CFG->gradebookroles ?? ''))));
        if (!$roleids || $groupids === []) {
            return null;
        }

        [$enrolledsql, $enrolledparams] = get_enrolled_sql($context, '', $groupids ?? 0, true);
        [$rolesql, $roleparams] = $DB->get_in_or_equal($roleids, SQL_PARAMS_NAMED, 'stdrole');
        [$contextsql, $contextparams] = $DB->get_in_or_equal(
            $context->get_parent_context_ids(true),
            SQL_PARAMS_NAMED,
            'stdctx'
        );

        $sql = "SELECT DISTINCT stdra.userid
                  FROM {role_assignments} stdra
                  JOIN ($enrolledsql) stden ON stden.id = stdra.userid
                 WHERE stdra.roleid $rolesql AND stdra.contextid $contextsql";

        return [$sql, $enrolledparams + $roleparams + $contextparams];
    }

    /**
     * Returns the students of a course, sorted by name.
     *
     * @param \core\context\course $context The course context.
     * @param int[]|null $groupids Only members of these groups, or null for all students.
     * @return \stdClass[] User records with the name fields, keyed by user id.
     */
    public static function get_users(\core\context\course $context, ?array $groupids = null): array {
        global $DB;

        $students = self::get_sql($context, $groupids);
        if (!$students) {
            return [];
        }
        [$sql, $params] = $students;
        $namefields = \core_user\fields::for_name()->get_sql('u', false, '', '', false)->selects;

        return $DB->get_records_sql(
            "SELECT u.id, $namefields
               FROM {user} u
               JOIN ($sql) std ON std.userid = u.id
           ORDER BY u.lastname, u.firstname, u.id",
            $params
        );
    }

    /**
     * Counts the students of a course.
     *
     * @param \core\context\course $context The course context.
     * @param int[]|null $groupids Only members of these groups, or null for all students.
     * @return int
     */
    public static function count(\core\context\course $context, ?array $groupids = null): int {
        global $DB;

        $students = self::get_sql($context, $groupids);
        if (!$students) {
            return 0;
        }
        [$sql, $params] = $students;

        return (int) $DB->count_records_sql("SELECT COUNT(1) FROM ($sql) std", $params);
    }
}
