#!/usr/bin/env bash
# Verify the installer-to-database-to-browser CSRF secret handoff.
set -euo pipefail
IFS=$'\n\t'

cd "$(dirname "$0")/.."

DC=(docker compose -f docker-compose.yml)

SECRET=$("${DC[@]}" exec -T cacti-db mariadb -N -B \
	-ucactiuser -pcactiuser cacti \
	-e "SELECT value FROM settings WHERE name='csrf_secret' LIMIT 1")

if ! [[ "$SECRET" =~ ^[a-f0-9]{64}$ ]]; then
	echo "FAIL: installer did not store a 32-byte hexadecimal CSRF secret" >&2
	exit 1
fi

if "${DC[@]}" exec -T cacti-master test -e /var/www/html/include/vendor/csrf/csrf-secret.php; then
	echo "FAIL: installer created the legacy CSRF secret below the web root" >&2
	exit 1
fi

"${DC[@]}" exec -T cacti-master php /var/www/html/cli/refresh_csrf.php >/dev/null
ROTATED_SECRET=$("${DC[@]}" exec -T cacti-db mariadb -N -B \
	-ucactiuser -pcactiuser cacti \
	-e "SELECT value FROM settings WHERE name='csrf_secret' LIMIT 1")
if ! [[ "$ROTATED_SECRET" =~ ^[a-f0-9]{64}$ ]] || [ "$ROTATED_SECRET" = "$SECRET" ]; then
	echo "FAIL: refresh_csrf.php did not persist a new 32-byte secret" >&2
	exit 1
fi

"${DC[@]}" exec -T cacti-master sh -c \
	'curl -fsS -c /tmp/c06.jar http://127.0.0.1/index.php > /tmp/c06_form'
TOKEN=$("${DC[@]}" exec -T cacti-master php \
	/var/www/html/tests/e2e/docker/probes/extract_csrf.php /tmp/c06_form)

if ! [[ "$TOKEN" =~ ^(sid|cookie|key|user|ip):[a-f0-9]+,[0-9]+ ]]; then
	echo "FAIL: browser form did not receive a valid token shape" >&2
	exit 1
fi

FALLBACK_WARNING='The configured external CSRF secret is unavailable or invalid, using the database secret instead'
count_fallback_warnings() {
	"${DC[@]}" exec -T cacti-master sh -c \
		"grep -cF '$FALLBACK_WARNING' /var/www/html/log/cacti.log || true"
}
WARNINGS_BEFORE=$(count_fallback_warnings)

"${DC[@]}" exec -T cacti-master sh -c \
	'printf '\''%s\n'\'' "\$path_csrf_secret = '\''/var/cacti-state/csrf-secret'\'';" >> /var/www/html/include/config.php'
# php.ini-production caches file timestamps for two seconds.
sleep 3

# A missing external secret falls back to the database secret, as 1.2.31 kept
# serving pages, so the login page still loads and issues a token.
"${DC[@]}" exec -T cacti-master rm -f /tmp/c06-fallback.jar /tmp/c06-bad.jar
FALLBACK_STATUS=$("${DC[@]}" exec -T cacti-master curl -sS \
	-b /tmp/c06-fallback.jar -c /tmp/c06-fallback.jar \
	-o /tmp/c06_fallback_form -w '%{http_code}' \
	http://127.0.0.1/index.php)
if [ "$FALLBACK_STATUS" != '200' ]; then
	echo "FAIL: a missing configured external secret returned HTTP $FALLBACK_STATUS instead of falling back" >&2
	exit 1
fi
FALLBACK_TOKEN=$("${DC[@]}" exec -T cacti-master php \
	/var/www/html/tests/e2e/docker/probes/extract_csrf.php /tmp/c06_fallback_form)
if ! [[ "$FALLBACK_TOKEN" =~ ^(sid|cookie|key|user|ip):[a-f0-9]+,[0-9]+ ]]; then
	echo "FAIL: the fallback login page did not issue a valid token shape" >&2
	exit 1
fi

# A second request in the same session must not repeat the warning. The
# container healthcheck requests index.php without a cookie every five seconds
# and logs its own warning, so the log count can only show the warning was
# written; the flag stored in this session is what keeps it to one.
"${DC[@]}" exec -T cacti-master curl -fsS \
	-b /tmp/c06-fallback.jar -c /tmp/c06-fallback.jar -o /dev/null \
	http://127.0.0.1/index.php
WARNINGS_AFTER=$(count_fallback_warnings)
if [ "$WARNINGS_AFTER" -le "$WARNINGS_BEFORE" ]; then
	echo "FAIL: the fallback to the database secret was not logged" >&2
	exit 1
fi
# shellcheck disable=SC2016
SESSION_ID=$("${DC[@]}" exec -T cacti-master awk '$6 == "Cacti" { print $7 }' /tmp/c06-fallback.jar)
if ! [[ "$SESSION_ID" =~ ^[A-Za-z0-9,-]+$ ]]; then
	echo "FAIL: the fallback login page did not start a Cacti session" >&2
	exit 1
fi
SESSION_DIR=$("${DC[@]}" exec -T cacti-master php -r 'echo session_save_path() ?: sys_get_temp_dir();')
if ! "${DC[@]}" exec -T cacti-master grep -qF 'cacti_csrf_external_secret_warned|b:1' \
	"$SESSION_DIR/sess_$SESSION_ID"; then
	echo "FAIL: the session did not record the fallback warning, so it would repeat on every request" >&2
	exit 1
fi

LAYOUT_MARKER="id='main_logo'|id=\"main_logo\"|class='cactiPageHead'|class=\"cactiPageHead\""

# A token issued from the database secret is accepted. setup.sh enables
# Domains auth, where the local realm is posted as 1.
"${DC[@]}" exec -T cacti-master curl -sS -L \
	-b /tmp/c06-fallback.jar -c /tmp/c06-fallback.jar \
	-o /tmp/c06_fallback_login \
	--data-urlencode 'action=login' \
	--data-urlencode 'login_username=admin' \
	--data-urlencode 'login_password=cacti-e2e-admin' \
	--data-urlencode "__csrf_magic=$FALLBACK_TOKEN" \
	--data-urlencode 'realm=1' \
	http://127.0.0.1/index.php
if ! "${DC[@]}" exec -T cacti-master grep -qE "$LAYOUT_MARKER" /tmp/c06_fallback_login; then
	echo "FAIL: a valid token from the fallback secret did not log in" >&2
	exit 1
fi

# The token check still refuses a forged token under the fallback secret.
"${DC[@]}" exec -T cacti-master curl -fsS \
	-b /tmp/c06-bad.jar -c /tmp/c06-bad.jar -o /dev/null \
	http://127.0.0.1/index.php
BAD_TOKEN="sid:$(printf '0%.0s' $(seq 1 64)),$(date +%s)"
"${DC[@]}" exec -T cacti-master curl -sS -L \
	-b /tmp/c06-bad.jar -c /tmp/c06-bad.jar \
	-o /tmp/c06_bad_login \
	--data-urlencode 'action=login' \
	--data-urlencode 'login_username=admin' \
	--data-urlencode 'login_password=cacti-e2e-admin' \
	--data-urlencode "__csrf_magic=$BAD_TOKEN" \
	--data-urlencode 'realm=1' \
	http://127.0.0.1/index.php
if "${DC[@]}" exec -T cacti-master grep -qE "$LAYOUT_MARKER" /tmp/c06_bad_login; then
	echo "FAIL: a forged token logged in under the fallback secret" >&2
	exit 1
fi

"${DC[@]}" exec -T cacti-master php /var/www/html/cli/refresh_csrf.php >/dev/null
if ! "${DC[@]}" exec -T cacti-master test -f /var/cacti-state/csrf-secret; then
	echo "FAIL: refresh_csrf.php did not create the configured external secret" >&2
	exit 1
fi

"${DC[@]}" exec -T cacti-master sh -c \
	'curl -fsS -c /tmp/c06-external.jar http://127.0.0.1/index.php > /tmp/c06_external_form'
EXTERNAL_TOKEN=$("${DC[@]}" exec -T cacti-master php \
	/var/www/html/tests/e2e/docker/probes/extract_csrf.php /tmp/c06_external_form)
if ! [[ "$EXTERNAL_TOKEN" =~ ^(sid|cookie|key|user|ip):[a-f0-9]+,[0-9]+ ]]; then
	echo "FAIL: the UI did not issue a token from the configured external secret" >&2
	exit 1
fi

echo "PASS: database and external secret handoffs rotate, fall back with one warning, and issue browser tokens"
