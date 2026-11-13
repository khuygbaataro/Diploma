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
 * Tests for linking chat app accounts.
 *
 * @package    local_darimaa
 * @category   test
 * @copyright  2026 Khuygbaatar Ochirkhuyag
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversClass(link_manager::class)]
final class link_manager_test extends \advanced_testcase {
    /**
     * A code links the account that sends it, and only works once.
     */
    public function test_code_links_once(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user(['firstname' => 'Anu']);

        $code = link_manager::create_code($user->id, 'messenger');
        $this->assertMatchesRegularExpression('/^[A-HJ-NP-Z2-9]{8}$/', $code);
        $this->assertMatchesRegularExpression('/\d/', $code);
        $this->assertNull(link_manager::get_user('messenger', 'psid-1'));

        // Users may type the code in small letters and with a dash.
        $typed = strtolower(substr($code, 0, 4) . '-' . substr($code, 4));
        $this->assertEquals($user->id, link_manager::link('messenger', $typed, 'psid-1'));
        $this->assertEquals($user->id, link_manager::get_user('messenger', 'psid-1')->id);

        $this->assertNull(link_manager::link('messenger', $code, 'psid-2'));
        $this->assertNull(link_manager::get_user('messenger', 'psid-2'));
    }

    /**
     * Expired and unknown codes do nothing.
     */
    public function test_expired_or_wrong_code_is_refused(): void {
        global $DB;
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();

        $code = link_manager::create_code($user->id, 'messenger');
        $DB->set_field('local_darimaa_links', 'codeexpires', time() - 1, ['userid' => $user->id]);

        $this->assertNull(link_manager::link('messenger', $code, 'psid-1'));
        $this->assertNull(link_manager::link('messenger', 'ABCDEFGH', 'psid-1'));
        $this->assertNull(link_manager::link('messenger', 'short', 'psid-1'));
    }

    /**
     * A chat account belongs to one user: linking it again moves it.
     */
    public function test_relinking_moves_the_account(): void {
        $this->resetAfterTest();
        $first = $this->getDataGenerator()->create_user();
        $second = $this->getDataGenerator()->create_user();

        link_manager::link('messenger', link_manager::create_code($first->id, 'messenger'), 'psid-1');
        link_manager::link('messenger', link_manager::create_code($second->id, 'messenger'), 'psid-1');

        $this->assertEquals($second->id, link_manager::get_user('messenger', 'psid-1')->id);
        $this->assertNull(link_manager::get_link($first->id, 'messenger'));
    }

    /**
     * Unlinking from the chat or from Moodle removes the link; suspended users are not returned.
     */
    public function test_unlink_and_suspended_users(): void {
        global $DB;
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $other = $this->getDataGenerator()->create_user();

        link_manager::link('messenger', link_manager::create_code($user->id, 'messenger'), 'psid-1');
        $this->assertTrue(link_manager::unlink('messenger', 'psid-1'));
        $this->assertFalse(link_manager::unlink('messenger', 'psid-1'));
        $this->assertNull(link_manager::get_user('messenger', 'psid-1'));

        link_manager::link('messenger', link_manager::create_code($user->id, 'messenger'), 'psid-1');
        link_manager::unlink_user($user->id, 'messenger');
        $this->assertNull(link_manager::get_link($user->id, 'messenger'));

        link_manager::link('messenger', link_manager::create_code($other->id, 'messenger'), 'psid-2');
        $DB->set_field('user', 'suspended', 1, ['id' => $other->id]);
        $this->assertNull(link_manager::get_user('messenger', 'psid-2'));
    }

    /**
     * Unknown chat apps are refused.
     */
    public function test_unknown_channel(): void {
        $this->resetAfterTest();
        $this->expectException(\invalid_parameter_exception::class);
        link_manager::create_code($this->getDataGenerator()->create_user()->id, 'myspace');
    }
}
