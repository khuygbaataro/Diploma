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
 * Web service functions the assistant bot uses.
 *
 * @package    local_darimaa
 * @copyright  2026 Khuygbaatar Ochirkhuyag
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$functions = [
    'local_darimaa_get_user' => [
        'classname' => \local_darimaa\external\get_user::class,
        'description' => 'Returns the Moodle user linked to a chat app account.',
        'type' => 'read',
        'capabilities' => 'local/darimaa:botaccess',
    ],
    'local_darimaa_link_account' => [
        'classname' => \local_darimaa\external\link_account::class,
        'description' => 'Links a chat app account to the Moodle user who created the given code.',
        'type' => 'write',
        'capabilities' => 'local/darimaa:botaccess',
    ],
    'local_darimaa_unlink_account' => [
        'classname' => \local_darimaa\external\unlink_account::class,
        'description' => 'Removes the link between a chat app account and its Moodle user.',
        'type' => 'write',
        'capabilities' => 'local/darimaa:botaccess',
    ],
    'local_darimaa_get_deadlines' => [
        'classname' => \local_darimaa\external\get_deadlines::class,
        'description' => 'Lists the activities a linked user still has to do, by due date.',
        'type' => 'read',
        'capabilities' => 'local/darimaa:botaccess',
    ],
    'local_darimaa_get_missing' => [
        'classname' => \local_darimaa\external\get_missing::class,
        'description' => 'Lists recent assignments with missing submissions for a linked teacher.',
        'type' => 'read',
        'capabilities' => 'local/darimaa:botaccess',
    ],
    'local_darimaa_send_reminders' => [
        'classname' => \local_darimaa\external\send_reminders::class,
        'description' => 'Sends reminders, in a linked teacher\'s name, to students who have not submitted.',
        'type' => 'write',
        'capabilities' => 'local/darimaa:botaccess',
    ],
];

$services = [
    // Moodle finds this service by its name on upgrade: renaming it would delete existing tokens.
    'Darimaa bot' => [
        'functions' => [
            'local_darimaa_get_user',
            'local_darimaa_link_account',
            'local_darimaa_unlink_account',
            'local_darimaa_get_deadlines',
            'local_darimaa_get_missing',
            'local_darimaa_send_reminders',
        ],
        'requiredcapability' => 'local/darimaa:botaccess',
        'restrictedusers' => 0,
        'enabled' => 1,
        'shortname' => 'local_darimaa_bot',
        'downloadfiles' => 0,
        'uploadfiles' => 0,
    ],
];
