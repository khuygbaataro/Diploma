# Teacher & Student Assistant (local_darimaa)

A Moodle plugin that takes repetitive work off teachers' hands. It finds students who have not
submitted an assignment and sends them a reminder that the teacher has checked and approved.
From version 0.2 students and teachers can also use it from Facebook Messenger through the
assistant's Messenger bot, a small separate service that talks to this plugin's web service
functions. The technical name of the plugin is `local_darimaa`.

## Features

- **Missing submissions overview** for each course: every assignment with its due date and the
  number of students who have not submitted. Open it from the course's *More* menu.
- **Reminder page** for each assignment: the students who have not submitted, with their last
  access to the course. Drafts and reopened submissions count as not submitted. Everyone is ticked
  to start with; the teacher unticks whoever should not get a reminder, edits the message and sends.
- **Personal messages**: the placeholders `{firstname}`, `{lastname}`, `{assignment}`, `{course}`,
  `{duedate}` and `{link}` are filled in for each student. The due date includes the student's
  extension or override and is shown in the student's time zone.
- **Moodle notifications**: reminders arrive as notifications (pop-up and email by default).
  Students can change this in their notification preferences.
- **Safe defaults**: respects separate groups, switches itself off for anonymous submissions,
  hides last access when the site hides that field, and logs a `reminders_sent` event.
- **Messenger connection** (0.2): users get a one-time code on *Preferences > Messenger connection*
  and send it to the bot, which links their Messenger account to Moodle. No password passes
  through the chat.
- **Web service functions for the bot** (0.2): a student's open work from the calendar, a teacher's
  assignments with missing submissions, and sending reminders in the teacher's name. The bot only
  gets data for accounts their owners have linked.
- **Reports for teachers** (0.3): three read-only web service functions for the bot. A linked
  teacher can get their courses and quizzes, the inactive students of a course, and the results of
  a quiz by topic. They send no messages and change nothing in Moodle. See
  [Web service functions](#web-service-functions).

## Requirements

- Moodle 5.0 or later. Tested on Moodle 5.0.11 and 5.3.
- PHP 8.2 or later (Moodle 5.2 and 5.3 need PHP 8.3).
- PostgreSQL or MySQL/MariaDB.

## Installation

1. Copy this folder to `local/darimaa` (Moodle 5.0) or `public/local/darimaa` (Moodle 5.1 and later),
   or upload the zip file in *Site administration > Plugins > Install plugins*.
2. Go to *Site administration > Notifications* to finish the installation.

## Permissions

| Capability | Default roles | Purpose |
| --- | --- | --- |
| `local/darimaa:sendreminders` | Teacher, Non-editing teacher, Manager | See missing submissions and send reminders |
| `local/darimaa:botaccess` | None | Only for the assistant bot's service account |

## Settings

*Site administration > Plugins > Local plugins > Teacher & Student Assistant*

- **Default reminder message**: the text teachers start from. The bot sends this text as it is.
- **Messenger link**: the `m.me` link of the bot's Facebook Page, used for the "Open Messenger" button.
- **Student code field** (0.3): the user field that holds the student code teachers know a student
  by. Choices: username (default), ID number, or any custom profile field. The reports for
  teachers show this code next to the student's name.
- **Session timeout (minutes)** (0.3): default 30. Used to estimate the time a student spent in a
  course: the pause between two actions of a student counts only when it is shorter than this.

## Setting up the Messenger bot

Run this once on the server, from the Moodle folder, as the web server user:

```bash
sudo -u www-data php local/darimaa/cli/setup_bot.php --iprestriction=SERVER_IP
```

It turns on web services and the REST protocol, creates a `darimaabot` service account with a role
that can only call the assistant's functions, and prints the `MOODLE_URL` and `MOODLE_TOKEN` lines for
the bot's settings. Running it again reuses what exists; `--newtoken` replaces the token.

## Web service functions

The service "Darimaa bot" (`local_darimaa_bot`) has nine functions. All of them need the bot's
token and the capability `local/darimaa:botaccess`, and all take `channel` (for example
`messenger`) and `externalid` (the account id the chat app gave the user). Permissions are always
checked for the Moodle user linked to that chat account, never for the bot's own account. When
the chat account is not linked, the reply has `linked: false` and no data.

| Function | Type | What it does |
| --- | --- | --- |
| `local_darimaa_get_user` | read | The linked user's first name, time zone and whether they teach |
| `local_darimaa_link_account` | write | Links a chat account with a one-time code |
| `local_darimaa_unlink_account` | write | Removes the link |
| `local_darimaa_get_deadlines` | read | A student's open work, by due date |
| `local_darimaa_get_missing` | read | A teacher's assignments with missing submissions |
| `local_darimaa_send_reminders` | write | Sends reminders in the teacher's name |
| `local_darimaa_get_courses` | read | (0.3) A teacher's courses and quizzes |
| `local_darimaa_get_inactive_students` | read | (0.3) Last access, time spent and missing work of each student |
| `local_darimaa_get_quiz_topic_results` | read | (0.3) Quiz results by topic |

The examples below use placeholders: put your own site address in place of `moodle.example.com`,
the bot's token in place of `BOT-TOKEN`, and a linked teacher's chat account id in place of
`CHAT-ACCOUNT-ID`.

### local_darimaa_get_courses

Courses where the linked user has `local/darimaa:sendreminders`, with the visible quizzes where
the user has `mod/quiz:viewreports`.

```bash
curl -s https://moodle.example.com/webservice/rest/server.php \
  -d wstoken=BOT-TOKEN -d moodlewsrestformat=json \
  -d wsfunction=local_darimaa_get_courses \
  -d channel=messenger -d externalid=CHAT-ACCOUNT-ID
```

```json
{
  "linked": true,
  "courses": [
    {
      "id": 12, "fullname": "Biology 101", "shortname": "BIO101", "students": 48,
      "quizzes": [{"cmid": 345, "name": "Cells", "timeclose": 1794000000, "finished": 41}]
    }
  ]
}
```

`students` counts users with an active enrolment and a gradebook role. `finished` is the number
of those students with a finished attempt. `timeclose` is 0 when the quiz does not close.

### local_darimaa_get_inactive_students

Parameters: `courseid`, and `days` (default 7, from 1 to 365). The linked user needs
`moodle/course:viewparticipants` and `local/darimaa:sendreminders` in the course.

```bash
curl -s https://moodle.example.com/webservice/rest/server.php \
  -d wstoken=BOT-TOKEN -d moodlewsrestformat=json \
  -d wsfunction=local_darimaa_get_inactive_students \
  -d channel=messenger -d externalid=CHAT-ACCOUNT-ID \
  -d courseid=12 -d days=7
```

```json
{
  "linked": true, "coursename": "Biology 101", "days": 7,
  "lastaccessavailable": true, "timeavailable": true,
  "students": [
    {"code": "B200910003", "fullname": "Saraa Tsetseg", "lastaccess": 0, "dayssince": -1,
     "minutes": 0, "missing": 2, "inactive": true},
    {"code": "B200910001", "fullname": "Anu Dorj", "lastaccess": 1793900000, "dayssince": 0,
     "minutes": 95, "missing": 0, "inactive": false}
  ]
}
```

- Inactive students come first: those who never opened the course, then the longest away.
- `minutes` is an estimate from the standard log since the course start date. It adds the pauses
  between a student's own actions that are shorter than the session timeout. When the standard
  log store is off, `timeavailable` is false and `minutes` is -1.
- `missing` counts assignments past their due date that the student has not submitted.
  Assignments with anonymous submissions are left out, and so is a student whose extension or
  user override is still running.
- When the site hides the "last access" field and the teacher may not see hidden user fields,
  `lastaccessavailable` is false, `lastaccess` is 0, `dayssince` is -1 and nobody is `inactive`.
- In separate groups mode a teacher without access to all groups gets only the students of
  their own groups.

### local_darimaa_get_quiz_topic_results

Parameter: `cmid`, the course module id of the quiz. The linked user needs `mod/quiz:viewreports`
on the quiz.

```bash
curl -s https://moodle.example.com/webservice/rest/server.php \
  -d wstoken=BOT-TOKEN -d moodlewsrestformat=json \
  -d wsfunction=local_darimaa_get_quiz_topic_results \
  -d channel=messenger -d externalid=CHAT-ACCOUNT-ID \
  -d cmid=345
```

```json
{
  "linked": true, "quizname": "Cells", "coursename": "Biology 101",
  "topicsource": "categories", "notattempted": 7,
  "topics": [{"name": "Cell structure", "maxmark": 6, "average": 71.5}],
  "students": [
    {"code": "B200910001", "fullname": "Anu Dorj", "percent": 83.3, "ungraded": 1,
     "topics": [{"name": "Cell structure", "mark": 5, "maxmark": 6, "percent": 83.3}]}
  ]
}
```

- For each student the finished attempt with the highest mark is used, counting the questions
  that are already marked. `students` lists only
  students with a finished attempt; `notattempted` counts the others.
- A topic is a quiz grade item when the quiz has grade items (`topicsource` is `gradeitems`).
  Otherwise it is the question category of the question the student was really asked
  (`categories`), so random questions are counted in the right category.
- `ungraded` counts answers that wait for manual grading. They are left out of the student's
  marks and maximums until a teacher has marked them.
- In a topic of `topics`, `maxmark` is the full size of the topic and `average` is the average
  percentage of the students who attempted.
- The quiz's separate groups mode is respected in the same way as for inactive students.

## Privacy

The plugin stores which chat app account belongs to which user (`local_darimaa_links`). Users can
disconnect at any time, and the privacy API exports and deletes these records.

The assistant bot's server gets data only about users who connected their chat app account: the
first name, the time zone, whether the user can send reminders, and the names, courses, due dates
and links of the activities the user still has to do. Teachers also get their assignments with the
number of students who have not submitted, without student names. The bot passes the first name,
the activities and the teacher overview on to the chat app when it answers.

A connected teacher can also ask for reports about the students of their own courses, whether or
not those students use the bot: student code, full name, last course access, estimated time in the
course, number of missing assignments, and quiz results by topic. The plugin stores none of this.
It reads the data when the teacher asks and sends it to the bot, which shows it to the teacher in
the chat app. Moodle permissions decide what a teacher may see, including separate groups and a
hidden "last access" field.

The student code comes from the field chosen in the plugin settings (username, ID number or a
custom profile field). Apart from that field, the bot never gets a username, email address, ID
number or user id.

Reminders are delivered by the Moodle messaging system, which keeps its own records.

## Development

```bash
# Unit tests (run from the Moodle root after initialising PHPUnit)
vendor/bin/phpunit --testsuite local_darimaa_testsuite

# Coding style
phpcs --standard=moodle-extra --extensions=php local/darimaa
```

GitHub Actions runs the full Moodle plugin checks, with the `moodle-extra` coding standard, on every
push that changes the plugin (`.github/workflows/plugin-ci.yml` in the repository root).

## Roadmap

- Reminder text drafted with the Moodle AI subsystem
- Students who need attention: inactive or behind on several assignments
- Quiz results explained in plain language
- Answers to free-text questions from course materials (AI), in Moodle and in Messenger
- Reminders delivered to Telegram and Messenger by a message output plugin

## License

GNU GPL v3 or later. Copyright 2026 Khuygbaatar Ochirkhuyag.
