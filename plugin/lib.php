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

/**
 * Callbacks for the Teacher & Student Assistant.
 *
 * @package    local_darimaa
 * @copyright  2026 Khuygbaatar Ochirkhuyag
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Adds a "Missing submissions" link to the course navigation for teachers.
 *
 * @param navigation_node $navigation The course navigation node.
 * @param stdClass $course The course.
 * @param context $context The course context.
 */
function local_darimaa_extend_navigation_course($navigation, $course, $context): void {
    if (!has_capability('local/darimaa:sendreminders', $context)) {
        return;
    }

    $navigation->add(
        get_string('missingsubmissions', 'local_darimaa'),
        new \core\url('/local/darimaa/index.php', ['id' => $course->id]),
        navigation_node::TYPE_SETTING,
        null,
        'local_darimaa_missing',
        new \core\output\pix_icon('i/report', '')
    );
}

/**
 * Adds a "Messenger connection" link to the user's preferences page.
 *
 * @param navigation_node $navigation The user settings node.
 * @param stdClass $user The user whose settings are shown.
 * @param context_user $usercontext The user's context.
 * @param stdClass $course The current course.
 * @param context_course $coursecontext The course context.
 */
function local_darimaa_extend_navigation_user_settings(
    navigation_node $navigation,
    stdClass $user,
    $usercontext,
    stdClass $course,
    $coursecontext
): void {
    global $PAGE, $USER;

    if ((int) $user->id !== (int) $USER->id || isguestuser()) {
        return;
    }
    if (!$PAGE->has_set_url() || !$PAGE->url->compare(new \core\url('/user/preferences.php'), URL_MATCH_BASE)) {
        return;
    }

    $usernode = $navigation->find('useraccount', navigation_node::TYPE_CONTAINER);
    if ($usernode) {
        $usernode->add(
            get_string('linkheading', 'local_darimaa'),
            new \core\url('/local/darimaa/link.php'),
            navigation_node::TYPE_SETTING,
            null,
            'local_darimaa_link'
        );
    }
}
