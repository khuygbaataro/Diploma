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

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/user/profile/lib.php');
require_once($CFG->libdir . '/adminlib.php');

/**
 * Tests for reading the student code and for the settings behind it.
 *
 * @package    local_darimaa
 * @category   test
 * @copyright  2026 Khuygbaatar Ochirkhuyag
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversClass(student_code::class)]
final class student_code_test extends \advanced_testcase {
    /**
     * The code comes from the username, the ID number or a custom profile field.
     */
    public function test_code_comes_from_the_chosen_field(): void {
        $this->resetAfterTest();
        $generator = $this->getDataGenerator();
        $generator->create_custom_profile_field(['datatype' => 'text', 'shortname' => 'studentcode', 'name' => 'Student code']);
        $anu = $generator->create_user(['username' => 'anu01', 'idnumber' => 'ID-001']);
        $bold = $generator->create_user(['username' => 'bold02', 'idnumber' => '']);
        profile_save_custom_fields($anu->id, ['studentcode' => 'B200910001']);
        $userids = [$anu->id, $bold->id];

        $choices = student_code::get_choices();
        $this->assertSame(['username', 'idnumber', 'profile_field_studentcode'], array_keys($choices));
        $this->assertSame('Student code', $choices['profile_field_studentcode']);

        // The username is the default.
        unset_config('studentcodefield', 'local_darimaa');
        $this->assertSame('username', student_code::get_field());
        $this->assertSame([$anu->id => 'anu01', $bold->id => 'bold02'], student_code::get_for_users($userids));

        set_config('studentcodefield', 'idnumber', 'local_darimaa');
        $this->assertSame([$anu->id => 'ID-001', $bold->id => ''], student_code::get_for_users($userids));

        set_config('studentcodefield', 'profile_field_studentcode', 'local_darimaa');
        $this->assertSame('profile_field_studentcode', student_code::get_field());
        $this->assertSame([$anu->id => 'B200910001', $bold->id => ''], student_code::get_for_users($userids));

        // A profile field that was deleted falls back to the username.
        set_config('studentcodefield', 'profile_field_gone', 'local_darimaa');
        $this->assertSame('username', student_code::get_field());
        $this->assertSame([$anu->id => 'anu01', $bold->id => 'bold02'], student_code::get_for_users($userids));

        $this->assertSame([], student_code::get_for_users([]));
    }

    /**
     * The settings page offers the code field and the session timeout with their defaults.
     */
    public function test_settings_page_has_both_settings(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $this->getDataGenerator()->create_custom_profile_field([
            'datatype' => 'text',
            'shortname' => 'studentcode',
            'name' => 'Student code',
        ]);

        $page = admin_get_root(true, true)->locate('local_darimaa');
        $this->assertInstanceOf(\admin_settingpage::class, $page);

        $settings = [];
        foreach ($page->settings as $setting) {
            $settings[$setting->name] = $setting;
        }
        $this->assertArrayHasKey('studentcodefield', $settings);
        $this->assertArrayHasKey('sessiontimeout', $settings);

        $codefield = $settings['studentcodefield'];
        $this->assertSame('local_darimaa', $codefield->plugin);
        $this->assertSame('username', $codefield->get_defaultsetting());
        $codefield->load_choices();
        $this->assertSame(['username', 'idnumber', 'profile_field_studentcode'], array_keys($codefield->choices));

        $this->assertEquals(30, $settings['sessiontimeout']->get_defaultsetting());
    }
}
