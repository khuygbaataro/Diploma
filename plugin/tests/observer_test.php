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

namespace local_darimaa;

use local_darimaa\local\link_manager;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Tests for the event observers.
 *
 * @package    local_darimaa
 * @category   test
 * @copyright  2026 Khuygbaatar Ochirkhuyag
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversClass(observer::class)]
final class observer_test extends \advanced_testcase {
    /**
     * Deleting a user removes their link and any waiting code; other users keep theirs.
     */
    public function test_deleting_a_user_removes_their_links(): void {
        global $DB;
        $this->resetAfterTest();
        $linked = $this->getDataGenerator()->create_user(['firstname' => 'Anu']);
        $waiting = $this->getDataGenerator()->create_user(['firstname' => 'Bold']);
        $other = $this->getDataGenerator()->create_user(['firstname' => 'Saraa']);

        link_manager::link('messenger', link_manager::create_code($linked->id, 'messenger'), 'psid-1');
        link_manager::create_code($waiting->id, 'messenger');
        link_manager::link('messenger', link_manager::create_code($other->id, 'messenger'), 'psid-3');
        $this->assertSame(3, $DB->count_records('local_darimaa_links'));

        delete_user($linked);
        delete_user($waiting);

        $this->assertFalse($DB->record_exists('local_darimaa_links', ['userid' => $linked->id]));
        $this->assertFalse($DB->record_exists('local_darimaa_links', ['userid' => $waiting->id]));
        $this->assertNull(link_manager::get_user('messenger', 'psid-1'));
        $this->assertEquals($other->id, link_manager::get_user('messenger', 'psid-3')->id);
        $this->assertSame(1, $DB->count_records('local_darimaa_links'));
    }
}
