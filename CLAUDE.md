# Moodle AI Assistant: Teacher & Students

Bachelor's diploma project (MUST-SICT, Software Engineering). A Moodle plugin plus a chat
assistant that takes repetitive work off teachers' hands. Product name in Moodle:
"Teacher & Student Assistant". Technical names: `local_darimaa` (plugin), `darimaa-bot` (server).

## Repository layout

- `plugin/` - Moodle plugin `local_darimaa` (PHP 8.3, Moodle 5.0-5.3). The folder content is the
  plugin root: it is installed as `local/darimaa` (Moodle 5.0) or `public/local/darimaa` (5.1+).
- `bot/` - assistant server (Python 3.12, FastAPI): Messenger webhook, conversation, approval
  queue, scheduled jobs, AI agent.
- `docs/` - diploma documents.

## Priorities

1. Teacher features first, then student features, then the enrolment system link.
2. One task = one piece of work that can be finished and checked. Split large work into steps.

## Rules

- Follow official Moodle APIs and plugin rules: Moodle coding style (`moodle-extra`), core_external
  classes, DML with placeholders, SQL that runs on MySQL/MariaDB and PostgreSQL, full Privacy API.
- Every change comes with tests. No code without tests.
- AI inside Moodle goes through core AI (`\core_ai\manager`). The plugin never stores an AI API
  key. Check `\core_ai\manager::get_user_policy_status()` before calling AI for a user.
- The chat AI agent (tool calling) runs on the assistant server and calls the OpenAI API with a
  key from `.env`.
- Nothing reaches a student and nothing changes in Moodle until the teacher approves it.
  AI output is always a draft.
- Use as little student personal data as possible. AI prompts carry no student code or email.
  Tests use invented students only.
- Never commit passwords, tokens, API keys or server IP addresses. Use placeholders in docs.
- Never rename the web service key 'Darimaa bot': Moodle matches services by name on upgrade,
  and a rename deletes the bot's tokens.
- User-facing text for teachers and students is in plain Mongolian. Code, comments and commit
  messages are in English.

## Checks

```bash
# Plugin, Moodle 5.0 (plugin at local/darimaa)
php admin/tool/phpunit/cli/init.php --disable-composer
vendor/bin/phpunit --testsuite local_darimaa_testsuite
phpcs --standard=moodle-extra --extensions=php local/darimaa

# Plugin, Moodle 5.1 and later (plugin at public/local/darimaa)
php public/admin/tool/phpunit/cli/init.php --disable-composer
vendor/bin/phpunit --testsuite local_darimaa_testsuite

# Assistant server
cd bot && pip install -r requirements-dev.txt && pytest
```
