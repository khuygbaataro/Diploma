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

## Setting up the Messenger bot

Run this once on the server, from the Moodle folder, as the web server user:

```bash
sudo -u www-data php local/darimaa/cli/setup_bot.php --iprestriction=SERVER_IP
```

It turns on web services and the REST protocol, creates a `darimaabot` service account with a role
that can only call the assistant's functions, and prints the `MOODLE_URL` and `MOODLE_TOKEN` lines for
the bot's settings. Running it again reuses what exists; `--newtoken` replaces the token.

## Privacy

The plugin stores which chat app account belongs to which user (`local_darimaa_links`). Users can
disconnect at any time, and the privacy API exports and deletes these records.

The assistant bot's server gets data only about users who connected their chat app account: the
first name, the time zone, whether the user can send reminders, and the names, courses, due dates
and links of the activities the user still has to do. Teachers also get their assignments with the
number of students who have not submitted, without student names. The bot passes the first name,
the activities and the teacher overview on to the chat app when it answers. It never gets a
username, email address, ID number or user id.

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
