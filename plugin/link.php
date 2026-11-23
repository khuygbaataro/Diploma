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
 * Lets a user link their chat app accounts (Telegram, Messenger) to Moodle with a one-time code.
 *
 * @package    local_darimaa
 * @copyright  2026 Khuygbaatar Ochirkhuyag
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use core\output\html_writer;
use core\output\notification;
use core\output\single_button;
use local_darimaa\local\chat_apps;
use local_darimaa\local\link_manager;

require_once(__DIR__ . '/../../config.php');

require_login(null, false);
if (isguestuser()) {
    throw new \core\exception\moodle_exception('noguest');
}

$apps = chat_apps::get_enabled();
$action = optional_param('action', '', PARAM_ALPHA);
$channel = optional_param('channel', reset($apps), PARAM_ALPHA);
$url = new \core\url('/local/darimaa/link.php');

$PAGE->set_url($url);
$PAGE->set_context(\core\context\user::instance($USER->id));
$PAGE->set_pagelayout('standard');
$PAGE->set_title(get_string('linkheading', 'local_darimaa'));
$PAGE->set_heading(fullname($USER));

$code = null;
if ($action !== '') {
    require_sesskey();
    if (!in_array($channel, $apps, true)) {
        throw new \core\exception\moodle_exception('invalidparameter', 'debug');
    }
    $name = chat_apps::get_name($channel);
    if ($action === 'newcode') {
        $code = link_manager::create_code($USER->id, $channel);
    } else if ($action === 'unlink') {
        link_manager::unlink_user($USER->id, $channel);
        redirect($url, get_string('unlinked', 'local_darimaa', $name), null, notification::NOTIFY_SUCCESS);
    }
}

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('linkheading', 'local_darimaa'));
echo html_writer::tag('p', get_string('linkintro', 'local_darimaa'));

foreach ($apps as $app) {
    $name = chat_apps::get_name($app);
    $link = link_manager::get_link($USER->id, $app);
    $linked = $link && !empty($link->externalid);
    $appurl = new \core\url($url, ['channel' => $app]);

    $body = $OUTPUT->heading($name, 3);
    if ($linked) {
        $body .= $OUTPUT->notification(get_string('linked', 'local_darimaa', $name), notification::NOTIFY_SUCCESS, false);
    }

    if ($code && $app === $channel) {
        $shown = substr($code, 0, 4) . '-' . substr($code, 4);
        $instructions = get_string('linkcodeinstructions', 'local_darimaa', (object) [
            'app' => $name,
            'minutes' => link_manager::CODE_LIFETIME / MINSECS,
        ]);
        $body .= html_writer::tag('div', get_string('linkcode', 'local_darimaa'), ['class' => 'text-muted mb-1']);
        $body .= html_writer::tag('div', s($shown), ['class' => 'h1 fw-bold font-monospace mb-2', 'id' => 'local-darimaa-code']);
        $body .= html_writer::tag('p', $instructions);
        $openurl = chat_apps::get_open_url($app, $code);
        if ($openurl) {
            $body .= html_writer::link($openurl, get_string('openapp', 'local_darimaa', $name), [
                'class' => 'btn btn-primary mb-3',
                'target' => '_blank',
                'rel' => 'noopener noreferrer',
            ]);
        }
    } else {
        $label = $linked ? get_string('newcode', 'local_darimaa') : get_string('linkaccount', 'local_darimaa', $name);
        $body .= $OUTPUT->single_button(new \core\url($appurl, ['action' => 'newcode']), $label, 'post', [
            'type' => $linked ? single_button::BUTTON_SECONDARY : single_button::BUTTON_PRIMARY,
        ]);
    }

    if ($linked) {
        $body .= html_writer::div(
            $OUTPUT->single_button(
                new \core\url($appurl, ['action' => 'unlink']),
                get_string('unlink', 'local_darimaa', $name),
                'post'
            ),
            'mt-3'
        );
    }

    echo html_writer::div($body, 'border rounded p-3 mb-3');
}

echo $OUTPUT->footer();
