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

namespace local_darimaa\form;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->libdir . '/formslib.php');

/**
 * Form for choosing students and writing the reminder text.
 *
 * Custom data: 'students' (user id => label), 'message' (default text), 'choosechannel'
 * (whether the teacher chooses how the reminders go) and 'channel' (how they go otherwise).
 *
 * @package    local_darimaa
 * @copyright  2026 Khuygbaatar Ochirkhuyag
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class remind_form extends \moodleform {
    /**
     * Defines the form fields.
     */
    protected function definition() {
        $mform = $this->_form;
        $students = $this->_customdata['students'];

        // One row per student; only the first row carries the field label.
        $heading = get_string('selectstudents', 'local_darimaa');
        foreach ($students as $userid => $label) {
            $mform->addElement('advcheckbox', "users[{$userid}]", $heading, $label, ['group' => 1], [0, 1]);
            $mform->setType("users[{$userid}]", PARAM_INT);
            $heading = '';
        }
        // Everyone is ticked to start with; the teacher unticks whoever should not get a reminder.
        // The last argument is the starting state the "Select all/none" controller gives the checkboxes.
        $this->add_checkbox_controller(1, null, null, 1);

        // The teacher chooses only when the site setting allows it and chat is possible.
        if (!empty($this->_customdata['choosechannel'])) {
            $mform->addElement('select', 'channel', get_string('channel', 'local_darimaa'), [
                \local_darimaa\local\reminder_sender::CHANNEL_NOTIFICATION => get_string('channel_notification', 'local_darimaa'),
                \local_darimaa\local\reminder_sender::CHANNEL_CHAT => get_string('channel_chat', 'local_darimaa'),
                \local_darimaa\local\reminder_sender::CHANNEL_BOTH => get_string('channel_both', 'local_darimaa'),
            ]);
            $mform->setType('channel', PARAM_ALPHA);
            $mform->setDefault('channel', \local_darimaa\local\reminder_sender::CHANNEL_NOTIFICATION);
            $mform->addHelpButton('channel', 'channel', 'local_darimaa');
        } else if (!empty($this->_customdata['channel'])) {
            $mform->addElement(
                'static',
                'channelinfo',
                get_string('channel', 'local_darimaa'),
                get_string('channel_' . $this->_customdata['channel'], 'local_darimaa')
            );
        }

        $mform->addElement('textarea', 'message', get_string('message', 'local_darimaa'), ['rows' => 10, 'cols' => 70]);
        $mform->setType('message', PARAM_TEXT);
        $mform->setDefault('message', $this->_customdata['message']);
        $mform->addRule('message', null, 'required', null, 'client');
        $mform->addHelpButton('message', 'message', 'local_darimaa');

        $this->add_action_buttons(true, get_string('sendreminders', 'local_darimaa'));
    }

    /**
     * Requires at least one selected student.
     *
     * @param array $data Submitted data.
     * @param array $files Submitted files.
     * @return array Errors keyed by element name.
     */
    public function validation($data, $files) {
        $errors = parent::validation($data, $files);

        $users = $data['users'] ?? [];
        if ($users && empty(array_filter($users))) {
            // Show the error under the first student in the list.
            $errors['users[' . array_key_first($users) . ']'] = get_string('nousersselected', 'local_darimaa');
        }
        return $errors;
    }
}
