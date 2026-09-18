#!/usr/bin/env bash
# SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
# SPDX-License-Identifier: GPL-3.0-or-later
#
# Exercises check_php_style.sh against throwaway repositories that use this
# repository's fixer config. Needs php and php-cs-fixer (or PHP_CS_FIXER).

# The PHP written below contains $name, which is PHP, not a shell variable.
# shellcheck disable=SC2016

set -euo pipefail

root="$(cd "$(dirname "$0")/../.." && pwd)"
scratch="$(mktemp -d)"
trap 'rm -rf "$scratch"' EXIT

fixer=${PHP_CS_FIXER:-php-cs-fixer}
if ! fixer_path=$(command -v "$fixer"); then
	echo "php-cs-fixer not found: $fixer" >&2
	exit 2
fi
case "$fixer_path" in
	/*) ;;
	*) fixer_path="$(pwd)/$fixer_path" ;;
esac
export PHP_CS_FIXER="$fixer_path"

# Cacti formatting: tab indentation, which PER-CS rejects.
legacy() {
	printf '<?php\n\nfunction greet($name)\n{\n\treturn %s . $name;\n}\n' "$1"
}

# The same function in PER-CS.
formatted() {
	printf '<?php\n\nfunction greet($name)\n{\n    return %s . $name;\n}\n' "$1"
}

# A repository whose base commit holds one unconverted and one converted file.
# Hooks are pointed at an empty directory so a developer's global hooks do not
# run inside the test.
new_repo() {
	local dir="$scratch/$1"
	mkdir -p "$dir/hooks" "$dir/repo/lib"
	cd "$dir/repo"
	git init -q -b main
	git config user.name 'Style Test'
	git config user.email 'style-test@example.invalid'
	git config commit.gpgsign false
	git config core.hooksPath "$dir/hooks"
	cp "$root/.php-cs-fixer.php" .
	legacy "'hello '" > lib/legacy.php
	formatted "'hello '" > lib/clean.php
	git add -A
	git commit -q -m base
	git branch base
}

check() {
	bash "$root/tests/tools/check_php_style.sh" base > "$scratch/log" 2>&1
}

passes() {
	if ! check; then
		cat "$scratch/log" >&2
		echo "FAIL: $1" >&2
		exit 1
	fi
}

fails() {
	if check; then
		cat "$scratch/log" >&2
		echo "FAIL: $1" >&2
		exit 1
	fi
}

# A behaviour change to an unconverted file must bring the file to PER-CS.
new_repo touched
legacy "'hi '" > lib/legacy.php
git commit -q -am 'fix: shorten greeting'
fails 'an edit to an unconverted file kept its old formatting'
grep -q 'lib/legacy.php' "$scratch/log"
grep -q 'convert_php_style.sh' "$scratch/log"

# The same edit is accepted once the file is formatted.
formatted "'hi '" > lib/legacy.php
git commit -q -am 'fix: format greeting'
passes 'a converted file was rejected'

# Moving an unconverted file without editing it does not touch its content.
new_repo renamed
git mv lib/legacy.php lib/moved.php
git commit -q -m 'refactor: move greeting'
passes 'a pure rename of an unconverted file was rejected'
grep -q 'Skipping lib/moved.php: content unchanged' "$scratch/log"

# A rename that also edits the file is an edit.
new_repo renamed-edited
git mv lib/legacy.php lib/moved.php
legacy "'hi '" > lib/moved.php
git commit -q -am 'refactor: move and change greeting'
fails 'a renamed and edited unconverted file kept its old formatting'

# Files the change does not touch are not checked.
new_repo untouched
formatted "'hi '" > lib/clean.php
git commit -q -am 'fix: shorten clean greeting'
passes 'an untouched unconverted file was checked'

# Files already formatted must stay formatted.
new_repo regressed
legacy "'hello '" > lib/clean.php
git commit -q -am 'fix: indent clean greeting with tabs'
fails 'a formatted file lost its formatting'

echo 'PHP style checks behave as expected.'
