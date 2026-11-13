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
 * Site settings for the Teacher & Student Assistant.
 *
 * @package    local_darimaa
 * @copyright  2026 Khuygbaatar Ochirkhuyag
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

if ($hassiteconfig) {
    $settings = new admin_settingpage('local_darimaa', new lang_string('pluginname', 'local_darimaa'));
    $ADMIN->add('localplugins', $settings);

    if ($ADMIN->fulltree) {
        $settings->add(new admin_setting_configtextarea(
            'local_darimaa/defaultmessage',
            new lang_string('defaultmessage', 'local_darimaa'),
            new lang_string('defaultmessage_desc', 'local_darimaa'),
            get_string('defaultmessage_text', 'local_darimaa'),
            PARAM_TEXT
        ));

        $settings->add(new admin_setting_configtext(
            'local_darimaa/messengerlink',
            new lang_string('messengerlink', 'local_darimaa'),
            new lang_string('messengerlink_desc', 'local_darimaa'),
            '',
            PARAM_URL
        ));

        $settings->add(new admin_setting_configselect(
            'local_darimaa/studentcodefield',
            new lang_string('studentcodefield', 'local_darimaa'),
            new lang_string('studentcodefield_desc', 'local_darimaa'),
            \local_darimaa\local\student_code::DEFAULT_FIELD,
            \local_darimaa\local\student_code::get_choices()
        ));

        $settings->add(new admin_setting_configtext(
            'local_darimaa/sessiontimeout',
            new lang_string('sessiontimeout', 'local_darimaa'),
            new lang_string('sessiontimeout_desc', 'local_darimaa'),
            30,
            PARAM_INT
        ));
    }
}
