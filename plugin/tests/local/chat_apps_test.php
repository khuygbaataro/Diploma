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

use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Tests for the chat apps a site offers.
 *
 * @package    local_darimaa
 * @category   test
 * @copyright  2026 Khuygbaatar Ochirkhuyag
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversClass(chat_apps::class)]
final class chat_apps_test extends \advanced_testcase {
    /**
     * Without any setting only Messenger is offered, as before Telegram existed.
     */
    public function test_messenger_alone_by_default(): void {
        $this->resetAfterTest();
        $this->assertSame([chat_apps::MESSENGER], chat_apps::get_enabled());
        $this->assertNull(chat_apps::get_open_url(chat_apps::MESSENGER, 'AB23CD45'));
        $this->assertNull(chat_apps::get_open_url(chat_apps::TELEGRAM, 'AB23CD45'));
    }

    /**
     * A Telegram bot name turns Telegram on; Messenger stays only when its link is set.
     */
    public function test_telegram_bot_and_messenger_link(): void {
        $this->resetAfterTest();

        set_config('telegrambot', '@school_helper_bot', 'local_darimaa');
        $this->assertSame('school_helper_bot', chat_apps::get_telegram_bot());
        $this->assertSame([chat_apps::TELEGRAM], chat_apps::get_enabled());
        $this->assertSame(
            'https://t.me/school_helper_bot?start=AB23CD45',
            chat_apps::get_open_url(chat_apps::TELEGRAM, 'AB23CD45')->out(false)
        );

        set_config('messengerlink', 'https://m.me/schoolpage', 'local_darimaa');
        $this->assertSame([chat_apps::TELEGRAM, chat_apps::MESSENGER], chat_apps::get_enabled());
        $this->assertSame(
            'https://m.me/schoolpage?ref=AB23CD45',
            chat_apps::get_open_url(chat_apps::MESSENGER, 'AB23CD45')->out(false)
        );
    }

    /**
     * A name that cannot be a Telegram username is ignored.
     */
    public function test_wrong_telegram_names_are_ignored(): void {
        $this->resetAfterTest();
        foreach (['bot', 'https://t.me/school_bot', '1school_bot', 'school bot'] as $name) {
            set_config('telegrambot', $name, 'local_darimaa');
            $this->assertSame('', chat_apps::get_telegram_bot(), $name);
            $this->assertSame([chat_apps::MESSENGER], chat_apps::get_enabled(), $name);
        }
    }

    /**
     * The settings page has the Telegram bot name next to the Messenger link, empty by default.
     */
    public function test_settings_page_has_the_telegram_bot(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $names = [];
        foreach (admin_get_root(true, true)->locate('local_darimaa')->settings as $setting) {
            $names[] = $setting->name;
        }
        $this->assertContains('telegrambot', $names);
        $this->assertSame(array_search('messengerlink', $names) + 1, array_search('telegrambot', $names));
        $this->assertEmpty(get_config('local_darimaa', 'telegrambot'));
    }

    /**
     * Each chat app has a name for users.
     */
    public function test_names(): void {
        $this->assertSame('Messenger', chat_apps::get_name(chat_apps::MESSENGER));
        $this->assertSame('Telegram', chat_apps::get_name(chat_apps::TELEGRAM));
    }
}
