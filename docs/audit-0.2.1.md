# Audit of the 0.2.1 baseline

Date: 2026-11-13. Scope: `plugin/` (local_darimaa 0.2.1, version 2026111300) and `bot/`
(darimaa-bot 0.2.1) as imported from the release zips, checked against the "Rules" section of
`CLAUDE.md`. This document only records findings. Nothing listed here has been fixed.

How it was checked: every imported file was read. The bot tests were run locally
(17 passed on Python 3.11 after adding `tzdata`, see risk 2). PHP, PHPUnit and phpcs are not
installed on the audit machine, so the plugin checks rely on GitHub Actions.

Status values: **OK** = the rule holds, **Gap** = built but does not fully meet the rule,
**Not built yet** = the feature the rule is about does not exist in 0.2.1.

Effort: S = up to half a day, M = one to three days, L = a week or more.

## Rules

| # | Rule | Status | Evidence | Proposed fix | Effort |
|---|------|--------|----------|--------------|--------|
| 1 | Follow official Moodle APIs and plugin rules: coding style (`moodle-extra`), core_external classes, DML with placeholders, SQL for MySQL/MariaDB and PostgreSQL, full Privacy API | Gap | OK parts: all web service classes extend `core_external\external_api` (`plugin/classes/external/bot_function.php:33`); all queries use DML with named placeholders and portable SQL (`plugin/classes/local/link_manager.php:122-136`, `plugin/classes/local/missing_submissions.php:98-103`, `plugin/classes/privacy/provider.php:73-79`). Gaps: (a) the privacy metadata is incomplete. The table description leaves out `code`, `codeexpires` and `timemodified` (`plugin/classes/privacy/provider.php:48-53`), although `timemodified` is exported (`provider.php:120`). The external location declares only first name and deadlines (`provider.php:55-58`), but the bot also receives user id, full name, language, time zone and teacher status (`plugin/classes/external/get_user.php:72-77`) and Meta receives course names, assignment names and missing counts for teachers (`plugin/classes/external/get_missing.php:90-96`, `bot/darimaa_bot/conversation.py:213-219`). (b) CI runs phpcs with the default `moodle` standard, not `moodle-extra` (`.github/workflows/plugin-ci.yml:95`, rule in `CLAUDE.md:22`). | (a) Add the missing fields and data categories to `get_metadata()` with new language strings, and assert them in `provider_test.php`. (b) Add `--standard moodle-extra` to the phpcs step and fix whatever it reports. | S |
| 2 | Every change comes with tests. No code without tests | Gap | Tested: link manager, missing submissions, reminder sender, privacy provider, all six web service functions, course navigation (`plugin/tests/`), and conversation, webhook and Messenger parsing in the bot (`bot/tests/`). No test refers to: `plugin/cli/setup_bot.php`, `plugin/classes/form/remind_form.php:68-77` (validation), `local_darimaa_extend_navigation_user_settings` (`plugin/lib.php:56`), the pages `index.php`, `remind.php`, `link.php` (no Behat features), team submissions (`missing_submissions.php:88-96`), `bot/darimaa_bot/config.py`, `bot/darimaa_bot/memory.py` (expiry and size limit), `bot/darimaa_bot/setup_profile.py`. | Add PHPUnit tests for the form, user navigation and team submissions; one Behat feature each for the reminder page and the link page; pytest tests for `config.py` and `memory.py`. Move the logic of `setup_bot.php` into a class so it can be tested. | M |
| 3 | AI inside Moodle goes through core AI (`\core_ai\manager`). The plugin never stores an AI API key. Check `get_user_policy_status()` before calling AI for a user | Not built yet | No reference to `core_ai` anywhere in `plugin/`. The plugin has two settings and neither is a key (`plugin/settings.php:32-46`). | When the first AI feature is added: call a core AI action through `\core_ai\manager`, check the user policy status first, and cover both paths with tests. | M |
| 4 | The chat AI agent (tool calling) runs on the assistant server and calls the OpenAI API with a key from `.env` | Not built yet | The bot answers with keyword rules (`bot/darimaa_bot/conversation.py:1`, `conversation.py:27-39`, `conversation.py:56-70`). `bot/requirements.txt` has no OpenAI client and `bot/.env.example` has no OpenAI key. | Add the agent behind the existing `intent()` step, with the key read in `config.py` from `.env`, a placeholder in `.env.example`, and tests that use a fake model. | L |
| 5 | Nothing reaches a student and nothing changes in Moodle until the teacher approves it. AI output is always a draft | Gap | Moodle page: OK, the teacher picks the students, edits the text and submits (`plugin/remind.php:88-98`). Chat: the teacher confirms with a button (`bot/darimaa_bot/conversation.py:224-232`), but (a) the confirmation shows only the assignment name and a count, not the student names or the message text (`bot/darimaa_bot/texts.py:58`); (b) the send step recomputes the list, so it can differ from what was confirmed (`plugin/classes/external/send_reminders.php:94-100`); (c) approval lives only in the chat flow: there is no approval queue or stored approval record, and the web service sends as soon as it is called with the bot token (`send_reminders.php:58-103`). There is no AI output yet. | Build the approval queue on the assistant server: store each proposed action (recipients, text) as a draft, show it to the teacher, and send exactly the approved draft. Let the web service accept the approved recipient list and text. | L |
| 6 | Use as little student personal data as possible. AI prompts carry no student code or email. Tests use invented students only | Gap | OK parts: no web service returns username, id number or email; tests use generator users and invented first names (`plugin/tests/local/missing_submissions_test.php:47-49`, `bot/tests/fakes.py:18-19`). There are no AI prompts yet. Gaps: `get_user` returns `userid`, `fullname` and `lang`, which the bot never reads (`plugin/classes/external/get_user.php:72-75`; no use in `bot/darimaa_bot/`). The bot log records Messenger account ids (`bot/darimaa_bot/conversation.py:98`). | Remove the unused fields from `get_user` (and from `bot/tests/fakes.py:35`). Log a short hash instead of the Messenger id. Add the "no student code or email" check when AI prompts are built. | S |
| 7 | Never commit passwords, tokens, API keys or server IP addresses. Use placeholders in docs | OK | `bot/.env.example:2-11` holds placeholders only; `.gitignore` excludes `.env` files. The three server addresses in `bot/README.md` (lines 14, 23, 35) were replaced with `SERVER-IP` during this import, before the file was first committed. Remaining addresses are loopback or RFC 5737 (`plugin/cli/setup_bot.php:59`). | None. Optional: add a secret and IP scan to CI so the rule is enforced on every push. | S |
| 8 | Never rename the web service key 'Darimaa bot' | OK | `plugin/db/services.php:68`, with a warning comment on line 67. The CLI finds the service by short name (`plugin/cli/setup_bot.php:127`). No test guards the name. | Add a test that asserts the service name and short name, so a rename fails CI. | S |
| 9 | User-facing text for teachers and students is in plain Mongolian. Code, comments and commit messages are in English | Gap | Bot: OK, all replies are Mongolian (`bot/darimaa_bot/texts.py`). Plugin: only an English language pack exists (`plugin/lang/en/local_darimaa.php`; there is no `plugin/lang/mn/`). The default reminder students receive is English (`lang/en/local_darimaa.php:32-37`, used by the bot path in `plugin/classes/external/send_reminders.php:95-98`). The bot tells users to press the English button label «Connect Messenger» (`bot/darimaa_bot/texts.py:25`). Code and comments are in English. | Add `plugin/lang/mn/local_darimaa.php` with every string in plain Mongolian. Send the default reminder in the student's language. Update the bot instruction to the Mongolian button label. | M |

## Other risks

Ordered by how soon they should be handled.

### Security

1. **The Messenger Page access token is written to the log.** The token is sent as a URL
   query parameter (`bot/darimaa_bot/messenger.py:124`). The app sets the root log level to
   INFO (`bot/darimaa_bot/app.py:29`), and the HTTP client logs every request URL at INFO.
   Confirmed with a fake token: the log line contains `?access_token=...`. HTTP errors logged
   in `bot/darimaa_bot/conversation.py:108` also include the URL. Anyone who can read the
   service journal can take over the Page's messaging.
   Fix: send the token in an `Authorization: Bearer` header, or set the `httpx` logger to
   WARNING. Rotate the Page token after the fix if the bot has already run. Effort: S.
2. **`tzdata` is not listed in `bot/requirements.txt`.** On Windows the tests cannot be
   collected (`bot/tests/test_conversation.py:14`), and at run time the bot silently falls back
   to UTC when the time zone database is missing (`bot/darimaa_bot/conversation.py:261-268`), so
   students would see wrong due times. Linux servers normally have the system database.
   Fix: add `tzdata` to the requirements. Effort: S.
3. **One token has site-wide reach.** The bot token can read the deadlines of every linked
   user and send reminders in the name of every linked teacher
   (`plugin/db/access.php:41-46`). The IP restriction that limits its use is optional and empty
   by default (`plugin/cli/setup_bot.php:38`, `setup_bot.php:68`). The script prints the token
   to the terminal (`setup_bot.php:165`).
   Fix: make `--iprestriction` required (or default to loopback when the bot runs on the same
   server) and document token rotation with `--newtoken`. Effort: S.
4. **Link codes are rate-limited only in the bot.** The limit of five attempts per hour is kept
   in memory per Messenger account (`bot/darimaa_bot/conversation.py:88`, `conversation.py:155`)
   and resets on restart. The plugin itself accepts unlimited attempts
   (`plugin/classes/local/link_manager.php:113-129`). The code space is large (8 characters
   from 32), so the risk is low. Fix: count failed attempts in the plugin too. Effort: S.
5. **Bot state is in memory only.** Duplicate detection, the code rate limit and the
   10-minute resend guard (`bot/darimaa_bot/conversation.py:87-89`) are lost on restart and are
   not shared between worker processes. `RateLimiter` never removes old keys
   (`bot/darimaa_bot/memory.py:56-66`), so memory grows with every new sender.
   Fix: move this state to a small database when the approval queue is built. Effort: M.
6. **Unexpected errors leave the user without an answer.** `handle()` catches only Moodle and
   HTTP errors (`bot/darimaa_bot/conversation.py:95-102`). A non-JSON reply from Moodle
   (`bot/darimaa_bot/moodle.py:34`) or a missing field raises inside the background task.
   Fix: catch everything else, log it and send the generic error text. Effort: S.
7. **The one-time code travels in a URL.** The "Open Messenger" button adds the code as
   `ref` to the m.me link (`plugin/link.php:80`), so it appears in browser history. The code
   is single-use and expires in 15 minutes, so the risk is low. Any `ref` value from Messenger
   is tried as a code without a format check (`bot/darimaa_bot/conversation.py:114`).
8. **Dependencies are pinned but not monitored.** `bot/requirements.txt` pins three packages
   released in spring 2025 and leaves transitive packages (for example Starlette) unpinned.
   GitHub Actions are pinned by tag, not by commit. Fix: run `pip-audit` in CI and turn on
   Dependabot. Effort: S.

### Privacy

9. **Personal data goes to Meta.** First names, course names, assignment names and due dates
   are sent to Messenger for every answer. The privacy metadata covers this only partly (see
   rule 1). The site privacy notice should say so before real students use the bot.
10. **Links of deleted users stay in the table.** A deleted or suspended user is ignored at
    read time (`plugin/classes/local/link_manager.php:167-170`), but the row with the Messenger
    id remains until a privacy deletion request runs. Fix: remove the link in a
    `user_deleted` event observer. Effort: S.
11. **Log retention is not defined.** The bot log holds Messenger ids and Moodle error text
    (`bot/darimaa_bot/conversation.py:98`, `bot/darimaa_bot/messenger.py:129`). Decide how long
    the journal is kept.

### Moodle guidelines and behaviour

12. **The setup script changes site-wide settings.** It turns on web services and the REST
    protocol for the whole site (`plugin/cli/setup_bot.php:74-79`) and writes `policyagreed`
    straight into the user table (`setup_bot.php:119`). Both are documented in the script, but
    an administrator should know before running it.
13. **No limit on repeated reminders from the Moodle page.** A teacher can send the same
    reminder again at once (`plugin/remind.php:90-98`); only the chat path has a 10-minute
    guard. The capability is marked `RISK_SPAM` (`plugin/db/access.php:29`).
14. **The teacher overview may be slow on large sites.** `get_teacher_overview()` loads the
    participants of every assignment in every course of the teacher inside one web service
    call (`plugin/classes/local/missing_submissions.php:221-253`), and team assignments run one
    query per student (`missing_submissions.php:88-96`). Not measured yet.
15. **Maturity is alpha.** `plugin/version.php:31` declares `MATURITY_ALPHA`. Fine for a
    baseline; it must change before any release to real users.
16. **Local development is not reproducible on Windows as documented.** Besides `tzdata`
    (risk 2), the README assumes Linux. A short "run the tests on Windows" note would help.
