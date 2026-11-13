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
 * Links Moodle users to their chat app accounts, such as Messenger.
 *
 * A user asks for a one-time code in Moodle and sends it to the bot from the chat app.
 * The bot then confirms the link with the account id the chat app gave it, so no
 * password ever passes through the chat.
 *
 * @package    local_darimaa
 * @copyright  2026 Khuygbaatar Ochirkhuyag
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class link_manager {
    /** @var string[] Chat apps that can be linked. */
    public const CHANNELS = ['messenger', 'telegram'];

    /** @var int How long a code stays valid, in seconds. */
    public const CODE_LIFETIME = 15 * MINSECS;

    /** @var int Number of characters in a code. */
    public const CODE_LENGTH = 8;

    /** @var string Characters used in codes, leaving out look-alikes such as 0 and O, or 1 and I. */
    private const CODE_ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';

    /** @var string The table that holds the links. */
    private const TABLE = 'local_darimaa_links';

    /**
     * Throws an exception for a chat app that cannot be linked.
     *
     * @param string $channel The chat app.
     */
    public static function validate_channel(string $channel): void {
        if (!in_array($channel, self::CHANNELS, true)) {
            throw new \invalid_parameter_exception('Unknown channel: ' . $channel);
        }
    }

    /**
     * Creates a new code for a user and replaces their older code.
     *
     * An existing link keeps working until the new code is used.
     *
     * @param int $userid The user.
     * @param string $channel The chat app.
     * @return string The code.
     */
    public static function create_code(int $userid, string $channel): string {
        global $DB;

        self::validate_channel($channel);
        $code = self::generate_code();
        $now = time();

        $record = $DB->get_record(self::TABLE, ['userid' => $userid, 'channel' => $channel]);
        if ($record) {
            $record->code = $code;
            $record->codeexpires = $now + self::CODE_LIFETIME;
            $record->timemodified = $now;
            $DB->update_record(self::TABLE, $record);
        } else {
            $DB->insert_record(self::TABLE, (object) [
                'userid' => $userid,
                'channel' => $channel,
                'externalid' => null,
                'code' => $code,
                'codeexpires' => $now + self::CODE_LIFETIME,
                'timecreated' => $now,
                'timemodified' => $now,
            ]);
        }
        return $code;
    }

    /**
     * Turns what a user typed into a code: drops spaces and dashes, and uses capital letters.
     *
     * @param string $code The code as typed.
     * @return string
     */
    public static function normalise_code(string $code): string {
        return strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $code));
    }

    /**
     * Confirms a link: the chat account that sent a valid code is linked to the code's owner.
     *
     * A chat account belongs to one Moodle user at a time, so it is removed from anyone else.
     *
     * @param string $channel The chat app.
     * @param string $code The code the user sent.
     * @param string $externalid The account id the chat app gave the sender.
     * @return int|null The linked user id, or null when the code is wrong or has expired.
     */
    public static function link(string $channel, string $code, string $externalid): ?int {
        global $DB;

        self::validate_channel($channel);
        $code = self::normalise_code($code);
        if (strlen($code) !== self::CODE_LENGTH || $externalid === '') {
            return null;
        }

        $record = $DB->get_record_select(
            self::TABLE,
            'channel = :channel AND code = :code AND codeexpires >= :now',
            ['channel' => $channel, 'code' => $code, 'now' => time()]
        );
        if (!$record) {
            return null;
        }

        $transaction = $DB->start_delegated_transaction();
        $DB->delete_records_select(
            self::TABLE,
            'channel = :channel AND externalid = :externalid AND id <> :id',
            ['channel' => $channel, 'externalid' => $externalid, 'id' => $record->id]
        );
        $record->externalid = $externalid;
        $record->code = null;
        $record->codeexpires = 0;
        $record->timemodified = time();
        $DB->update_record(self::TABLE, $record);
        $transaction->allow_commit();

        return (int) $record->userid;
    }

    /**
     * Returns the active user a chat account is linked to.
     *
     * @param string $channel The chat app.
     * @param string $externalid The account id in the chat app.
     * @return \stdClass|null The full user record, or null when no active user is linked.
     */
    public static function get_user(string $channel, string $externalid): ?\stdClass {
        global $DB;

        self::validate_channel($channel);
        if ($externalid === '') {
            return null;
        }

        $userid = $DB->get_field(self::TABLE, 'userid', ['channel' => $channel, 'externalid' => $externalid], IGNORE_MULTIPLE);
        if (!$userid) {
            return null;
        }

        $user = \core_user::get_user($userid);
        if (!$user || !empty($user->deleted) || !empty($user->suspended)) {
            return null;
        }
        return $user;
    }

    /**
     * Returns a user's link record for a chat app.
     *
     * @param int $userid The user.
     * @param string $channel The chat app.
     * @return \stdClass|null The record, or null when the user never asked for a code.
     */
    public static function get_link(int $userid, string $channel): ?\stdClass {
        global $DB;

        self::validate_channel($channel);
        return $DB->get_record(self::TABLE, ['userid' => $userid, 'channel' => $channel]) ?: null;
    }

    /**
     * Removes the link of a chat account, as asked from the chat app.
     *
     * @param string $channel The chat app.
     * @param string $externalid The account id in the chat app.
     * @return bool Whether a link was removed.
     */
    public static function unlink(string $channel, string $externalid): bool {
        global $DB;

        self::validate_channel($channel);
        if ($externalid === '' || !$DB->record_exists(self::TABLE, ['channel' => $channel, 'externalid' => $externalid])) {
            return false;
        }
        $DB->delete_records(self::TABLE, ['channel' => $channel, 'externalid' => $externalid]);
        return true;
    }

    /**
     * Removes a user's link and code for a chat app, as asked from Moodle.
     *
     * @param int $userid The user.
     * @param string $channel The chat app.
     */
    public static function unlink_user(int $userid, string $channel): void {
        global $DB;

        self::validate_channel($channel);
        $DB->delete_records(self::TABLE, ['userid' => $userid, 'channel' => $channel]);
    }

    /**
     * Removes every link and code of a user, in all chat apps.
     *
     * @param int $userid The user.
     */
    public static function unlink_all(int $userid): void {
        global $DB;

        $DB->delete_records(self::TABLE, ['userid' => $userid]);
    }

    /**
     * Generates a code that no other user holds right now.
     *
     * Every code mixes letters and digits, so the bot can tell a code from an ordinary word.
     *
     * @return string
     */
    private static function generate_code(): string {
        global $DB;

        $last = strlen(self::CODE_ALPHABET) - 1;
        do {
            $code = '';
            for ($i = 0; $i < self::CODE_LENGTH; $i++) {
                $code .= self::CODE_ALPHABET[random_int(0, $last)];
            }
        } while (
            !preg_match('/\d/', $code)
            || !preg_match('/[A-Z]/', $code)
            || $DB->record_exists(self::TABLE, ['code' => $code])
        );

        return $code;
    }
}
