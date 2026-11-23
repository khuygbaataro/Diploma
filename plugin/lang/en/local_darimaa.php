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
$string['app_messenger'] = 'Messenger';
$string['app_telegram'] = 'Telegram';
$string['atrisk'] = 'Students who need attention';
$string['atrisk_days'] = 'Away after';
$string['atrisk_daysago'] = '{$a} days ago';
$string['atrisk_daysoption'] = '{$a} days';
$string['atrisk_hours'] = '≈ {$a} h';
$string['atrisk_intro'] = 'Students with at least one warning sign: no visit to the course for {$a->days} days or more, an assignment past its due date and not submitted, a closed quiz they did not attempt, or quiz results below {$a->percent}%. The signs are hints for a conversation, not a judgement.';
$string['atrisk_lastaccess'] = 'Last visit';
$string['atrisk_missing'] = 'Missing assignments';
$string['atrisk_nolastaccess'] = 'This site hides when students last visited a course, so visits are not checked.';
$string['atrisk_none'] = 'No student shows a warning sign.';
$string['atrisk_notattempted'] = 'Skipped quizzes';
$string['atrisk_note'] = 'Time in the course is an estimate from the site\'s logs. Only quizzes that at least one student finished are looked at, and a quiz counts as skipped only after it has closed.';
$string['atrisk_quizaverage'] = 'Quiz average';
$string['atrisk_showall'] = 'Show all students';
$string['atrisk_showflagged'] = 'Show only students with warning signs';
$string['atrisk_signals'] = 'Warning signs';
$string['atrisk_student'] = 'Student';
$string['atrisk_time'] = 'Time in the course';
$string['atrisk_weaktopics'] = 'Weak topics';
$string['back'] = 'Back to all assignments';
$string['channel'] = 'Send as';
$string['channel_both'] = 'Both: Moodle notification and chat message';
$string['channel_chat'] = 'Moodle chat message from you';
$string['channel_help'] = 'A Moodle notification shows under the bell, and the student also gets it by email when their notification settings allow it. A chat message comes from you in the student\'s Moodle chat, where the student can answer you. Moodle only lets you message students whose privacy settings allow it.';
$string['channel_notification'] = 'Moodle notification (bell and email)';
$string['chatnotpossible'] = 'Moodle chat messages cannot be sent: site messaging is off, or you may not send messages.';
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
$string['linkaccount'] = 'Connect {$a}';
$string['linkcode'] = 'Your code';
$string['linkcodeinstructions'] = 'Send this code to the Teacher & Student Assistant in {$a->app}. It works once and expires in {$a->minutes} minutes.';
$string['linked'] = 'Your {$a} account is connected to the Teacher & Student Assistant.';
$string['linkheading'] = 'Chat app connection';
$string['linkintro'] = 'Connect a chat app to ask the Teacher & Student Assistant about your deadlines. Get a code here, then send it to the assistant in the chat app.';
$string['message'] = 'Message';
$string['message_help'] = 'These placeholders are replaced for each student: {firstname}, {lastname}, {assignment}, {course}, {duedate} and {link}. The due date includes any extension or override the student has.';
$string['messagelength'] = 'The message must have between 1 and {$a} characters.';
$string['messageprovider:reminder'] = 'Assignment reminders from your teacher';
$string['messagerefused'] = 'Moodle did not send the message. The student may not accept messages from you.';
$string['messengerlink'] = 'Messenger link';
$string['messengerlink_desc'] = 'The m.me link of the Facebook Page where the assistant bot answers, for example https://m.me/yourpage. Users then get a button that opens the chat with their code. Leave empty to show only the code. When a Telegram bot is set and this is empty, Messenger is not offered.';
$string['missingcount'] = 'Not submitted';
$string['missingsubmissions'] = 'Missing submissions';
$string['newcode'] = 'Get a new code';
$string['noassignments'] = 'There are no assignments in this course that you can send reminders for.';
$string['noduedate'] = 'Not set';
$string['nogroupaccess'] = 'You are not a member of any group in this assignment, so there are no students to show.';
$string['nomissing'] = 'Everyone has submitted this assignment.';
$string['notlinked'] = 'This chat account is not connected to a Moodle user.';
$string['notyourstudent'] = 'This student is not one of the students you can see in this course.';
$string['nousersselected'] = 'Select at least one student.';
$string['openapp'] = 'Open {$a}';
$string['pluginname'] = 'Teacher & Student Assistant';
$string['privacy:links'] = 'Chat app connections';
$string['privacy:metadata:aiservice'] = 'When the site turns on the AI helper of the assistant bot, the bot sends an AI service (for example OpenAI) what it needs to answer a free question. Students are replaced by labels such as S1: their names, student codes and email addresses are never sent.';
$string['privacy:metadata:aiservice:question'] = 'The question the user typed in the chat app.';
$string['privacy:metadata:aiservice:reports'] = 'Course, quiz and topic names and the numbers of the teacher reports, with labels in place of students.';
$string['privacy:metadata:bot'] = 'The assistant bot runs on its own server and reads Moodle data through web service functions. It gets data about users who connected their chat app account. A connected teacher can also ask for reports about the students of their own courses, whether or not those students use the bot.';
$string['privacy:metadata:bot:courseactivity'] = 'For a teacher\'s report: when each student last accessed the course, an estimate of the time the student spent in it, and the number of assignments past their due date that the student has not submitted, and the warning signs of the list of students who need attention.';
$string['privacy:metadata:bot:courses'] = 'For teachers: names of their courses and quizzes, with the number of students and the number of students who finished each quiz.';
$string['privacy:metadata:bot:deadlines'] = 'Names, course names, due dates and links of the activities the user still has to do.';
$string['privacy:metadata:bot:firstname'] = 'The user\'s first name, used to greet them.';
$string['privacy:metadata:bot:fullname'] = 'For a teacher\'s report: the full names of the students of the course.';
$string['privacy:metadata:bot:isteacher'] = 'Whether the user can send reminders in at least one course.';
$string['privacy:metadata:bot:missing'] = 'For teachers: names, course names and due dates of their assignments, with the number of students who have not submitted. No student names.';
$string['privacy:metadata:bot:quizmarks'] = 'For a teacher\'s report: each student\'s result in a quiz, for the whole quiz and by topic, the number of answers that wait for grading, the quiz average, the weak topics and the number of closed quizzes the student did not attempt.';
$string['privacy:metadata:bot:studentcode'] = 'For a teacher\'s report: the student code of each student, from the user field chosen in the plugin settings.';
$string['privacy:metadata:bot:studentid'] = 'For a teacher\'s message: the Moodle user id of each student whose name matches what the teacher asked for, so that the message reaches the right student.';
$string['privacy:metadata:bot:timezone'] = 'The user\'s time zone, used to show due dates in local time.';
$string['privacy:metadata:chatapps'] = 'The assistant bot answers users in chat apps such as Telegram and Messenger. To answer, it sends some Moodle data to the chat app: data about the user and, for teachers, reports about the students of their courses.';
$string['privacy:metadata:chatapps:courseactivity'] = 'For a teacher\'s report: when each student last accessed the course, an estimate of the time the student spent in it, and the number of assignments past their due date that the student has not submitted, and the warning signs of the list of students who need attention.';
$string['privacy:metadata:chatapps:courses'] = 'For teachers: names of their courses and quizzes, with the number of students and the number of students who finished each quiz.';
$string['privacy:metadata:chatapps:deadlines'] = 'Names, course names, due dates and links of the activities the user still has to do.';
$string['privacy:metadata:chatapps:firstname'] = 'The user\'s first name, used to greet them.';
$string['privacy:metadata:chatapps:fullname'] = 'For a teacher\'s report: the full names of the students of the course.';
$string['privacy:metadata:chatapps:missing'] = 'For teachers: names, course names and due dates of their assignments, with the number of students who have not submitted. No student names.';
$string['privacy:metadata:chatapps:quizmarks'] = 'For a teacher\'s report: each student\'s result in a quiz, for the whole quiz and by topic, the number of answers that wait for grading, the quiz average, the weak topics and the number of closed quizzes the student did not attempt.';
$string['privacy:metadata:chatapps:studentcode'] = 'For a teacher\'s report: the student code of each student, from the user field chosen in the plugin settings.';
$string['privacy:metadata:coremessage'] = 'Reminders are sent as Moodle notifications, as Moodle chat messages from the teacher, or both.';
$string['privacy:metadata:links'] = 'Which chat app account belongs to which user.';
$string['privacy:metadata:links:channel'] = 'The chat app, for example Telegram or Messenger.';
$string['privacy:metadata:links:code'] = 'The one-time code the user sends to the bot to connect. It is cleared once it has been used.';
$string['privacy:metadata:links:codeexpires'] = 'When the one-time code stops working.';
$string['privacy:metadata:links:externalid'] = 'The account id the chat app gives the user.';
$string['privacy:metadata:links:timecreated'] = 'When the user first asked for a link code.';
$string['privacy:metadata:links:timemodified'] = 'When the link or its code last changed.';
$string['privacy:metadata:links:userid'] = 'The user.';
$string['reminderchannel'] = 'How reminders are sent';
$string['reminderchannel_both'] = 'Always both: Moodle notification and chat message';
$string['reminderchannel_chat'] = 'Always as a Moodle chat message from the teacher';
$string['reminderchannel_desc'] = 'A Moodle notification shows under the bell and is also emailed when the student\'s settings allow it. A chat message comes from the teacher in the student\'s Moodle chat, where the student can answer. When chat is not possible (messaging is off, or the teacher may not send messages), reminders go as notifications. Reminders sent by the assistant bot follow this setting too; with "The teacher chooses each time" they go as notifications.';
$string['reminderchannel_notification'] = 'Always as a Moodle notification';
$string['reminderchannel_teacher'] = 'The teacher chooses each time';
$string['reminderschatrefused'] = '{$a} students cannot get a chat message from you, because of their privacy settings or the site\'s messaging settings.';
$string['reminderssent'] = 'Reminders sent: {$a}';
$string['reminderssentincomplete'] = 'Reminders sent: {$a->sent}. All of them are in the students\' Moodle notifications, but {$a->incomplete} could not be delivered by every channel, for example by email. Check the site\'s outgoing mail settings. Do not send these reminders again.';
$string['remindersubject'] = 'Reminder: {$a}';
$string['remindheading'] = 'Missing submissions: {$a}';
$string['selectstudents'] = 'Students who have not submitted';
$string['sendreminders'] = 'Send reminders';
$string['sessiontimeout'] = 'Session timeout (minutes)';
$string['sessiontimeout_desc'] = 'Used to estimate how long a student worked in a course. The time between two actions of a student counts only when it is shorter than this many minutes. A longer pause starts a new session.';
$string['signal_inactive'] = 'Away {$a} days or more';
$string['signal_lowquiz'] = 'Low quiz results';
$string['signal_missing'] = 'Missing work';
$string['signal_never'] = 'Never visited';
$string['signal_notattempted'] = 'Skipped a quiz';
$string['signal_weaktopic'] = 'Weak topic';
$string['studentcodefield'] = 'Student code field';
$string['studentcodefield_desc'] = 'The user field that holds the student code teachers know a student by. Reports for teachers show this code next to the student\'s name.';
$string['studentlastaccess'] = '{$a->name} (last access to the course: {$a->lastaccess})';
$string['telegrambot'] = 'Telegram bot';
$string['telegrambot_desc'] = 'The username of the Telegram bot where the assistant answers, without the @, for example school_helper_bot. Users can then connect Telegram and get a button that opens the chat with their code. Leave empty to turn Telegram off.';
$string['unlink'] = 'Disconnect {$a}';
$string['unlinked'] = 'Your {$a} account was disconnected.';
$string['unsupportedanonymous'] = 'Reminders are not available while this assignment hides student identities (anonymous submissions).';
