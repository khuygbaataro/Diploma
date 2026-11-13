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
require_once($CFG->dirroot . '/calendar/lib.php');

/**
 * Collects what a student still has to do, from the Moodle calendar.
 *
 * Uses the same "action events" as the Timeline block, so an activity disappears
 * once the student has done it (for example, submitted the assignment).
 *
 * @package    local_darimaa
 * @copyright  2026 Khuygbaatar Ochirkhuyag
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class deadlines {
    /** @var int The most items one call returns, as the calendar allows. */
    public const MAX_ITEMS = 50;

    /**
     * Returns a user's open activities due between two times, soonest first.
     *
     * @param \stdClass $user The student.
     * @param int $from Earliest due time.
     * @param int $to Latest due time.
     * @param int $limit Most items to return, from 1 to 50.
     * @return \stdClass[] Rows with name, coursename, modname, timedue, url and actionname.
     */
    public static function get_for_user(\stdClass $user, int $from, int $to, int $limit = 20): array {
        $limit = max(1, min(self::MAX_ITEMS, $limit));
        $events = \core_calendar\local\api::get_action_events_by_timesort($from, $to, null, $limit, true, $user);

        $rows = [];
        foreach ($events as $event) {
            $name = $event->get_name();
            $modname = '';
            $url = null;

            $cm = $event->get_course_module();
            if ($cm) {
                $instance = $cm->get_proxied_instance();
                $modname = (string) $cm->get('modname');
                $name = format_string($instance->name, true, ['context' => $instance->context, 'escape' => false]);
                $url = $instance->url;
            }

            $coursename = '';
            $course = $event->get_course();
            if ($course && $course->get('id')) {
                $coursecontext = \core\context\course::instance($course->get('id'));
                $coursename = format_string($course->get('fullname'), true, ['context' => $coursecontext, 'escape' => false]);
            }

            $action = $event->get_action();
            if ($action && $action->get_url()) {
                $url = $action->get_url();
            }

            $rows[] = (object) [
                'name' => $name,
                'coursename' => $coursename,
                'modname' => $modname,
                'timedue' => $event->get_times()->get_sort_time()->getTimestamp(),
                'url' => $url ? $url->out(false) : '',
                'actionname' => $action ? (string) $action->get_name() : '',
            ];
        }
        return $rows;
    }
}
