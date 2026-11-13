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

/**
 * English strings for the Teacher & Student Assistant.
 *
 * @package    local_darimaa
 * @category   string
 * @copyright  2026 Khuygbaatar Ochirkhuyag
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

$string['anonymous'] = 'Anonymous submissions';
$string['back'] = 'Back to all assignments';
$string['darimaa:botaccess'] = 'Use the assistant bot\'s web services (only for the bot\'s service account)';
$string['darimaa:sendreminders'] = 'Send reminders to students who have not submitted';
$string['defaultmessage'] = 'Default reminder message';
$string['defaultmessage_desc'] = 'The text teachers start from. They can change it before sending. You can use these placeholders: {firstname}, {lastname}, {assignment}, {course}, {duedate}, {link}.';
$string['defaultmessage_text'] = 'Hello {firstname},

We have not received your submission for "{assignment}" in {course} yet.

Due date: {duedate}
Submit here: {link}';
$string['duedate'] = 'Due date';
$string['duedateis'] = 'Due date: {$a}';
$string['eventreminderssent'] = 'Reminders sent';
$string['linkaccount'] = 'Connect Messenger';
$string['linkcode'] = 'Your code';
$string['linkcodeinstructions'] = 'Send this code to the Teacher & Student Assistant in Messenger. It works once and expires in {$a} minutes.';
$string['linked'] = 'Your Messenger account is connected to the Teacher & Student Assistant.';
$string['linkheading'] = 'Messenger connection';
$string['linkintro'] = 'Connect your Messenger account to ask the Teacher & Student Assistant about your deadlines. Get a code here, then send it to the assistant in Messenger.';
$string['message'] = 'Message';
$string['message_help'] = 'These placeholders are replaced for each student: {firstname}, {lastname}, {assignment}, {course}, {duedate} and {link}. The due date includes any extension or override the student has.';
$string['messageprovider:reminder'] = 'Assignment reminders from your teacher';
$string['messengerlink'] = 'Messenger link';
$string['messengerlink_desc'] = 'The m.me link of the Facebook Page where the assistant bot answers, for example https://m.me/yourpage. Users then get a button that opens the chat with their code. Leave empty to show only the code.';
$string['missingcount'] = 'Not submitted';
$string['missingsubmissions'] = 'Missing submissions';
$string['newcode'] = 'Get a new code';
$string['noassignments'] = 'There are no assignments in this course that you can send reminders for.';
$string['noduedate'] = 'Not set';
$string['nogroupaccess'] = 'You are not a member of any group in this assignment, so there are no students to show.';
$string['nomissing'] = 'Everyone has submitted this assignment.';
$string['notlinked'] = 'This chat account is not connected to a Moodle user.';
$string['nousersselected'] = 'Select at least one student.';
$string['openmessenger'] = 'Open Messenger';
$string['pluginname'] = 'Teacher & Student Assistant';
$string['privacy:links'] = 'Chat app connections';
$string['privacy:metadata:chatapps'] = 'The assistant bot answers users in chat apps such as Messenger. To answer, it sends some of the user\'s Moodle data to the chat app.';
$string['privacy:metadata:chatapps:deadlines'] = 'Names and due dates of the activities the user still has to do.';
$string['privacy:metadata:chatapps:firstname'] = 'The user\'s first name, used to greet them.';
$string['privacy:metadata:coremessage'] = 'Reminders are sent as Moodle notifications.';
$string['privacy:metadata:links'] = 'Which chat app account belongs to which user.';
$string['privacy:metadata:links:channel'] = 'The chat app, for example Messenger.';
$string['privacy:metadata:links:externalid'] = 'The account id the chat app gives the user.';
$string['privacy:metadata:links:timecreated'] = 'When the user first asked for a link code.';
$string['privacy:metadata:links:userid'] = 'The user.';
$string['reminderssent'] = 'Reminders sent: {$a}';
$string['remindersubject'] = 'Reminder: {$a}';
$string['remindheading'] = 'Missing submissions: {$a}';
$string['selectstudents'] = 'Students who have not submitted';
$string['sendreminders'] = 'Send reminders';
$string['studentlastaccess'] = '{$a->name} (last access to the course: {$a->lastaccess})';
$string['unlink'] = 'Disconnect Messenger';
$string['unlinked'] = 'Your Messenger account was disconnected.';
$string['unsupportedanonymous'] = 'Reminders are not available while this assignment hides student identities (anonymous submissions).';
