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
use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\approved_userlist;
use core_privacy\local\request\contextlist;
use core_privacy\local\request\transform;
use core_privacy\local\request\userlist;
use core_privacy\local\request\writer;

/**
 * Privacy provider for the Teacher & Student Assistant.
 *
 * The plugin stores which chat app account belongs to which user. Reminders are
 * stored by the Moodle messaging system and the event log. The reports for teachers
 * store nothing: they read course and quiz data and send it to the bot.
 *
 * @package    local_darimaa
 * @copyright  2026 Khuygbaatar Ochirkhuyag
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class provider implements
    \core_privacy\local\metadata\provider,
    \core_privacy\local\request\core_userlist_provider,
    \core_privacy\local\request\plugin\provider {
    /**
     * Describes the personal data the plugin stores or sends out.
     *
     * @param collection $collection The collection to add to.
     * @return collection
     */
    public static function get_metadata(collection $collection): collection {
        $collection->add_database_table('local_darimaa_links', [
            'userid' => 'privacy:metadata:links:userid',
            'channel' => 'privacy:metadata:links:channel',
            'externalid' => 'privacy:metadata:links:externalid',
            'code' => 'privacy:metadata:links:code',
            'codeexpires' => 'privacy:metadata:links:codeexpires',
            'timecreated' => 'privacy:metadata:links:timecreated',
            'timemodified' => 'privacy:metadata:links:timemodified',
        ], 'privacy:metadata:links');

        // What the web service functions give to the assistant bot's server.
        $collection->add_external_location_link('bot', [
            'firstname' => 'privacy:metadata:bot:firstname',
            'timezone' => 'privacy:metadata:bot:timezone',
            'isteacher' => 'privacy:metadata:bot:isteacher',
            'deadlines' => 'privacy:metadata:bot:deadlines',
            'missing' => 'privacy:metadata:bot:missing',
            // The reports a linked teacher can ask for are about the students of their courses.
            'courses' => 'privacy:metadata:bot:courses',
            'studentcode' => 'privacy:metadata:bot:studentcode',
            'fullname' => 'privacy:metadata:bot:fullname',
            'courseactivity' => 'privacy:metadata:bot:courseactivity',
            'quizmarks' => 'privacy:metadata:bot:quizmarks',
        ], 'privacy:metadata:bot');

        // What the bot passes on to the chat app when it answers.
        $collection->add_external_location_link('chatapps', [
            'firstname' => 'privacy:metadata:chatapps:firstname',
            'deadlines' => 'privacy:metadata:chatapps:deadlines',
            'missing' => 'privacy:metadata:chatapps:missing',
            'courses' => 'privacy:metadata:chatapps:courses',
            'studentcode' => 'privacy:metadata:chatapps:studentcode',
            'fullname' => 'privacy:metadata:chatapps:fullname',
            'courseactivity' => 'privacy:metadata:chatapps:courseactivity',
            'quizmarks' => 'privacy:metadata:chatapps:quizmarks',
        ], 'privacy:metadata:chatapps');

        $collection->add_subsystem_link('core_message', [], 'privacy:metadata:coremessage');

        return $collection;
    }

    /**
     * Returns the contexts with data about a user.
     *
     * @param int $userid The user.
     * @return contextlist
     */
    public static function get_contexts_for_userid(int $userid): contextlist {
        $contextlist = new contextlist();
        $contextlist->add_from_sql(
            "SELECT ctx.id
               FROM {local_darimaa_links} l
               JOIN {context} ctx ON ctx.instanceid = l.userid AND ctx.contextlevel = :contextlevel
              WHERE l.userid = :userid",
            ['contextlevel' => CONTEXT_USER, 'userid' => $userid]
        );
        return $contextlist;
    }

    /**
     * Adds the users who have data in a context.
     *
     * @param userlist $userlist The list to add to.
     */
    public static function get_users_in_context(userlist $userlist): void {
        $context = $userlist->get_context();
        if (!$context instanceof \core\context\user) {
            return;
        }
        $userlist->add_from_sql(
            'userid',
            'SELECT userid FROM {local_darimaa_links} WHERE userid = :userid',
            ['userid' => $context->instanceid]
        );
    }

    /**
     * Exports a user's chat app links.
     *
     * @param approved_contextlist $contextlist The approved contexts.
     */
    public static function export_user_data(approved_contextlist $contextlist): void {
        global $DB;

        $userid = (int) $contextlist->get_user()->id;
        foreach ($contextlist->get_contexts() as $context) {
            if (!$context instanceof \core\context\user || (int) $context->instanceid !== $userid) {
                continue;
            }

            $links = [];
            foreach ($DB->get_records('local_darimaa_links', ['userid' => $userid], 'channel') as $link) {
                $links[] = (object) [
                    'channel' => $link->channel,
                    'externalid' => (string) $link->externalid,
                    'timecreated' => transform::datetime($link->timecreated),
                    'timemodified' => transform::datetime($link->timemodified),
                ];
            }
            if ($links) {
                writer::with_context($context)->export_data(
                    [get_string('privacy:links', 'local_darimaa')],
                    (object) ['links' => $links]
                );
            }
        }
    }

    /**
     * Deletes all data in a context.
     *
     * @param \context $context The context.
     */
    public static function delete_data_for_all_users_in_context(\context $context): void {
        global $DB;

        if ($context instanceof \core\context\user) {
            $DB->delete_records('local_darimaa_links', ['userid' => $context->instanceid]);
        }
    }

    /**
     * Deletes a user's data in the approved contexts.
     *
     * @param approved_contextlist $contextlist The approved contexts.
     */
    public static function delete_data_for_user(approved_contextlist $contextlist): void {
        global $DB;

        $userid = (int) $contextlist->get_user()->id;
        foreach ($contextlist->get_contexts() as $context) {
            if ($context instanceof \core\context\user && (int) $context->instanceid === $userid) {
                $DB->delete_records('local_darimaa_links', ['userid' => $userid]);
            }
        }
    }

    /**
     * Deletes the data of several users in a context.
     *
     * @param approved_userlist $userlist The approved users.
     */
    public static function delete_data_for_users(approved_userlist $userlist): void {
        global $DB;

        $context = $userlist->get_context();
        if ($context instanceof \core\context\user && in_array((int) $context->instanceid, $userlist->get_userids())) {
            $DB->delete_records('local_darimaa_links', ['userid' => $context->instanceid]);
        }
    }
}
