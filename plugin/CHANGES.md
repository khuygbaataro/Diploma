# Changes

## 0.4.1 (2026-11-18)

The assistant bot can show the list of students who need attention in the chat. Read-only: nothing
is sent to students and nothing changes. No database changes; the nine existing web service
functions keep their parameters and replies.

- New web service function `local_darimaa_get_attention`: for a linked teacher, the students of a
  course with at least one warning sign, the signs, and the numbers behind them (days away,
  missing assignments, skipped quizzes, quiz average, weak topics). The same checks as on the
  page: the teacher must be able to send reminders and see the participants, and groups, the
  hidden "last access" field and quiz report access are respected. The estimated time is not sent.
- The service "Darimaa bot" now has ten functions. Its name is unchanged.
- The privacy metadata names the new data that the bot and the chat app get.

## 0.4.0 (2026-11-18)

A page in Moodle that shows which students may need the teacher's attention, and why. Read-only:
nothing is sent and nothing changes. No database changes; the web service functions keep their
parameters and replies.

- New page "Students who need attention" in the course menu, for teachers who can send reminders
  and see the participants. Each student gets a warning sign for: never visited the course, no
  visit for 7, 14 or 30 days (the teacher picks), an assignment past its due date and not
  submitted, a closed quiz not attempted, a quiz average below 60%, or a quiz topic below 60%.
- The page shows the student code and name, last visit, estimated time in the course, missing
  assignments, skipped quizzes, quiz average and weak topics. Students with the most signs come
  first. The signs are hints for a conversation; there is no hidden score.
- Separate groups, the hidden "last access" field, report access to each quiz and accounts that
  cannot log in are respected, as in the other reports.

## 0.3.3 (2026-11-18)

A site setting decides how reminders are sent. No database changes, and the web service functions
keep their parameters and replies.

- New setting "How reminders are sent": the teacher chooses each time (default, as in 0.3.2),
  always as a Moodle chat message from the teacher, always as a Moodle notification, or always
  both. With a fixed choice the reminder page shows how the reminders will go instead of a choice.
- When chat is not possible (site messaging is off, or the teacher may not send messages), the
  reminders go as notifications, so that they still reach the students.
- Reminders sent through the assistant bot follow the setting too. With "The teacher chooses each
  time" they go as notifications, as before.

## 0.3.2 (2026-11-18)

Reminders can go as Moodle chat messages, and the reminder count tells the truth when email fails.
No database changes, no new settings, and the web service functions keep their parameters and
replies.

- On the reminder page the teacher chooses how the reminders go: as a Moodle notification (the bell,
  and email when the student's settings allow it), as a Moodle chat message from the teacher, or
  both. In the chat the student can answer the teacher. Moodle decides who may get a chat message:
  the teacher needs the capability to send messages, and each student's privacy settings count.
  The page says how many students could not get a chat message. The choice only shows when
  messaging is turned on for the site.

- Moodle saves a reminder as a notification first, and then emails it. When the email fails,
  Moodle reports the whole send as failed, although the student already sees the notification in
  Moodle. Such reminders were counted as not sent, so the page said "Reminders sent: 0" and
  teachers could send them again. They now count as sent.
- The reminder page warns when some reminders could not be delivered by every channel (for example
  email), tells the teacher to check the site's outgoing mail settings and not to send them again.
- `local_darimaa_send_reminders` counts the same way, so the bot reports the real number.

## 0.3.1 (2026-11-15)

Accounts that cannot log in are left out everywhere, with the same result on Moodle 5.0 and 5.3.
No database changes, no new settings, and the web service functions keep their parameters and
replies.

- One definition of a student for the reports for teachers: an active enrolment, a gradebook role
  and an account that is not suspended and not of the type "No login". The inactive students
  report, the quiz results by topic and the student and finished counts of the course list no
  longer include suspended and "No login" accounts.
- Missing submissions and reminders leave the same accounts out on every Moodle version: on the
  Moodle pages and in `local_darimaa_get_missing`, `local_darimaa_send_reminders` and the `missing`
  count of `local_darimaa_get_inactive_students`. Before, Moodle 5.0 left them out and later
  versions listed them and sent them reminders.
- README: new section "Who counts as a student".

## 0.3.0 (2026-11-13)

Reports for teachers. Everything in this release only reads data: nothing is sent to students and
nothing changes in Moodle. No database changes; existing links and the bot's token are kept.

- New web service function `local_darimaa_get_courses`: a linked teacher's courses with their
  quizzes, the number of students and the number of students who finished each quiz.
- New web service function `local_darimaa_get_inactive_students`: for each student of a course
  the last access, an estimate of the time spent in the course, the number of assignments past
  their due date that are not submitted, and whether the student is inactive.
- New web service function `local_darimaa_get_quiz_topic_results`: quiz results for each student
  and for the group by topic. A topic is a quiz grade item, or the question category when the
  quiz has no grade items.
- The service "Darimaa bot" now has nine functions. Its name, the six existing functions and
  their replies are unchanged.
- New setting "Student code field": username (default), ID number or a custom profile field.
- New setting "Session timeout (minutes)", default 30, for the time estimate.
- The privacy metadata lists the data the new reports send to the bot and the chat app.

## 0.2.2 (2026-11-13)

Small fixes from the audit of 0.2.1. No new features and no database changes.

- `local_darimaa_get_user` returns only what the bot reads: it no longer returns the user id, the
  full name and the language. Its parameters are unchanged, and older bots keep working.
- The privacy metadata lists every field of the link table and every item that reaches the bot's
  server and the chat app.
- When a user is deleted, their chat app link and any waiting code are removed at once.
- A test guards the web service name "Darimaa bot" and its short name.
- The coding style is checked with the `moodle-extra` standard.

## 0.2.1 (2026-11-13)

- New name: Teacher & Student Assistant. The technical name stays `local_darimaa`, so existing
  installations upgrade in place and keep their tokens and links.

## 0.2.0 (2026-11-06)

- Messenger connection: users link their Messenger account with a one-time code.
- Web service functions and a "Darimaa bot" service for the Messenger bot: linked user,
  deadlines, missing submissions, sending reminders in a teacher's name.
- `cli/setup_bot.php` prepares web services, the bot's service account and its token.
- Full privacy provider for the new link records.

## 0.1.0 (2026-10-30)

- First version: missing submissions overview, teacher-approved reminders, settings,
  privacy provider, unit tests and GitHub Actions checks.
- Supports Moodle 5.0 to 5.3.
