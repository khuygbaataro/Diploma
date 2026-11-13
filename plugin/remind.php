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
 * Shows who has not submitted one assignment and lets the teacher send reminders.
 *
 * @package    local_darimaa
 * @copyright  2026 Khuygbaatar Ochirkhuyag
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use core\output\html_writer;
use core\output\notification;
use local_darimaa\form\remind_form;
use local_darimaa\local\missing_submissions;
use local_darimaa\local\reminder_sender;

require(__DIR__ . '/../../config.php');
require_once($CFG->dirroot . '/mod/assign/locallib.php');

$cmid = required_param('cmid', PARAM_INT);
[$course, $cm] = get_course_and_cm_from_cmid($cmid, 'assign');

require_login($course, false, $cm);
$context = \core\context\module::instance($cm->id);
require_capability('local/darimaa:sendreminders', $context);

$url = new \core\url('/local/darimaa/remind.php', ['cmid' => $cm->id]);
$backurl = new \core\url('/local/darimaa/index.php', ['id' => $course->id]);

$assign = new assign($context, $cm, $course);
$assignname = format_string($assign->get_instance()->name, true, ['context' => $context]);

$PAGE->set_url($url);
$PAGE->set_pagelayout('incourse');
$PAGE->set_title(get_string('remindheading', 'local_darimaa', $assignname));
$PAGE->set_heading(format_string($course->fullname, true, ['context' => $context]));
$PAGE->activityheader->disable();

$supported = missing_submissions::is_supported($assign);
$groupid = missing_submissions::get_current_group($cm, true);
$missing = [];
if ($supported && $groupid !== null) {
    $missing = missing_submissions::get_missing_users($assign, $groupid);
}

// Each student's last access helps the teacher decide, unless the site hides that field.
$hiddenfields = array_flip(explode(',', $CFG->hiddenuserfields ?? ''));
$showlastaccess = !isset($hiddenfields['lastaccess']) || has_capability('moodle/course:viewhiddenuserfields', $context);
$lastaccess = [];
if ($showlastaccess && $missing) {
    $lastaccess = $DB->get_records_menu('user_lastaccess', ['courseid' => $course->id], '', 'userid, timeaccess');
}
$viewfullnames = has_capability('moodle/site:viewfullnames', $context);

$students = [];
foreach ($missing as $userid => $user) {
    $label = fullname($user, $viewfullnames);
    if ($showlastaccess) {
        $seen = get_string('never');
        if (!empty($lastaccess[$userid])) {
            $seen = userdate($lastaccess[$userid], get_string('strftimedatetimeshort', 'langconfig'));
        }
        $label = get_string('studentlastaccess', 'local_darimaa', ['name' => $label, 'lastaccess' => $seen]);
    }
    $students[$userid] = $label;
}

$message = get_config('local_darimaa', 'defaultmessage');
if (empty($message)) {
    $message = get_string('defaultmessage_text', 'local_darimaa');
}
$form = new remind_form($url, ['students' => $students, 'message' => $message]);

if ($form->is_cancelled()) {
    redirect($backurl);
} else if ($missing && ($data = $form->get_data())) {
    require_sesskey();

    // Only students who are still missing a submission can be reminded.
    $selected = array_keys(array_filter((array) ($data->users ?? [])));
    $recipients = array_intersect_key($missing, array_flip($selected));
    $sent = reminder_sender::send($assign, $recipients, $data->message, $USER);

    redirect($url, get_string('reminderssent', 'local_darimaa', $sent), null, notification::NOTIFY_SUCCESS);
}

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('missingsubmissions', 'local_darimaa'));
echo html_writer::div(html_writer::link($backurl, get_string('back', 'local_darimaa')), 'mb-3');

groups_print_activity_menu($cm, $url);

$duedate = (int) $assign->get_instance()->duedate;
$duestr = $duedate ? userdate($duedate) : get_string('noduedate', 'local_darimaa');
echo html_writer::div(get_string('duedateis', 'local_darimaa', $duestr), 'mb-3');

if (!$supported) {
    echo $OUTPUT->notification(get_string('unsupportedanonymous', 'local_darimaa'), notification::NOTIFY_INFO);
} else if ($groupid === null) {
    echo $OUTPUT->notification(get_string('nogroupaccess', 'local_darimaa'), notification::NOTIFY_INFO);
} else if (empty($missing)) {
    echo $OUTPUT->notification(get_string('nomissing', 'local_darimaa'), notification::NOTIFY_SUCCESS);
} else {
    $form->display();
}

echo $OUTPUT->footer();
