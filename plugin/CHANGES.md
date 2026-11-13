# Changes

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
