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
use local_darimaa\local\missing_submissions;
use local_darimaa\local\reminder_sender;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/mod/assign/locallib.php');

/**
 * Sends reminders, in a linked teacher's name, to students who have not submitted.
 *
 * @package    local_darimaa
 * @copyright  2026 Khuygbaatar Ochirkhuyag
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class send_reminders extends bot_function {
    /**
     * Describes the parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters(self::account_parameters() + [
            'cmid' => new external_value(PARAM_INT, 'Course module id of the assignment'),
        ]);
    }

    /**
     * Sends the site's default reminder to every student the teacher sees as missing.
     *
     * @param string $channel The chat app.
     * @param string $externalid The teacher's account id in the chat app.
     * @param int $cmid Course module id of the assignment.
     * @return array
     */
    public static function execute(string $channel, string $externalid, int $cmid): array {
        [
            'channel' => $channel,
            'externalid' => $externalid,
            'cmid' => $cmid,
        ] = self::validate_parameters(self::execute_parameters(), [
            'channel' => $channel,
            'externalid' => $externalid,
            'cmid' => $cmid,
        ]);
        self::require_bot($channel);

        $teacher = link_manager::get_user($channel, $externalid);
        if (!$teacher) {
            throw new \core\exception\moodle_exception('notlinked', 'local_darimaa');
        }

        [$course, $cm] = get_course_and_cm_from_cmid($cmid, 'assign', 0, $teacher->id);
        $context = \core\context\module::instance($cm->id);
        if (!$cm->uservisible) {
            throw new \core\exception\moodle_exception('nopermissions', 'error', '', get_string('sendreminders', 'local_darimaa'));
        }
        if (!has_capability('local/darimaa:sendreminders', $context, $teacher)) {
            throw new \core\exception\required_capability_exception(
                $context,
                'local/darimaa:sendreminders',
                'nopermissions',
                ''
            );
        }

        $assign = new \assign($context, $cm, $course);
        if (!missing_submissions::is_supported($assign)) {
            throw new \core\exception\moodle_exception('unsupportedanonymous', 'local_darimaa');
        }

        $missing = missing_submissions::get_missing_users_for($assign, $teacher);
        $template = get_config('local_darimaa', 'defaultmessage');
        if (trim((string) $template) === '') {
            $template = get_string('defaultmessage_text', 'local_darimaa');
        }

        $sent = $missing ? reminder_sender::send($assign, $missing, $template, $teacher) : 0;

        return ['sent' => $sent, 'missing' => count($missing)];
    }

    /**
     * Describes the result.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'sent' => new external_value(PARAM_INT, 'How many reminders were sent'),
            'missing' => new external_value(PARAM_INT, 'How many students had not submitted'),
        ]);
    }
}
