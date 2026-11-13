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
 * Prepares Moodle for the assistant bot and prints its access token.
 *
 * Turns on web services and the REST protocol, creates a service account with a
 * role that can only use the assistant bot functions, and creates a token for it.
 * Running it again is safe: it reuses what already exists.
 *
 * @package    local_darimaa
 * @copyright  2026 Khuygbaatar Ochirkhuyag
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('CLI_SCRIPT', true);

require(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/clilib.php');
require_once($CFG->dirroot . '/user/lib.php');

[$options, $unrecognised] = cli_get_params([
    'help' => false,
    'username' => 'darimaabot',
    'iprestriction' => '',
    'newtoken' => false,
], [
    'h' => 'help',
]);

if ($unrecognised) {
    cli_error(get_string('cliunknowoption', 'admin', implode(PHP_EOL . '  ', $unrecognised)));
}

if ($options['help']) {
    cli_writeln("Prepares Moodle for the assistant bot and prints its access token.

Options:
  --username=NAME        Username of the bot's service account (default: darimaabot)
  --iprestriction=LIST   Only accept the token from these IP addresses, for example
                         the server's own address. Comma separated.
  --newtoken             Replace the existing token with a new one
  -h, --help             Show this help

Example:
  sudo -u www-data php local/darimaa/cli/setup_bot.php --iprestriction=203.0.113.10
");
    exit(0);
}

$username = core_user::clean_field(trim($options['username']), 'username');
if ($username === '') {
    cli_error('Give a valid --username.');
}
$iprestriction = trim((string) $options['iprestriction']);

\core\session\manager::set_user(get_admin());
$syscontext = \core\context\system::instance();

// Web services and the REST protocol.
if (empty($CFG->enablewebservices)) {
    set_config('enablewebservices', 1);
    cli_writeln('Turned on web services.');
}
if (\core\plugininfo\webservice::enable_plugin('rest', 1)) {
    cli_writeln('Turned on the REST protocol.');
}

// A role that only allows the bot functions.
$roleid = (int) $DB->get_field('role', 'id', ['shortname' => 'darimaabot']);
if (!$roleid) {
    $roleid = create_role(
        'Assistant bot',
        'darimaabot',
        'Service account role for the Teacher & Student Assistant bot. Do not give it to people.'
    );
    cli_writeln('Created the "Assistant bot" role.');
}
set_role_contextlevels($roleid, [CONTEXT_SYSTEM]);
assign_capability('local/darimaa:botaccess', CAP_ALLOW, $roleid, $syscontext->id, true);
assign_capability('webservice/rest:use', CAP_ALLOW, $roleid, $syscontext->id, true);

// The service account.
$user = $DB->get_record('user', ['username' => $username, 'mnethostid' => $CFG->mnet_localhost_id, 'deleted' => 0]);
if (!$user) {
    $record = (object) [
        'username' => $username,
        'auth' => 'manual',
        'password' => random_string(32) . 'aA1!',
        'firstname' => 'Assistant',
        'lastname' => 'Bot',
        'email' => $username . '@localhost.invalid',
        'confirmed' => 1,
        'mnethostid' => $CFG->mnet_localhost_id,
    ];
    // Moodle 5.3 moved user creation into the user class.
    if (method_exists('\\core\\user', 'create_user')) {
        $userid = \core\user::create_user($record, true, false);
    } else {
        $userid = user_create_user($record, true, false);
    }
    $user = core_user::get_user($userid);
    cli_writeln("Created the service account \"{$username}\".");
}
// It never logs in through the website, never gets email, and has nothing to agree to.
$DB->update_record('user', (object) ['id' => $user->id, 'policyagreed' => 1, 'emailstop' => 1]);

if (!user_has_role_assignment($user->id, $roleid, $syscontext->id)) {
    role_assign($roleid, $user->id, $syscontext->id);
}
accesslib_clear_all_caches(true);

// The token.
$service = $DB->get_record('external_services', ['shortname' => 'local_darimaa_bot'], '*', MUST_EXIST);
if (empty($service->enabled)) {
    $DB->set_field('external_services', 'enabled', 1, ['id' => $service->id]);
}

$existing = $DB->get_records('external_tokens', [
    'userid' => $user->id,
    'externalserviceid' => $service->id,
    'tokentype' => EXTERNAL_TOKEN_PERMANENT,
], 'id DESC');

if ($existing && !$options['newtoken']) {
    $token = reset($existing);
    if ($iprestriction !== '' && $token->iprestriction !== $iprestriction) {
        $DB->set_field('external_tokens', 'iprestriction', $iprestriction, ['id' => $token->id]);
        cli_writeln('Updated the token\'s IP restriction.');
    }
    $tokenvalue = $token->token;
    cli_writeln('Reusing the existing token. Use --newtoken to replace it.');
} else {
    foreach ($existing as $old) {
        $DB->delete_records('external_tokens', ['id' => $old->id]);
    }
    $tokenvalue = \core_external\util::generate_token(
        EXTERNAL_TOKEN_PERMANENT,
        $service,
        $user->id,
        $syscontext,
        0,
        $iprestriction,
        'Assistant bot'
    );
    cli_writeln('Created a new token.');
}

cli_writeln('');
cli_writeln('Put these two lines in the bot\'s .env file:');
cli_writeln('MOODLE_URL=' . $CFG->wwwroot);
cli_writeln('MOODLE_TOKEN=' . $tokenvalue);
