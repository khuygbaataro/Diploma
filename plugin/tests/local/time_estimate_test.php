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
 * Tests for the estimate of time spent in a course, on fixed log rows.
 *
 * @package    local_darimaa
 * @category   test
 * @copyright  2026 Khuygbaatar Ochirkhuyag
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversClass(time_estimate::class)]
final class time_estimate_test extends \advanced_testcase {
    /** @var int The time of minute 0 in these tests. */
    private const START = 1790000000;

    /**
     * Writes one row to the standard log.
     *
     * @param int $courseid The course.
     * @param int $userid The user who acted.
     * @param int $minute Minutes after the start time.
     * @param array $extra Other column values.
     */
    private function log(int $courseid, int $userid, int $minute, array $extra = []): void {
        global $DB;

        $context = \core\context\course::instance($courseid);
        $DB->insert_record('logstore_standard_log', (object) ($extra + [
            'eventname' => '\core\event\course_viewed',
            'component' => 'core',
            'action' => 'viewed',
            'target' => 'course',
            'crud' => 'r',
            'edulevel' => 2,
            'contextid' => $context->id,
            'contextlevel' => CONTEXT_COURSE,
            'contextinstanceid' => $courseid,
            'userid' => $userid,
            'courseid' => $courseid,
            'anonymous' => 0,
            'timecreated' => self::START + $minute * MINSECS,
            'origin' => 'web',
            'realuserid' => null,
        ]));
    }

    /**
     * Events at minute 0, 10, 50 and 55 with a 30 minute timeout give 15 minutes.
     */
    public function test_pauses_shorter_than_the_timeout_are_added(): void {
        $this->resetAfterTest();
        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $othercourse = $generator->create_course();
        $anu = $generator->create_user(['firstname' => 'Anu']);
        $bold = $generator->create_user(['firstname' => 'Bold']);
        $saraa = $generator->create_user(['firstname' => 'Saraa']);
        $teacher = $generator->create_user(['firstname' => 'Bat']);

        foreach ([0, 10, 50, 55] as $minute) {
            $this->log($course->id, $anu->id, $minute);
        }
        // These rows would fill Anu's long pause, but they do not count: an anonymous action,
        // an action a teacher did while logged in as Anu, and an action in another course.
        $this->log($course->id, $anu->id, 25, ['anonymous' => 1]);
        $this->log($course->id, $anu->id, 30, ['realuserid' => $teacher->id]);
        $this->log($othercourse->id, $anu->id, 35);
        // Bold came twice, an hour apart. Saraa came once.
        $this->log($course->id, $bold->id, 0);
        $this->log($course->id, $bold->id, 60);
        $this->log($course->id, $saraa->id, 5);

        set_config('sessiontimeout', 30, 'local_darimaa');
        $this->assertSame([$anu->id => 15 * MINSECS], time_estimate::get_seconds($course->id, self::START));

        // Actions before the given time are not read: minutes 10, 50 and 55 are left.
        $this->assertSame(
            [$anu->id => 5 * MINSECS],
            time_estimate::get_seconds($course->id, self::START + 5 * MINSECS)
        );

        // A pause counts only when it is shorter than the timeout.
        $this->assertSame([], time_estimate::get_seconds($course->id, self::START, 5 * MINSECS));
        $this->assertSame([$anu->id => 5 * MINSECS], time_estimate::get_seconds($course->id, self::START, 6 * MINSECS));
        $seconds = time_estimate::get_seconds($course->id, self::START, 61 * MINSECS);
        $this->assertSame(55 * MINSECS, $seconds[$anu->id]);
        $this->assertSame(60 * MINSECS, $seconds[$bold->id]);
        $this->assertArrayNotHasKey($saraa->id, $seconds);
    }

    /**
     * The timeout comes from the setting, with 30 minutes when it is empty.
     */
    public function test_timeout_setting_and_log_store_switch(): void {
        $this->resetAfterTest();

        unset_config('sessiontimeout', 'local_darimaa');
        $this->assertSame(30 * MINSECS, time_estimate::get_timeout());
        set_config('sessiontimeout', 0, 'local_darimaa');
        $this->assertSame(30 * MINSECS, time_estimate::get_timeout());
        set_config('sessiontimeout', 45, 'local_darimaa');
        $this->assertSame(45 * MINSECS, time_estimate::get_timeout());

        set_config('enabled_stores', '', 'tool_log');
        $this->assertFalse(time_estimate::is_available());
        set_config('enabled_stores', 'logstore_legacy,logstore_standard', 'tool_log');
        $this->assertTrue(time_estimate::is_available());
    }
}
