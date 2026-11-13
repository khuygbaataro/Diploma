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
 * Estimates how long each user worked in a course, from the standard log.
 *
 * The log only records single actions, so the time is an estimate: the pause between two
 * actions of a user in the course counts when it is shorter than the session timeout.
 * A longer pause means the user left and came back, and it is not counted.
 *
 * The database does the whole calculation and returns one row per user, so the course log is
 * never loaded into memory.
 *
 * @package    local_darimaa
 * @copyright  2026 Khuygbaatar Ochirkhuyag
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class time_estimate {
    /** @var int Session timeout in minutes when the setting is empty. */
    public const DEFAULT_TIMEOUT = 30;

    /**
     * Whether the standard log store is turned on, so that there is something to read.
     *
     * @return bool
     */
    public static function is_available(): bool {
        $stores = explode(',', (string) get_config('tool_log', 'enabled_stores'));
        return in_array('logstore_standard', $stores, true);
    }

    /**
     * Returns the session timeout from the plugin settings.
     *
     * @return int Seconds.
     */
    public static function get_timeout(): int {
        $minutes = (int) get_config('local_darimaa', 'sessiontimeout');
        if ($minutes <= 0) {
            $minutes = self::DEFAULT_TIMEOUT;
        }
        return $minutes * MINSECS;
    }

    /**
     * Estimates the time every user spent in a course.
     *
     * Only a user's own actions count: anonymous actions and actions somebody did while
     * logged in as the user are left out.
     *
     * @param int $courseid The course.
     * @param int $since Only actions from this time on are read.
     * @param int|null $timeout Session timeout in seconds, or null to use the setting.
     * @return int[] Seconds keyed by user id. Users with no counted time are not listed.
     */
    public static function get_seconds(int $courseid, int $since, ?int $timeout = null): array {
        global $DB;

        $sql = "SELECT pauses.userid, SUM(pauses.pause) AS total
                  FROM (
                        SELECT l.userid,
                               l.timecreated - LAG(l.timecreated) OVER (
                                   PARTITION BY l.userid ORDER BY l.timecreated, l.id
                               ) AS pause
                          FROM {logstore_standard_log} l
                         WHERE l.courseid = :courseid
                               AND l.anonymous = 0
                               AND l.realuserid IS NULL
                               AND l.timecreated >= :since
                       ) pauses
                 WHERE pauses.pause < :timeout
              GROUP BY pauses.userid";
        $params = [
            'courseid' => $courseid,
            'since' => $since,
            'timeout' => $timeout ?? self::get_timeout(),
        ];

        $seconds = [];
        foreach ($DB->get_records_sql($sql, $params) as $row) {
            if ((int) $row->total > 0) {
                $seconds[(int) $row->userid] = (int) $row->total;
            }
        }
        return $seconds;
    }
}
