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

namespace local_darimaa\event;

/**
 * Event triggered when a teacher sends assignment reminders.
 *
 * @property-read array $other {
 *      Extra information about the event.
 *
 *      - int count: number of reminders sent.
 * }
 *
 * @package    local_darimaa
 * @copyright  2026 Khuygbaatar Ochirkhuyag
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class reminders_sent extends \core\event\base {
    /**
     * Sets the basic event properties.
     */
    protected function init() {
        $this->data['crud'] = 'c';
        $this->data['edulevel'] = self::LEVEL_TEACHING;
    }

    /**
     * Returns the localised event name.
     *
     * @return string
     */
    public static function get_name() {
        return get_string('eventreminderssent', 'local_darimaa');
    }

    /**
     * Returns a non-localised description of what happened.
     *
     * @return string
     */
    public function get_description() {
        return "The user with id '{$this->userid}' sent {$this->other['count']} reminder(s) for the assignment " .
            "with course module id '{$this->contextinstanceid}'.";
    }

    /**
     * Returns the URL of the reminder page.
     *
     * @return \core\url
     */
    public function get_url() {
        return new \core\url('/local/darimaa/remind.php', ['cmid' => $this->contextinstanceid]);
    }

    /**
     * Checks that the event has the data it needs.
     *
     * @throws \core\exception\coding_exception
     */
    protected function validate_data() {
        parent::validate_data();

        if (!isset($this->other['count'])) {
            throw new \core\exception\coding_exception('The \'count\' value must be set in other.');
        }
        if ($this->contextlevel != CONTEXT_MODULE) {
            throw new \core\exception\coding_exception('Context level must be CONTEXT_MODULE.');
        }
    }

    /**
     * The "other" data holds no ids, so nothing needs mapping during restore.
     *
     * @return bool
     */
    public static function get_other_mapping() {
        return false;
    }
}
