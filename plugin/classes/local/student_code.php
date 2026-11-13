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

/**
 * Reads the student code from the field the site chose in the plugin settings.
 *
 * The code is what teachers know a student by, for example the number on the student card.
 * Sites keep it in the username, in the ID number or in a custom profile field.
 *
 * @package    local_darimaa
 * @copyright  2026 Khuygbaatar Ochirkhuyag
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class student_code {
    /** @var string The field used when nothing valid is set. */
    public const DEFAULT_FIELD = 'username';

    /** @var string Start of the setting value that names a custom profile field. */
    public const PROFILE_PREFIX = 'profile_field_';

    /**
     * Lists the fields that can hold the student code.
     *
     * @return string[] Field labels keyed by setting value.
     */
    public static function get_choices(): array {
        global $DB;

        $choices = [
            'username' => get_string('username'),
            'idnumber' => get_string('idnumber'),
        ];
        $fields = $DB->get_records('user_info_field', null, 'categoryid, sortorder, id', 'id, shortname, name');
        foreach ($fields as $field) {
            $choices[self::PROFILE_PREFIX . $field->shortname] = format_string(
                $field->name,
                true,
                ['context' => \core\context\system::instance()]
            );
        }
        return $choices;
    }

    /**
     * Returns the field chosen in the settings, or the username when the choice no longer exists.
     *
     * @return string A key of {@see self::get_choices()}.
     */
    public static function get_field(): string {
        global $DB;

        $field = (string) get_config('local_darimaa', 'studentcodefield');
        if ($field === 'username' || $field === 'idnumber') {
            return $field;
        }
        if (str_starts_with($field, self::PROFILE_PREFIX)) {
            $shortname = substr($field, strlen(self::PROFILE_PREFIX));
            if ($DB->record_exists('user_info_field', ['shortname' => $shortname])) {
                return $field;
            }
        }
        return self::DEFAULT_FIELD;
    }

    /**
     * Returns the student codes of several users with one query.
     *
     * @param int[] $userids The users.
     * @return string[] Codes keyed by user id. A user without a code gets an empty text.
     */
    public static function get_for_users(array $userids): array {
        global $DB;

        if (!$userids) {
            return [];
        }

        $field = self::get_field();
        [$insql, $params] = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED, 'codeuser');

        if (str_starts_with($field, self::PROFILE_PREFIX)) {
            $params['shortname'] = substr($field, strlen(self::PROFILE_PREFIX));
            $found = $DB->get_records_sql_menu(
                "SELECT d.userid, d.data
                   FROM {user_info_data} d
                   JOIN {user_info_field} f ON f.id = d.fieldid
                  WHERE f.shortname = :shortname AND d.userid $insql",
                $params
            );
        } else {
            $found = $DB->get_records_select_menu('user', "id $insql", $params, '', "id, $field");
        }

        $codes = [];
        foreach ($userids as $userid) {
            $codes[(int) $userid] = trim((string) ($found[$userid] ?? ''));
        }
        return $codes;
    }
}
