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

namespace local_darimaa\external;

use core_external\external_function_parameters;
use core_external\external_single_structure;
use core_external\external_value;
use local_darimaa\local\link_manager;

/**
 * Returns the Moodle user linked to a chat app account.
 *
 * @package    local_darimaa
 * @copyright  2026 Khuygbaatar Ochirkhuyag
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class get_user extends bot_function {
    /**
     * Describes the parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters(self::account_parameters());
    }

    /**
     * Returns the linked user, or tells where to link the account.
     *
     * @param string $channel The chat app.
     * @param string $externalid The account id in the chat app.
     * @return array
     */
    public static function execute(string $channel, string $externalid): array {
        [
            'channel' => $channel,
            'externalid' => $externalid,
        ] = self::validate_parameters(self::execute_parameters(), [
            'channel' => $channel,
            'externalid' => $externalid,
        ]);
        self::require_bot($channel);

        $result = [
            'linked' => false,
            'userid' => 0,
            'firstname' => '',
            'fullname' => '',
            'lang' => '',
            'timezone' => '',
            'isteacher' => false,
            'linkurl' => (new \core\url('/local/darimaa/link.php'))->out(false),
        ];

        $user = link_manager::get_user($channel, $externalid);
        if ($user) {
            $result['linked'] = true;
            $result['userid'] = (int) $user->id;
            $result['firstname'] = $user->firstname;
            $result['fullname'] = fullname($user);
            $result['lang'] = $user->lang;
            $result['timezone'] = \core_date::get_user_timezone($user);
            $result['isteacher'] = self::is_teacher($user);
        }
        return $result;
    }

    /**
     * Describes the result.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'linked' => new external_value(PARAM_BOOL, 'Whether the chat account is linked to an active user'),
            'userid' => new external_value(PARAM_INT, 'The linked user, or 0'),
            'firstname' => new external_value(PARAM_TEXT, 'The user\'s first name'),
            'fullname' => new external_value(PARAM_TEXT, 'The user\'s full name'),
            'lang' => new external_value(PARAM_ALPHANUMEXT, 'The user\'s language code'),
            'timezone' => new external_value(PARAM_TIMEZONE, 'The user\'s time zone'),
            'isteacher' => new external_value(PARAM_BOOL, 'Whether the user can send reminders in a course'),
            'linkurl' => new external_value(PARAM_URL, 'The Moodle page where users get a link code'),
        ]);
    }
}
