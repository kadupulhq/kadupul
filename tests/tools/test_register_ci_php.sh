#!/usr/bin/env bash
# SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
# SPDX-License-Identifier: GPL-3.0-or-later
set -euo pipefail
selected_php=$(mise exec php@8.4.25 -- php -r 'echo PHP_BINARY;')
root=$(cd "$(dirname "$0")/../.." && pwd)
workspace=$(mktemp -d)
trap 'rm -rf "$workspace"' EXIT
mkdir -p "$workspace/tools" "$workspace/preserved php/bin"
cat > "$workspace/preserved php/bin/php" <<'PHP'
#!/usr/bin/env bash
printf '%s' "$FIXTURE_VERSION"
PHP
cat > "$workspace/tools/mise" <<'MISE'
#!/usr/bin/env bash
set -euo pipefail
printf '%s\n' "$*" >> "$MISE_CALLS"
case "$1" in
    plugins) ;;
    link) exit "${LINK_STATUS:-0}" ;;
    exec)
        [ "$2" = "php@$FIXTURE_VERSION" ]
        if [ -n "${REAL_PHP:-}" ]; then
            exec "$REAL_PHP" "${@:5}"
        fi
        printf '%s' "${READBACK_VERSION:-$FIXTURE_VERSION}"
        ;;
    *) exit 1 ;;
esac
MISE
chmod +x "$workspace/tools/mise" "$workspace/preserved php/bin/php"
export PATH="$workspace/tools:$PATH"
export GITHUB_ENV="$workspace/environment" MISE_CALLS="$workspace/calls"
export FIXTURE_VERSION=8.4.26
run_registration() {
    bash "$root/tests/tools/register_ci_php.sh" "$workspace/preserved php" "$1"
}
reset_evidence() {
    : > "$GITHUB_ENV"
    : > "$MISE_CALLS"
}
reset_evidence
run_registration 8.4
[ "$(cat "$GITHUB_ENV")" = 'KADUPUL_CI_PHP_VERSION=8.4.26' ]
grep -Fq "link php@8.4.26 $workspace/preserved php" "$MISE_CALLS"
if grep -Fq 'php@8.4.25' "$MISE_CALLS"; then exit 1; fi
echo 'PASS: fallback patch is registered and selected under its actual version'
for invalid in 8.3.30 8.4.26RC1 $'8.4.26\ninjected=value' ''; do
    reset_evidence
    export FIXTURE_VERSION="$invalid"
    if run_registration 8.4; then exit 1; fi
    [ ! -s "$GITHUB_ENV" ]
    [ ! -s "$MISE_CALLS" ]
done
echo 'PASS: wrong minor, prerelease, multiline and empty versions fail before registration'
export FIXTURE_VERSION=8.4.26
reset_evidence
export READBACK_VERSION=8.4.25
if run_registration 8.4; then exit 1; fi
[ ! -s "$GITHUB_ENV" ]
unset READBACK_VERSION
echo 'PASS: mismatched mise readback cannot publish a runtime'
reset_evidence
export LINK_STATUS=1
if run_registration 8.4; then exit 1; fi
[ ! -s "$GITHUB_ENV" ]
if grep -q '^exec ' "$MISE_CALLS"; then exit 1; fi
unset LINK_STATUS
echo 'PASS: failed registration cannot publish a runtime'
reset_evidence
export FIXTURE_VERSION=8.3.30
run_registration 8.3
[ "$(cat "$GITHUB_ENV")" = 'KADUPUL_CI_PHP_VERSION=8.3.30' ]
echo 'PASS: unsupported application runtime is admitted for the 8.3 rejection matrix'
reset_evidence
rm "$workspace/preserved php/bin/php"
ln -s "$selected_php" "$workspace/preserved php/bin/php"
export REAL_PHP="$selected_php"
export FIXTURE_VERSION
FIXTURE_VERSION=$("$selected_php" -r 'echo PHP_VERSION;')
run_registration 8.4
[ "$(cat "$GITHUB_ENV")" = "KADUPUL_CI_PHP_VERSION=$FIXTURE_VERSION" ]
echo 'PASS: native PHP version survives preserved-path and mise-command handoff'
