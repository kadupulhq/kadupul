#!/usr/bin/env bash
# SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
# SPDX-License-Identifier: GPL-3.0-or-later
#
# Checks PER-CS formatting on the PHP files changed since a base commit.
# main moves to PER-CS one edited file at a time, so a whole-tree run would
# fail on every file nobody has touched yet.
#
# Usage: tests/tools/check_php_style.sh [base]    (default: origin/main)
# Set PHP_CS_FIXER to use a fixer binary that is not on PATH.

set -euo pipefail

base=${1:-origin/main}
fixer=${PHP_CS_FIXER:-php-cs-fixer}

cd "$(git rev-parse --show-toplevel)"

# Same precedence the fixer uses when no --config is given, and the same two
# names the CI job accepts.
if [ -f .php-cs-fixer.php ]; then
	config=.php-cs-fixer.php
elif [ -f .php-cs-fixer.dist.php ]; then
	config=.php-cs-fixer.dist.php
else
	echo "No .php-cs-fixer.php or .php-cs-fixer.dist.php at the repository root." >&2
	exit 2
fi

# Diff from the merge base to the working tree, so a local run also covers
# uncommitted edits, and add untracked files so a new file is checked before
# it is staged. In CI the working tree is the commit under test and nothing
# is untracked.
merge_base=$(git merge-base "$base" HEAD)

files=()
while IFS= read -r -d '' f; do
	files+=("$f")
done < <(
	{
		git diff -z --name-only --diff-filter=ACMR "$merge_base" -- '*.php'
		git ls-files -z --others --exclude-standard -- '*.php'
	} | sort -zu
)

if [ "${#files[@]}" -eq 0 ]; then
	echo "No changed PHP files; nothing to check."
	exit 0
fi

# intersection keeps the config's exclusions in force for the paths given.
exec "$fixer" check --config="$config" --path-mode=intersection \
	--using-cache=no --diff -- "${files[@]}"
