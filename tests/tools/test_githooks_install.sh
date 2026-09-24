#!/usr/bin/env bash
# SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
# SPDX-License-Identifier: GPL-3.0-or-later
set -euo pipefail

root="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/../.." && pwd)"
fixture=$(mktemp -d)
trap 'rm -rf "$fixture"' EXIT

mkdir -p "$fixture/.githooks"
cp "$root/.githooks/install" "$fixture/.githooks/install"
chmod +x "$fixture/.githooks/install"

cd "$fixture"
git init -q .

# The contributor's own global hooksPath would otherwise decide every case
# below, so point Git at a config file this test owns and can set per case.
export GIT_CONFIG_GLOBAL="$fixture/gitconfig"
export GIT_CONFIG_SYSTEM=/dev/null
: > "$GIT_CONFIG_GLOBAL"

hooks_path() {
	git config --local --get core.hooksPath || printf '<unset>'
}

# Unset is the clean clone: the installer has nothing to displace.
git config --local --unset core.hooksPath 2>/dev/null || true
.githooks/install >/dev/null
[ "$(hooks_path)" = '.githooks' ] || {
	printf 'FAIL: a clean clone was not configured\n' >&2
	exit 1
}

# Re-running must stay quiet rather than refuse its own value.
.githooks/install >/dev/null
[ "$(hooks_path)" = '.githooks' ] || {
	printf 'FAIL: re-running changed the value\n' >&2
	exit 1
}

# Another path is someone else's hooks. Refuse and leave it alone.
git config --local core.hooksPath other-hooks
if .githooks/install >/dev/null 2>&1; then
	printf 'FAIL: an existing hooks path was overwritten\n' >&2
	exit 1
fi
[ "$(hooks_path)" = 'other-hooks' ] || {
	printf 'FAIL: the existing hooks path was modified\n' >&2
	exit 1
}

# An empty value is how Git disables hooks. Reading it as unset would
# overwrite a deliberate choice, which is what the guard exists to stop.
git config --local core.hooksPath ''
if .githooks/install >/dev/null 2>&1; then
	printf 'FAIL: an empty hooks path was overwritten\n' >&2
	exit 1
fi
[ "$(hooks_path)" = '' ] || {
	printf 'FAIL: the empty hooks path was modified\n' >&2
	exit 1
}

# A global hooks directory is the case the guard was written for: the value
# belongs to no repository, so configuring this one would drop those checks
# here without saying so. Nothing local must be written either.
git config --local --unset core.hooksPath 2>/dev/null || true
git config --global core.hooksPath "$fixture/global-hooks"
if .githooks/install >"$fixture/out" 2>&1; then
	printf 'FAIL: a global hooks path was overwritten\n' >&2
	exit 1
fi
[ "$(git config --global --get core.hooksPath)" = "$fixture/global-hooks" ] || {
	printf 'FAIL: the global hooks path was modified\n' >&2
	exit 1
}
if git config --local --get core.hooksPath >/dev/null 2>&1; then
	printf 'FAIL: a local hooks path was written despite the refusal\n' >&2
	exit 1
fi
grep -F "$fixture/global-hooks" "$fixture/out" >/dev/null || {
	printf 'FAIL: the refusal did not name the configured path\n' >&2
	exit 1
}

printf 'PASS: the hook installer refuses every hooks path it did not set\n'
