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
 * Lists the students of a course who may need the teacher's attention, with the reasons.
 *
 * @package    local_darimaa
 * @copyright  2026 Khuygbaatar Ochirkhuyag
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use core\output\html_writer;
use core\output\notification;
use local_darimaa\local\at_risk;

require(__DIR__ . '/../../config.php');

$courseid = required_param('id', PARAM_INT);
$days = max(1, min(365, optional_param('days', at_risk::DEFAULT_DAYS, PARAM_INT)));
$showall = optional_param('all', 0, PARAM_BOOL);
$course = get_course($courseid);

require_login($course);
$context = \core\context\course::instance($course->id);
require_capability('local/darimaa:sendreminders', $context);
require_capability('moodle/course:viewparticipants', $context);

$url = new \core\url('/local/darimaa/atrisk.php', ['id' => $course->id, 'days' => $days, 'all' => (int) $showall]);
$PAGE->set_url($url);
$PAGE->set_pagelayout('incourse');
$PAGE->set_title(get_string('atrisk', 'local_darimaa'));
$PAGE->set_heading(format_string($course->fullname, true, ['context' => $context]));

$report = at_risk::get_report($course, $USER, $days);

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('atrisk', 'local_darimaa'));
echo html_writer::tag('p', get_string('atrisk_intro', 'local_darimaa', [
    'days' => $days,
    'percent' => format_float(at_risk::WEAK_BELOW, 0),
]));

// How many days without a visit count as away, and whether to list everybody.
$choices = [];
foreach ([7, 14, 30] as $choice) {
    $label = get_string('atrisk_daysoption', 'local_darimaa', $choice);
    if ($choice === $days) {
        $choices[] = html_writer::tag('strong', $label);
    } else {
        $choices[] = html_writer::link(new \core\url($url, ['days' => $choice]), $label);
    }
}
echo html_writer::div(get_string('atrisk_days', 'local_darimaa') . ': ' . implode(' · ', $choices), 'mb-2');
$toggle = new \core\url($url, ['all' => $showall ? 0 : 1]);
$togglelabel = get_string($showall ? 'atrisk_showflagged' : 'atrisk_showall', 'local_darimaa');
echo html_writer::div(html_writer::link($toggle, $togglelabel), 'mb-3');

if (!$report['lastaccessavailable']) {
    echo $OUTPUT->notification(get_string('atrisk_nolastaccess', 'local_darimaa'), notification::NOTIFY_INFO);
}

$rows = $showall ? $report['students'] : array_filter($report['students'], static fn(array $row): bool => !empty($row['signals']));
$dash = '-';

if (!$rows) {
    echo $OUTPUT->notification(get_string('atrisk_none', 'local_darimaa'), notification::NOTIFY_SUCCESS);
} else {
    $table = new \core_table\output\html_table();
    $table->head = [
        get_string('atrisk_student', 'local_darimaa'),
        get_string('atrisk_lastaccess', 'local_darimaa'),
        get_string('atrisk_time', 'local_darimaa'),
        get_string('atrisk_missing', 'local_darimaa'),
        get_string('atrisk_notattempted', 'local_darimaa'),
        get_string('atrisk_quizaverage', 'local_darimaa'),
        get_string('atrisk_weaktopics', 'local_darimaa'),
        get_string('atrisk_signals', 'local_darimaa'),
    ];

    foreach ($rows as $row) {
        $name = trim($row['code'] . ' ' . $row['fullname']);
        $student = html_writer::link(new \core\url('/user/view.php', ['id' => $row['userid'], 'course' => $course->id]), s($name));

        if (!$report['lastaccessavailable']) {
            $lastaccess = $dash;
        } else if (!$row['lastaccess']) {
            $lastaccess = get_string('never');
        } else {
            $lastaccess = userdate($row['lastaccess'], get_string('strftimedatetimeshort', 'langconfig')) .
                ' (' . get_string('atrisk_daysago', 'local_darimaa', $row['dayssince']) . ')';
        }

        $time = $row['minutes'] < 0 ? $dash : get_string('atrisk_hours', 'local_darimaa', format_float($row['minutes'] / 60, 1));
        $average = $row['quizaverage'] === null ? $dash : format_float($row['quizaverage'], 1) . '%';

        $topics = array_map(
            static fn(array $topic): string => s($topic['name']) . ' ' . format_float($topic['percent'], 0) . '%',
            $row['weaktopics']
        );

        $badges = [];
        foreach ($row['signals'] as $signal) {
            $away = in_array($signal, [at_risk::SIGNAL_NEVER, at_risk::SIGNAL_INACTIVE], true);
            $badges[] = html_writer::span(
                get_string('signal_' . $signal, 'local_darimaa', $days),
                'badge ' . ($away ? 'bg-danger' : 'bg-warning text-dark') . ' me-1'
            );
        }

        $table->data[] = [
            $student,
            $lastaccess,
            $time,
            $row['missing'],
            $row['notattempted'],
            $average,
            $topics ? implode(', ', $topics) : $dash,
            implode('', $badges),
        ];
    }
    echo html_writer::table($table);
}

echo html_writer::tag('p', get_string('atrisk_note', 'local_darimaa'), ['class' => 'text-muted small']);

echo $OUTPUT->footer();
