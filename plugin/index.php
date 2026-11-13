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
 * Lists the assignments in a course with the number of missing submissions.
 *
 * @package    local_darimaa
 * @copyright  2026 Khuygbaatar Ochirkhuyag
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use core\output\html_writer;
use core\output\notification;
use local_darimaa\local\missing_submissions;

require(__DIR__ . '/../../config.php');

$courseid = required_param('id', PARAM_INT);
$course = get_course($courseid);

require_login($course);
$context = \core\context\course::instance($course->id);
require_capability('local/darimaa:sendreminders', $context);

$PAGE->set_url(new \core\url('/local/darimaa/index.php', ['id' => $course->id]));
$PAGE->set_pagelayout('incourse');
$PAGE->set_title(get_string('missingsubmissions', 'local_darimaa'));
$PAGE->set_heading(format_string($course->fullname, true, ['context' => $context]));

$rows = missing_submissions::get_course_summary($course);

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('missingsubmissions', 'local_darimaa'));

if (empty($rows)) {
    echo $OUTPUT->notification(get_string('noassignments', 'local_darimaa'), notification::NOTIFY_INFO);
} else {
    $table = new \core_table\output\html_table();
    $table->head = [
        get_string('modulename', 'mod_assign'),
        get_string('duedate', 'local_darimaa'),
        get_string('missingcount', 'local_darimaa'),
        '',
    ];

    foreach ($rows as $row) {
        $name = html_writer::link(new \core\url('/mod/assign/view.php', ['id' => $row->cmid]), $row->name);
        $due = $row->duedate ? userdate($row->duedate) : get_string('noduedate', 'local_darimaa');
        $action = '';

        if ($row->missing === null) {
            $count = get_string('anonymous', 'local_darimaa');
        } else {
            $count = $row->missing;
            if ($row->missing > 0) {
                $action = $OUTPUT->single_button(
                    new \core\url('/local/darimaa/remind.php', ['cmid' => $row->cmid]),
                    get_string('sendreminders', 'local_darimaa'),
                    'get'
                );
            }
        }
        $table->data[] = [$name, $due, $count, $action];
    }

    echo html_writer::table($table);
}

echo $OUTPUT->footer();
