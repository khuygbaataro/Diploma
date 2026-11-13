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
 * Links a chat app account to the Moodle user who created a code.
 *
 * @package    local_darimaa
 * @copyright  2026 Khuygbaatar Ochirkhuyag
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class link_account extends bot_function {
    /**
     * Describes the parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters(self::account_parameters() + [
            'code' => new external_value(PARAM_RAW_TRIMMED, 'The code the user sent to the bot'),
        ]);
    }

    /**
     * Confirms the link if the code is valid.
     *
     * @param string $channel The chat app.
     * @param string $externalid The account id in the chat app.
     * @param string $code The code the user sent.
     * @return array
     */
    public static function execute(string $channel, string $externalid, string $code): array {
        [
            'channel' => $channel,
            'externalid' => $externalid,
            'code' => $code,
        ] = self::validate_parameters(self::execute_parameters(), [
            'channel' => $channel,
            'externalid' => $externalid,
            'code' => $code,
        ]);
        self::require_bot($channel);

        $userid = link_manager::link($channel, $code, $externalid);
        $user = $userid ? link_manager::get_user($channel, $externalid) : null;

        return [
            'linked' => (bool) $user,
            'firstname' => $user ? $user->firstname : '',
        ];
    }

    /**
     * Describes the result.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'linked' => new external_value(PARAM_BOOL, 'Whether the code was valid and the account is now linked'),
            'firstname' => new external_value(PARAM_TEXT, 'The linked user\'s first name'),
        ]);
    }
}
