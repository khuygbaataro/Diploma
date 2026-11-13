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

use core_external\external_api;
use core_external\external_value;
use local_darimaa\local\link_manager;

/**
 * Shared checks for the web service functions the chat bot calls.
 *
 * The bot calls them with its own service account token, always on behalf of a
 * chat account. Data is only returned for chat accounts their owner has linked.
 *
 * @package    local_darimaa
 * @copyright  2026 Khuygbaatar Ochirkhuyag
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
abstract class bot_function extends external_api {
    /**
     * Describes the parameters that name a chat account.
     *
     * @return external_value[]
     */
    protected static function account_parameters(): array {
        return [
            'channel' => new external_value(PARAM_ALPHA, 'The chat app, for example messenger'),
            'externalid' => new external_value(PARAM_ALPHANUMEXT, 'The account id the chat app gave the user'),
        ];
    }

    /**
     * Checks that the caller is the bot's service account.
     *
     * @param string $channel The chat app named in the call.
     */
    protected static function require_bot(string $channel): void {
        $context = \core\context\system::instance();
        self::validate_context($context);
        require_capability('local/darimaa:botaccess', $context);
        link_manager::validate_channel($channel);
    }

    /**
     * Whether a user can send reminders in at least one of their courses.
     *
     * @param \stdClass $user The user.
     * @return bool
     */
    protected static function is_teacher(\stdClass $user): bool {
        foreach (enrol_get_users_courses($user->id, true, 'id') as $course) {
            if (has_capability('local/darimaa:sendreminders', \core\context\course::instance($course->id), $user)) {
                return true;
            }
        }
        return false;
    }
}
