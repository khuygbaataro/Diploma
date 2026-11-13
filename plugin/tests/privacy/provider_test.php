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

namespace local_darimaa\privacy;

use core_privacy\local\metadata\collection;
use core_privacy\local\metadata\types\database_table;
use core_privacy\local\metadata\types\external_location;
use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\approved_userlist;
use core_privacy\local\request\userlist;
use core_privacy\local\request\writer;
use core_privacy\tests\provider_testcase;
use local_darimaa\external\get_inactive_students;
use local_darimaa\external\get_quiz_topic_results;
use local_darimaa\external\get_user;
use local_darimaa\external\link_account;
use local_darimaa\local\link_manager;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Tests for the privacy provider.
 *
 * @package    local_darimaa
 * @category   test
 * @copyright  2026 Khuygbaatar Ochirkhuyag
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversClass(provider::class)]
final class provider_test extends provider_testcase {
    /**
     * Creates two users with linked Messenger accounts.
     *
     * @return \stdClass[] The two users.
     */
    private function create_linked_users(): array {
        $users = [$this->getDataGenerator()->create_user(), $this->getDataGenerator()->create_user()];
        foreach ($users as $i => $user) {
            link_manager::link('messenger', link_manager::create_code($user->id, 'messenger'), 'psid-' . $i);
        }
        return $users;
    }

    /**
     * The metadata names every stored field and every item that leaves Moodle.
     */
    public function test_metadata_declares_stored_and_shared_data(): void {
        global $DB;

        $items = [];
        foreach (provider::get_metadata(new collection('local_darimaa'))->get_collection() as $item) {
            $items[$item->get_name()] = $item;
        }
        $this->assertEqualsCanonicalizing(['local_darimaa_links', 'bot', 'chatapps', 'core_message'], array_keys($items));

        // Every column of the link table except the record id.
        $this->assertInstanceOf(database_table::class, $items['local_darimaa_links']);
        $columns = array_diff(array_keys($DB->get_columns('local_darimaa_links')), ['id']);
        $this->assertEqualsCanonicalizing($columns, array_keys($items['local_darimaa_links']->get_privacy_fields()));

        // What the web service functions give to the bot's server, and what the bot passes on to the chat app.
        $this->assertInstanceOf(external_location::class, $items['bot']);
        $botfields = array_keys($items['bot']->get_privacy_fields());
        $teacherreports = ['courses', 'studentcode', 'fullname', 'courseactivity', 'quizmarks'];
        $this->assertEqualsCanonicalizing(
            array_merge(['firstname', 'timezone', 'isteacher', 'deadlines', 'missing'], $teacherreports),
            $botfields
        );
        $this->assertInstanceOf(external_location::class, $items['chatapps']);
        $chatfields = array_keys($items['chatapps']->get_privacy_fields());
        $this->assertEqualsCanonicalizing(array_merge(['firstname', 'deadlines', 'missing'], $teacherreports), $chatfields);

        // Every field the teacher reports return about a student is declared, for the bot and for the chat app.
        $declaredas = [
            'code' => 'studentcode',
            'fullname' => 'fullname',
            'lastaccess' => 'courseactivity',
            'dayssince' => 'courseactivity',
            'minutes' => 'courseactivity',
            'missing' => 'courseactivity',
            'inactive' => 'courseactivity',
            'percent' => 'quizmarks',
            'ungraded' => 'quizmarks',
            'topics' => 'quizmarks',
        ];
        foreach ([get_inactive_students::class, get_quiz_topic_results::class] as $function) {
            foreach (array_keys($function::execute_returns()->keys['students']->content->keys) as $field) {
                $this->assertArrayHasKey($field, $declaredas, $function);
                $this->assertContains($declaredas[$field], $botfields, $field);
                $this->assertContains($declaredas[$field], $chatfields, $field);
            }
        }

        // Every field about a person that the user functions return is declared for the bot.
        foreach ([get_user::class, link_account::class] as $function) {
            $returned = array_diff(array_keys($function::execute_returns()->keys), ['linked', 'linkurl']);
            $this->assertSame([], array_values(array_diff($returned, $botfields)), $function);
        }

        // Every description exists as a language string.
        $strings = get_string_manager();
        foreach ($items as $item) {
            $this->assertTrue($strings->string_exists($item->get_summary(), 'local_darimaa'), $item->get_summary());
            foreach ($item->get_privacy_fields() as $identifier) {
                $this->assertTrue($strings->string_exists($identifier, 'local_darimaa'), $identifier);
            }
        }
    }

    /**
     * The user context is found, exported and deleted for one user only.
     */
    public function test_export_and_delete_for_user(): void {
        global $DB;
        $this->resetAfterTest();
        [$user, $other] = $this->create_linked_users();
        $context = \core\context\user::instance($user->id);

        $contextlist = provider::get_contexts_for_userid($user->id);
        $this->assertEquals([$context->id], $contextlist->get_contextids());

        $approved = new approved_contextlist($user, 'local_darimaa', [$context->id]);
        provider::export_user_data($approved);
        $data = writer::with_context($context)->get_data([get_string('privacy:links', 'local_darimaa')]);
        $this->assertSame('messenger', $data->links[0]->channel);
        $this->assertSame('psid-0', $data->links[0]->externalid);

        provider::delete_data_for_user($approved);
        $this->assertFalse($DB->record_exists('local_darimaa_links', ['userid' => $user->id]));
        $this->assertTrue($DB->record_exists('local_darimaa_links', ['userid' => $other->id]));
    }

    /**
     * Users are found in their context and deleted in bulk.
     */
    public function test_userlist_and_context_delete(): void {
        global $DB;
        $this->resetAfterTest();
        [$user, $other] = $this->create_linked_users();
        $context = \core\context\user::instance($user->id);

        $userlist = new userlist($context, 'local_darimaa');
        provider::get_users_in_context($userlist);
        $this->assertEquals([$user->id], $userlist->get_userids());

        provider::delete_data_for_users(new approved_userlist($context, 'local_darimaa', [$user->id]));
        $this->assertFalse($DB->record_exists('local_darimaa_links', ['userid' => $user->id]));

        $othercontext = \core\context\user::instance($other->id);
        provider::delete_data_for_all_users_in_context($othercontext);
        $this->assertFalse($DB->record_exists('local_darimaa_links', ['userid' => $other->id]));
    }
}
