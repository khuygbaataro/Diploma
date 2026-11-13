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
 * Lets a user link their Messenger account to Moodle with a one-time code.
 *
 * @package    local_darimaa
 * @copyright  2026 Khuygbaatar Ochirkhuyag
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use core\output\html_writer;
use core\output\notification;
use core\output\single_button;
use local_darimaa\local\link_manager;

require_once(__DIR__ . '/../../config.php');

require_login(null, false);
if (isguestuser()) {
    throw new \core\exception\moodle_exception('noguest');
}

$channel = 'messenger';
$action = optional_param('action', '', PARAM_ALPHA);
$url = new \core\url('/local/darimaa/link.php');

$PAGE->set_url($url);
$PAGE->set_context(\core\context\user::instance($USER->id));
$PAGE->set_pagelayout('standard');
$PAGE->set_title(get_string('linkheading', 'local_darimaa'));
$PAGE->set_heading(fullname($USER));

$code = null;
if ($action === 'newcode') {
    require_sesskey();
    $code = link_manager::create_code($USER->id, $channel);
} else if ($action === 'unlink') {
    require_sesskey();
    link_manager::unlink_user($USER->id, $channel);
    redirect($url, get_string('unlinked', 'local_darimaa'), null, notification::NOTIFY_SUCCESS);
}

$link = link_manager::get_link($USER->id, $channel);
$linked = $link && !empty($link->externalid);

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('linkheading', 'local_darimaa'));

if ($linked) {
    echo $OUTPUT->notification(get_string('linked', 'local_darimaa'), notification::NOTIFY_SUCCESS, false);
} else {
    echo html_writer::tag('p', get_string('linkintro', 'local_darimaa'));
}

if ($code) {
    $shown = substr($code, 0, 4) . '-' . substr($code, 4);
    echo html_writer::div(
        html_writer::tag('div', get_string('linkcode', 'local_darimaa'), ['class' => 'text-muted mb-1']) .
        html_writer::tag('div', s($shown), ['class' => 'h1 fw-bold font-monospace mb-2', 'id' => 'local-darimaa-code']) .
        html_writer::tag('p', get_string('linkcodeinstructions', 'local_darimaa', link_manager::CODE_LIFETIME / MINSECS)),
        'border rounded p-3 mb-3'
    );

    $messengerlink = trim((string) get_config('local_darimaa', 'messengerlink'));
    if ($messengerlink !== '') {
        $openurl = new \core\url($messengerlink, ['ref' => $code]);
        echo html_writer::link($openurl, get_string('openmessenger', 'local_darimaa'), [
            'class' => 'btn btn-primary mb-3',
            'target' => '_blank',
            'rel' => 'noopener noreferrer',
        ]);
    }
} else {
    $label = get_string($linked ? 'newcode' : 'linkaccount', 'local_darimaa');
    echo $OUTPUT->single_button(new \core\url($url, ['action' => 'newcode']), $label, 'post', [
        'type' => $linked ? single_button::BUTTON_SECONDARY : single_button::BUTTON_PRIMARY,
    ]);
}

if ($linked) {
    echo html_writer::div(
        $OUTPUT->single_button(new \core\url($url, ['action' => 'unlink']), get_string('unlink', 'local_darimaa'), 'post'),
        'mt-3'
    );
}

echo $OUTPUT->footer();
