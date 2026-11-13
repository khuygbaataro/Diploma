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

use PHPUnit\Framework\Attributes\CoversNothing;

/**
 * Guards the web service definition that the bot's token depends on.
 *
 * Moodle finds a plugin's service by its name when the plugin is upgraded. If the
 * name changes, Moodle deletes the old service together with the bot's tokens.
 *
 * @package    local_darimaa
 * @category   test
 * @copyright  2026 Khuygbaatar Ochirkhuyag
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversNothing]
final class services_test extends \advanced_testcase {
    /**
     * The service keeps its name and short name, in the definition and once installed.
     */
    public function test_service_name_and_shortname_do_not_change(): void {
        global $DB;

        $functions = [];
        $services = [];
        require(\core_component::get_plugin_directory('local', 'darimaa') . '/db/services.php');

        $this->assertSame(['Darimaa bot'], array_keys($services));
        $this->assertSame('local_darimaa_bot', $services['Darimaa bot']['shortname']);
        $this->assertEqualsCanonicalizing(array_keys($functions), $services['Darimaa bot']['functions']);

        $installed = $DB->get_records('external_services', ['component' => 'local_darimaa']);
        $this->assertCount(1, $installed);
        $service = reset($installed);
        $this->assertSame('Darimaa bot', $service->name);
        $this->assertSame('local_darimaa_bot', $service->shortname);
    }

    /**
     * The service offers nine functions, all only for the bot. The three reports for teachers are read-only.
     */
    public function test_service_has_nine_functions(): void {
        global $DB;

        $functions = [];
        $services = [];
        require(\core_component::get_plugin_directory('local', 'darimaa') . '/db/services.php');

        $expected = [
            'local_darimaa_get_user' => 'read',
            'local_darimaa_link_account' => 'write',
            'local_darimaa_unlink_account' => 'write',
            'local_darimaa_get_deadlines' => 'read',
            'local_darimaa_get_missing' => 'read',
            'local_darimaa_send_reminders' => 'write',
            'local_darimaa_get_courses' => 'read',
            'local_darimaa_get_inactive_students' => 'read',
            'local_darimaa_get_quiz_topic_results' => 'read',
        ];
        $this->assertCount(9, $services['Darimaa bot']['functions']);
        $this->assertSame(array_keys($expected), $services['Darimaa bot']['functions']);
        $this->assertSame($expected, array_map(static fn(array $function): string => $function['type'], $functions));
        foreach ($functions as $name => $function) {
            $this->assertSame('local/darimaa:botaccess', $function['capabilities'], $name);
            $this->assertTrue(class_exists($function['classname']), $name);
        }

        // Moodle installed all nine and put them in the service.
        $service = $DB->get_record('external_services', ['shortname' => 'local_darimaa_bot'], '*', MUST_EXIST);
        $inservice = $DB->get_fieldset_select(
            'external_services_functions',
            'functionname',
            'externalserviceid = :serviceid',
            ['serviceid' => $service->id]
        );
        $this->assertEqualsCanonicalizing(array_keys($expected), $inservice);
        $this->assertSame(9, $DB->count_records('external_functions', ['component' => 'local_darimaa']));
    }
}
