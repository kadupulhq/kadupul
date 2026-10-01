#!/usr/bin/env bash
# SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
# SPDX-License-Identifier: GPL-3.0-or-later
set -euo pipefail
root=$(cd "$(dirname "$0")/../.." && pwd)
fixture=$(mktemp -d)
trap 'rm -rf "$fixture"' EXIT
git init -q "$fixture"
cd "$fixture"
# Synthetic fixture commits use an empty hook directory; the repository hook
# under test is invoked explicitly below.
mkdir hooks
git config core.hooksPath "$fixture/hooks"
git config user.name HookTest
git config user.email hook-test@example.invalid
git config commit.gpgsign false
printf 'base\n' > base.txt
git add base.txt
git commit -qm base
base=$(git rev-parse HEAD)
git checkout -qb incoming
printf 'inherited trailing space \n' > incoming.txt
git add incoming.txt
git commit -qm incoming
git checkout -qb local "$base"
printf 'local\n' > local.txt
git add local.txt
git commit -qm local
git merge --no-commit --no-ff incoming >/dev/null 2>&1
bash "$root/.githooks/pre-commit-checks"
printf 'authored trailing space \n' > authored.txt
git add authored.txt
if bash "$root/.githooks/pre-commit-checks" > "$fixture/failure.log" 2>&1; then
    echo 'ERROR: authored whitespace was accepted' >&2
    exit 1
fi
if ! rg -q 'authored.txt.*trailing whitespace' "$fixture/failure.log"; then
    cat "$fixture/failure.log"
    exit 1
fi
printf 'OK: incoming unchanged whitespace preserved; authored whitespace rejected\n'
