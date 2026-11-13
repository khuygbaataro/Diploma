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

use PHPUnit\Framework\Attributes\CoversFunction;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/local/darimaa/lib.php');

/**
 * Tests for the plugin callbacks.
 *
 * @package    local_darimaa
 * @category   test
 * @copyright  2026 Khuygbaatar Ochirkhuyag
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversFunction('local_darimaa_extend_navigation_course')]
final class lib_test extends \advanced_testcase {
    /**
     * Teachers see the navigation link, students do not.
     */
    public function test_course_navigation_link_only_for_teachers(): void {
        $this->resetAfterTest();
        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $teacher = $generator->create_and_enrol($course, 'editingteacher');
        $student = $generator->create_and_enrol($course, 'student');
        $context = \core\context\course::instance($course->id);

        $this->setUser($teacher);
        $node = \navigation_node::create('Course');
        local_darimaa_extend_navigation_course($node, $course, $context);
        $this->assertNotFalse($node->find('local_darimaa_missing', \navigation_node::TYPE_SETTING));

        $this->setUser($student);
        $node = \navigation_node::create('Course');
        local_darimaa_extend_navigation_course($node, $course, $context);
        $this->assertFalse($node->find('local_darimaa_missing', \navigation_node::TYPE_SETTING));
    }
}
