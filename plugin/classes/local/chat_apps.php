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

namespace local_darimaa\local;

/**
 * The chat apps in which users can reach the assistant bot, as the site set them up.
 *
 * Telegram is offered when the site names its Telegram bot. Messenger is offered when the site
 * gives its Messenger link, and also when no Telegram bot is named, as before Telegram existed.
 *
 * @package    local_darimaa
 * @copyright  2026 Khuygbaatar Ochirkhuyag
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class chat_apps {
    /** @var string Messenger. */
    public const MESSENGER = 'messenger';

    /** @var string Telegram. */
    public const TELEGRAM = 'telegram';

    /**
     * The username of the site's Telegram bot, without the @, or '' when there is none.
     *
     * @return string
     */
    public static function get_telegram_bot(): string {
        $name = ltrim(trim((string) get_config('local_darimaa', 'telegrambot')), '@');
        return preg_match('/^[A-Za-z][A-Za-z0-9_]{3,31}$/', $name) ? $name : '';
    }

    /**
     * The chat apps users can connect, in the order they are shown.
     *
     * @return string[]
     */
    public static function get_enabled(): array {
        $apps = [];
        if (self::get_telegram_bot() !== '') {
            $apps[] = self::TELEGRAM;
        }
        if (trim((string) get_config('local_darimaa', 'messengerlink')) !== '' || !$apps) {
            $apps[] = self::MESSENGER;
        }
        return $apps;
    }

    /**
     * The name of a chat app, as users know it.
     *
     * @param string $app The chat app.
     * @return string
     */
    public static function get_name(string $app): string {
        return get_string('app_' . $app, 'local_darimaa');
    }

    /**
     * A link that opens the chat with the bot and hands it the code, or null when the site gave none.
     *
     * @param string $app The chat app.
     * @param string $code The link code.
     * @return \core\url|null
     */
    public static function get_open_url(string $app, string $code): ?\core\url {
        if ($app === self::TELEGRAM) {
            $bot = self::get_telegram_bot();
            // Telegram passes the start parameter to the bot as "/start CODE".
            return $bot === '' ? null : new \core\url('https://t.me/' . $bot, ['start' => $code]);
        }
        $messengerlink = trim((string) get_config('local_darimaa', 'messengerlink'));
        return $messengerlink === '' ? null : new \core\url($messengerlink, ['ref' => $code]);
    }
}
