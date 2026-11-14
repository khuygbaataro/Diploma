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
 * Creates, or deletes, the demo course of the Teacher & Student Assistant on a test site.
 *
 * Not part of the plugin. Run it on the server as the web server user:
 *
 *   sudo -u www-data php create_demo_course.php --config=/var/www/moodle/config.php --confirm
 *   sudo -u www-data php create_demo_course.php --config=/var/www/moodle/config.php --delete --confirm
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

[$options, $unrecognised] = cli_get_params([
    'config' => '',
    'confirm' => false,
    'delete' => false,
    'help' => false,
], [
    'h' => 'help',
]);

if ($unrecognised) {
    cli_error(get_string('cliunknowoption', 'admin', implode(PHP_EOL . '  ', $unrecognised)));
}

if ($options['help']) {
    cli_writeln("Creates a demo course with invented students for the Teacher & Student Assistant.

Options:
  --config=PATH   Path of Moodle's config.php (needed)
  --confirm       Really create (or, with --delete, really delete). Without it nothing changes
  --delete        Delete the demo course and the demo users that this script created
  -h, --help      Show this help

The course has one teacher, " . darimaa_demo::STUDENTS . " students in two groups, three assignments and two quizzes.
Everything is invented. The students cannot log in. The teacher's password is printed once.
");
    exit(0);
}

cli_writeln('Moodle site: ' . $CFG->wwwroot);
\core\session\manager::set_user(get_admin());

if ($options['delete']) {
    $manifest = darimaa_demo::manifest();
    if (!$manifest) {
        cli_error('This script has not created a demo course on this site. Nothing was deleted.');
    }
    cli_writeln('To delete: the course ' . darimaa_demo::SHORTNAME . ' and ' . (count($manifest['userids']) + 1) .
        ' demo users (usernames that start with ' . darimaa_demo::USER_PREFIX . ').');
    if (!$options['confirm']) {
        cli_writeln('Nothing was deleted. Run again with --delete --confirm to delete them.');
        exit(1);
    }

    $result = darimaa_demo::delete();
    foreach ($result['deleted'] as $line) {
        cli_writeln('Deleted: ' . $line);
    }
    foreach ($result['skipped'] as $line) {
        cli_writeln('Left alone: ' . $line);
    }
    exit($result['skipped'] ? 1 : 0);
}

// Check everything before the first change, so that a refusal changes nothing.
$blockers = darimaa_demo::blockers();
if ($blockers) {
    foreach ($blockers as $reason) {
        cli_writeln('Cannot create the demo course: ' . $reason);
    }
    cli_error('Nothing was changed. To start again, first run this script with --delete --confirm.');
}

cli_writeln('To create: the course "' . darimaa_demo::FULLNAME . '" (' . darimaa_demo::SHORTNAME . ') with one teacher, ' .
    darimaa_demo::STUDENTS . ' invented students, three assignments and two quizzes.');
if (!$options['confirm']) {
    cli_writeln('Nothing was created. Run again with --confirm to create it on this site.');
    exit(1);
}
if (!class_exists(\local_darimaa\local\quiz_topics::class)) {
    cli_writeln('Note: the plugin local_darimaa 0.3.0 or later is not installed here. The reports need it.');
}
if (!in_array('logstore_standard', explode(',', (string) get_config('tool_log', 'enabled_stores')), true)) {
    cli_writeln('Note: the standard log store is off on this site, so the reports will show no time estimate.');
}

$result = darimaa_demo::create();

cli_writeln('');
cli_writeln('Created the demo course: ' . $result['url']);
foreach ($result['counts'] as $what => $count) {
    cli_writeln('  ' . $what . ': ' . $count);
}
cli_writeln('');
cli_writeln('Demo teacher (shown only now, and stored nowhere else):');
cli_writeln('  username: ' . $result['teacher']);
cli_writeln('  password: ' . $result['password']);
cli_writeln('');
cli_writeln('To remove everything again, run this script with --delete --confirm.');
