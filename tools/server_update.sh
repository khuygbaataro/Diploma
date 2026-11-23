#!/usr/bin/env bash
# Updates the assistant bot and the Moodle plugin on the server from the GitHub repository.
#
# Once:          sudo git clone https://github.com/khuygbaataro/Diploma.git /opt/darimaa-src
# Every update:  sudo bash /opt/darimaa-src/tools/server_update.sh
#
# It pulls the newest code, copies bot/ to the bot folder (the .env file and the virtual
# environment stay), installs the Python packages, restarts the bot, copies plugin/ into Moodle
# and runs the Moodle upgrade. Paths can be changed with the variables below.
set -euo pipefail

SRC="${DARIMAA_SRC:-/opt/darimaa-src}"
BOT_DIR="${DARIMAA_BOT_DIR:-/opt/darimaa-bot}"
BOT_USER="${DARIMAA_BOT_USER:-darimaa}"
BOT_SERVICE="${DARIMAA_BOT_SERVICE:-darimaa-bot}"
MOODLE_DIR="${MOODLE_DIR:-/var/www/moodle}"
WEB_USER="${MOODLE_WEB_USER:-www-data}"

say() { printf '\n== %s\n' "$*"; }

if [ "$(id -u)" -ne 0 ] && [ -z "${DARIMAA_TEST:-}" ]; then
    echo "Run it with sudo: sudo bash $0" >&2
    exit 1
fi
command -v rsync >/dev/null || { echo "rsync is missing: sudo apt install -y rsync" >&2; exit 1; }

# Moodle 5.1 and later keep the web files (plugins included) in public/, and the command line
# scripts stay in admin/cli at the top. Moodle 5.0 has both at the top.
if [ -d "$MOODLE_DIR/public" ]; then
    WEB_ROOT="$MOODLE_DIR/public"
else
    WEB_ROOT="$MOODLE_DIR"
fi
if [ -f "$MOODLE_DIR/admin/cli/upgrade.php" ]; then
    CLI_DIR="$MOODLE_DIR/admin/cli"
else
    CLI_DIR="$WEB_ROOT/admin/cli"
fi
PLUGIN_DIR="$WEB_ROOT/local/darimaa"
[ -f "$CLI_DIR/upgrade.php" ] || { echo "Moodle not found in $MOODLE_DIR (set MOODLE_DIR)" >&2; exit 1; }
[ -x "$BOT_DIR/venv/bin/pip" ] || { echo "Bot virtual environment not found in $BOT_DIR/venv" >&2; exit 1; }

say "Getting the newest code"
before="$(git -C "$SRC" rev-parse --short HEAD)"
git -C "$SRC" pull --ff-only
after="$(git -C "$SRC" rev-parse --short HEAD)"
if [ "$before" = "$after" ]; then
    echo "Already up to date ($after). Copying again anyway."
else
    git -C "$SRC" log --oneline "$before..$after"
fi

say "Updating the bot in $BOT_DIR"
# rsync uses the first rule that matches: the example file is let in before .env.* is kept out.
rsync -a --delete \
    --include '.env.example' \
    --exclude '.env' --exclude '.env.*' --exclude 'venv/' --exclude '.venv/' \
    --exclude '__pycache__/' --exclude '.pytest_cache/' \
    "$SRC/bot/" "$BOT_DIR/"
"$BOT_DIR/venv/bin/pip" install --quiet -r "$BOT_DIR/requirements.txt"
chown -R "$BOT_USER:$BOT_USER" "$BOT_DIR"
systemctl restart "$BOT_SERVICE"

say "Updating the Moodle plugin in $PLUGIN_DIR"
owner="$(stat -c '%U:%G' "$WEB_ROOT/local")"
rsync -a --delete "$SRC/plugin/" "$PLUGIN_DIR/"
chown -R "$owner" "$PLUGIN_DIR"
sudo -u "$WEB_USER" php "$CLI_DIR/upgrade.php" --non-interactive
sudo -u "$WEB_USER" php "$CLI_DIR/purge_caches.php"

say "Done"
grep -o "release = '[^']*'" "$PLUGIN_DIR/version.php" | sed "s/release = /Plugin: /"
grep -o '__version__ = "[^"]*"' "$BOT_DIR/darimaa_bot/__init__.py" | sed 's/__version__ = /Bot: /'
systemctl is-active "$BOT_SERVICE"
