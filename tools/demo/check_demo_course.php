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
 * Checks that the demo course gives the reports for teachers something to show.
 *
 * Not part of the plugin. It calls the plugin's report classes as the demo teacher and ends
 * with exit code 1 when something is not as expected. With --gone it checks the opposite:
 * that the demo course and the demo users are no longer there.
 *
 *   sudo -u www-data php check_demo_course.php --config=/var/www/moodle/config.php
 *
 * @package    local_darimaa
 * @copyright  2026 Khuygbaatar Ochirkhuyag
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('CLI_SCRIPT', true);

// The script lives outside Moodle, so it has to be told where Moodle's config.php is.
$configfile = null;
foreach ($_SERVER['argv'] as $argument) {
    if (str_starts_with($argument, '--config=')) {
        $configfile = realpath(substr($argument, strlen('--config=')));
    }
}
if (!$configfile || !is_readable($configfile)) {
    fwrite(STDERR, "Give the path of Moodle's config.php, for example: --config=/var/www/moodle/config.php\n");
    exit(1);
}

require($configfile);
require_once($CFG->libdir . '/clilib.php');
require_once(__DIR__ . '/demolib.php');
require_once($CFG->dirroot . '/mod/assign/locallib.php');

[$options, $unrecognised] = cli_get_params([
    'config' => '',
    'gone' => false,
    'help' => false,
], [
    'h' => 'help',
]);

if ($unrecognised) {
    cli_error(get_string('cliunknowoption', 'admin', implode(PHP_EOL . '  ', $unrecognised)));
}

if ($options['help']) {
    cli_writeln("Checks the demo course of the Teacher & Student Assistant.

Options:
  --config=PATH   Path of Moodle's config.php (needed)
  --gone          Check that the demo course and the demo users are no longer there
  -h, --help      Show this help
");
    exit(0);
}

cli_writeln('Moodle site: ' . $CFG->wwwroot);
\core\session\manager::set_user(get_admin());

$failed = 0;
$check = static function (string $what, bool $ok, string $found = '') use (&$failed): void {
    if (!$ok) {
        $failed++;
    }
    cli_writeln(($ok ? 'OK    ' : 'WRONG ') . $what . ($found === '' ? '' : ' (' . $found . ')'));
};

$manifest = darimaa_demo::manifest();

if ($options['gone']) {
    $inuse = darimaa_demo::usernames_in_use();
    $check('the script remembers no demo course', $manifest === null);
    $check('no course is called ' . darimaa_demo::SHORTNAME, !$DB->record_exists('course', [
        'shortname' => darimaa_demo::SHORTNAME,
    ]));
    $check('no user has a demo username', !$inuse, implode(', ', $inuse));
    cli_writeln($failed ? 'The demo data is not completely gone.' : 'The demo data is gone.');
    exit($failed ? 1 : 0);
}

if (!$manifest) {
    cli_error('There is no demo course on this site. Create it first with create_demo_course.php.');
}
if (!class_exists(\local_darimaa\local\quiz_topics::class)) {
    cli_error('The plugin local_darimaa 0.3.0 or later is not installed on this site.');
}

$course = get_course($manifest['courseid']);
$context = \core\context\course::instance($course->id);
$teacher = $DB->get_record('user', ['id' => $manifest['teacherid'], 'deleted' => 0], '*', MUST_EXIST);

// The course list, as the teacher gets it.
$students = \local_darimaa\local\course_students::count($context);
$check('the course has ' . darimaa_demo::STUDENTS . ' students', $students === darimaa_demo::STUDENTS, $students . ' found');

$mine = array_values(array_filter(
    \local_darimaa\local\teacher_courses::get_overview($teacher),
    static fn(array $row): bool => $row['id'] === (int) $course->id
));
$check('the teacher sees the course in their course list', count($mine) === 1);
$quizzes = $mine[0]['quizzes'] ?? [];
$finished = array_column($quizzes, 'finished');
$check('the course has two quizzes with finished attempts', count($quizzes) === 2 && min($finished ?: [0]) > 0, implode(
    ', ',
    array_map(static fn(array $quiz): string => $quiz['name'] . ': ' . $quiz['finished'], $quizzes)
));

$modinfo = get_fast_modinfo($course, $teacher->id);

// Inactive students.
$report = \local_darimaa\local\inactive_students::get_report($course, $teacher, 7);
$rows = $report['students'];
$inactive = array_filter($rows, static fn(array $row): bool => $row['inactive']);
$never = array_filter($inactive, static fn(array $row): bool => $row['dayssince'] === -1);
$away = array_filter($inactive, static fn(array $row): bool => $row['dayssince'] >= 8);
$minutes = array_column($rows, 'minutes');
$check('the activity report lists all students', count($rows) === darimaa_demo::STUDENTS, count($rows) . ' found');
$check('the last access is available', $report['lastaccessavailable']);
$check('an inactive student never accessed the course', count($never) > 0, count($never) . ' found');
$check('an inactive student has been away for 8 days or more', count($away) > 0, count($away) . ' found');
$check('the time estimate is available', $report['timeavailable']);
$check('a student has more than 60 minutes', $minutes && max($minutes) > 60, 'from ' . min($minutes ?: [0]) . ' to ' .
    max($minutes ?: [0]) . ' minutes');
$missing = array_column($rows, 'missing');
$check('a student has a missing assignment', max($missing ?: [0]) > 0, 'at most ' . max($missing ?: [0]) . ' for one student');

// The assignments, as the teacher sees them.
$now = time();
foreach ($modinfo->get_instances_of('assign') as $cm) {
    $assign = new \assign($cm->context, $cm, $course);
    $duedate = (int) $assign->get_instance()->duedate;
    $notsubmitted = \local_darimaa\local\missing_submissions::get_missing_users_for($assign, $teacher);
    cli_writeln('INFO  ' . $cm->name . ': due ' . round(($duedate - $now) / DAYSECS) . ' days from now, ' .
        count($assign->list_participants(0, true)) . ' participants, ' . count($notsubmitted) . ' have not submitted');
}

// Quiz results by topic.
$expected = [
    [darimaa_demo::QUIZ1, \local_darimaa\local\quiz_topics::SOURCE_GRADEITEMS],
    [darimaa_demo::QUIZ2, \local_darimaa\local\quiz_topics::SOURCE_CATEGORIES],
];
foreach ($manifest['quizzes'] as $index => $cmid) {
    [$name, $source] = $expected[$index];
    $results = \local_darimaa\local\quiz_topics::get_report($modinfo->get_cm($cmid), $teacher);

    $weak = 0;
    foreach ($results['students'] as $student) {
        foreach ($student['topics'] as $topic) {
            if ($topic['maxmark'] > 0 && $topic['percent'] < 60) {
                $weak++;
                break;
            }
        }
    }
    $label = 'quiz ' . ($index + 1) . ': ';
    $check($label . 'it is "' . $name . '"', $modinfo->get_cm($cmid)->name === $name);
    $check($label . 'topics come from ' . $source, $results['topicsource'] === $source, $results['topicsource']);
    $check($label . 'it has 3 topics', count($results['topics']) === 3, implode(', ', array_map(
        static fn(array $topic): string => $topic['name'] . ' ' . $topic['average'] . '%',
        $results['topics']
    )));
    $check($label . 'some students did not attempt', $results['notattempted'] > 0, count($results['students']) .
        ' attempted, ' . $results['notattempted'] . ' did not');
    $check($label . 'a student has a topic below 60 percent', $weak > 0, $weak . ' students');
}

cli_writeln($failed ? $failed . ' checks failed.' : 'All checks passed.');
exit($failed ? 1 : 0);
