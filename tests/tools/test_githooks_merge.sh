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
if ! grep -q 'authored.txt.*trailing whitespace' "$fixture/failure.log"; then
    cat "$fixture/failure.log"
    exit 1
fi
printf 'OK: incoming unchanged whitespace preserved; authored whitespace rejected\n'
git restore --staged authored.txt
for excluded in include/vendor tests/Fixtures node_modules; do
    mkdir -p "$excluded"
    file="$excluded/authored.php"
    printf 'invalid PHP with authored trailing space \n' > "$file"
    git add -f "$file"
    if bash "$root/.githooks/pre-commit-checks" > "$fixture/excluded-failure.log" 2>&1; then
        echo "ERROR: authored whitespace was accepted under $excluded" >&2
        exit 1
    fi
    if ! grep -Fq "$file:1: trailing whitespace" "$fixture/excluded-failure.log"; then
        cat "$fixture/excluded-failure.log"
        exit 1
    fi
    printf '<?php deliberately invalid syntax\n' > "$file"
    git add -f "$file"
    bash "$root/.githooks/pre-commit-checks"
    git restore --staged "$file"
done
printf 'OK: PHP exclusions retain authored whitespace validation\n'

git merge --abort
conflict_files=('conflict.txt' 'conflict name.txt' 'include/vendor/conflict.php' 'tests/Fixtures/conflict.php' 'node_modules/conflict.php')
for file in "${conflict_files[@]}"; do
    mkdir -p "$(dirname "$file")"
    printf 'base conflict\n' > "$file"
    git add -f -- "$file"
done
git commit -qm 'conflict base'
conflict_base=$(git rev-parse HEAD)
git checkout -qb conflict-incoming
for file in "${conflict_files[@]}"; do
    printf 'incoming conflict trailing space \n' > "$file"
    git add -f -- "$file"
done
printf 'clean incoming trailing space \n' > clean-incoming.txt
git add clean-incoming.txt
git commit -qm 'conflict incoming'
git checkout -qb conflict-local "$conflict_base"
for file in "${conflict_files[@]}"; do
    printf 'local conflict\n' > "$file"
    git add -f -- "$file"
done
git commit -qm 'conflict local'
if git merge --no-commit --no-ff conflict-incoming > "$fixture/merge-conflict.log" 2>&1; then
    echo 'ERROR: expected a real merge conflict' >&2
    exit 1
fi
for file in "${conflict_files[@]}"; do
    git checkout --theirs -- "$file"
    git add -f -- "$file"
    if [ "$(git rev-parse ":$file")" != "$(git rev-parse "MERGE_HEAD:$file")" ]; then
        echo "ERROR: resolution does not match incoming blob: $file" >&2
        exit 1
    fi
done
if bash "$root/.githooks/pre-commit-checks" > "$fixture/resolved-failure.log" 2>&1; then
    echo 'ERROR: conflicted paths resolved exactly to incoming whitespace were accepted' >&2
    exit 1
fi
# Exercise Git's real pre-commit invocation as well as the direct hook call.
printf '#!/usr/bin/env bash\nexec bash %q\n' "$root/.githooks/pre-commit-checks" > hooks/pre-commit
chmod +x hooks/pre-commit
if git commit --no-edit > "$fixture/resolved-commit-failure.log" 2>&1; then
    echo 'ERROR: Git committed an unchecked incoming conflict resolution' >&2
    exit 1
fi
if ! grep -Fq 'conflict.txt:1: trailing whitespace' "$fixture/resolved-commit-failure.log"; then
    cat "$fixture/resolved-commit-failure.log"
    exit 1
fi
for file in "${conflict_files[@]}"; do
    if ! grep -Fq "$file:1: trailing whitespace" "$fixture/resolved-failure.log"; then
        cat "$fixture/resolved-failure.log"
        exit 1
    fi
    printf 'clean resolved content\n' > "$file"
    git add -f -- "$file"
done
# The unrelated incoming blob retains its exemption after local conflicts
# have been checked and corrected.
bash "$root/.githooks/pre-commit-checks"
git commit --no-edit > "$fixture/resolved-commit-success.log" 2>&1
printf 'OK: exact incoming conflict resolutions checked; clean incoming exemption retained\n'

# Fixture branch construction remains independent of the hook under test.
mkdir setup-hooks
git config core.hooksPath "$fixture/setup-hooks"
edge_failed=0
magic_files=(':(exclude)*' ':(exclude)authored.txt' ':(glob)wildcard[abc].txt')
printf 'clean wildcard neighbour\n' > wildcarda.txt
magic_failed=0
for file in "${magic_files[@]}"; do
    printf 'authored literal path trailing space \n' > "$file"
    git --literal-pathspecs add -- "$file"
    if bash "$root/.githooks/pre-commit-checks" > "$fixture/magic-failure.log" 2>&1; then
        echo "ERROR: pathspec-magic authored whitespace was accepted: $file" >&2
        magic_failed=1
        edge_failed=1
    else
        if ! grep -Fq "$file:1: trailing whitespace" "$fixture/magic-failure.log"; then
            echo "ERROR: authored literal path was not checked: $file" >&2
            magic_failed=1
            edge_failed=1
        fi
    fi
    git --literal-pathspecs restore --staged -- "$file"
done
if [ "$magic_failed" -eq 0 ]; then
    printf 'OK: authored pathspec-magic filenames checked literally\n'
fi

octopus_base=$(git rev-parse HEAD)
git checkout -qb octopus-one
printf 'first incoming trailing space \n' > octopus-one.txt
git add octopus-one.txt
git commit -qm 'first octopus incoming'
git checkout -qb octopus-two "$octopus_base"
printf 'second incoming trailing space \n' > octopus-two.txt
git add octopus-two.txt
git commit -qm 'second octopus incoming'
git checkout -qb octopus-local "$octopus_base"
printf 'local octopus content\n' > octopus-local.txt
git add octopus-local.txt
git commit -qm 'octopus local'
git merge --no-commit --no-ff octopus-one octopus-two > "$fixture/octopus-merge.log" 2>&1
if [ "$(wc -l < "$(git rev-parse --git-path MERGE_HEAD)")" -ne 2 ]; then
    echo 'ERROR: expected a genuine two-incoming-parent merge' >&2
    exit 1
fi
if bash "$root/.githooks/pre-commit-checks" > "$fixture/octopus-check.log" 2>&1; then
    printf 'OK: both unchanged octopus parents retain their exemption\n'
else
    echo 'ERROR: unchanged octopus incoming whitespace was rejected' >&2
    cat "$fixture/octopus-check.log"
    edge_failed=1
fi
printf 'locally authored octopus trailing space \n' > octopus-local.txt
git add octopus-local.txt
if bash "$root/.githooks/pre-commit-checks" > "$fixture/octopus-authored.log" 2>&1; then
    echo 'ERROR: locally authored octopus whitespace was accepted' >&2
    exit 1
fi
if ! grep -Fq 'octopus-local.txt:1: trailing whitespace' "$fixture/octopus-authored.log"; then
    cat "$fixture/octopus-authored.log"
    exit 1
fi
exit "$edge_failed"
