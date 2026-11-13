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
}
