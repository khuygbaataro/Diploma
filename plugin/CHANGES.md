# Changes

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
