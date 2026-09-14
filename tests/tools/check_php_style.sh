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

# main moves to PER-CS one file at a time. A file that was not yet PER-CS clean
# at the merge base is skipped, so a small fix in an unconverted file does not
# force a whole-file reformat; converting it is its own formatting-only change.
# New files, and files already clean at the merge base, must stay clean.
tmp=$(mktemp -d)
trap 'rm -rf "$tmp"' EXIT
root=$(pwd)

# Resolve the fixer once: the merge-base check below runs from another directory,
# where a relative PHP_CS_FIXER path would no longer point at the binary.
if ! fixer_path=$(command -v "$fixer"); then
	echo "php-cs-fixer not found: $fixer" >&2
	exit 2
fi
case "$fixer_path" in
	/*) ;;
	*) fixer_path="$root/$fixer_path" ;;
esac

checked=()
# A renamed file is compared with its merge-base name. A plain list keeps the
# script working on the bash 3.2 that macOS ships.
renames=$(git diff --name-status -M --diff-filter=R "$merge_base" -- '*.php')

# Paths the config's Finder covers (it excludes include/vendor and
# tests/Fixtures). The merge-base copy is checked with --path-mode=override,
# which would otherwise bypass those exclusions.
included=$("$fixer_path" list-files --config="$config" 2>/dev/null | sed -e "s/^'//" -e "s/'$//" -e 's#^\./##')

# True when two PHP files hold the same tokens apart from whitespace, so a
# change between them only reformats. String and heredoc contents are tokens,
# so a changed literal does not count as whitespace.
same_tokens() {
	php -r '
		$strip = function ($file) {
			$out = array();
			foreach (token_get_all(file_get_contents($file)) as $t) {
				if (is_array($t)) {
					if ($t[0] === T_WHITESPACE) {
						continue;
					}
					$out[] = array($t[0], $t[1]);
				} else {
					$out[] = $t;
				}
			}
			return $out;
		};
		exit($strip($argv[1]) === $strip($argv[2]) ? 0 : 1);
	' "$1" "$2"
}

# Paths the Finder covered at the merge base. A rename source no longer exists
# in the working tree, so list-files there cannot say whether it was excluded.
# The Finder only looks at names, so empty placeholder files are enough.
base_included=
if [ -n "$renames" ]; then
	base_tree="$tmp/.merge-base-tree"
	mkdir -p "$base_tree"
	cp "$config" "$base_tree/"
	# ls-tree takes pathspecs as literal prefixes, so '*.php' would match nothing.
	git ls-tree -r --name-only "$merge_base" | { grep '\.php$' || true; } > "$tmp/.merge-base-files"
	(
		cd "$base_tree"
		awk -F/ 'NF > 1 { NF--; print }' OFS=/ "$tmp/.merge-base-files" | sort -u | tr '\n' '\0' | xargs -0 mkdir -p
		tr '\n' '\0' < "$tmp/.merge-base-files" | xargs -0 touch
	)
	base_included=$(cd "$base_tree" && "$fixer_path" list-files --config="$config" 2>/dev/null | sed -e "s/^'//" -e "s/'$//" -e 's#^\./##')
fi

for f in "${files[@]}"; do
	if ! printf '%s\n' "$included" | grep -Fqx -- "$f"; then
		continue
	fi
	base_path=$f
	while IFS=$'\t' read -r _ from to; do
		if [ "$to" = "$f" ]; then
			base_path=$from
		fi
	done <<< "$renames"
	# A file moved in from outside the Finder was never subject to the rules;
	# treat it as new so it is checked in full.
	if [ "$base_path" != "$f" ] && ! printf '%s\n' "$base_included" | grep -Fqx -- "$base_path"; then
		checked+=("$f")
		continue
	fi
	if git cat-file -e "$merge_base:$base_path" 2>/dev/null; then
		mkdir -p "$tmp/$(dirname "$base_path")"
		git show "$merge_base:$base_path" > "$tmp/$base_path"
		# override applies the rules to the copy, which lies outside the config's finder
		set +e
		(cd "$tmp" && "$fixer_path" check --config="$root/$config" --path-mode=override \
			--using-cache=no -- "$base_path" >/dev/null 2>&1)
		status=$?
		set -e
		# php-cs-fixer check exits 8 when files only need formatting; any other
		# non-zero status is a real failure and must not be read as "unconverted".
		if [ "$status" -eq 8 ]; then
			# A change that only moves whitespace is a PER-CS conversion; it must
			# finish the job, so it is checked in full rather than skipped.
			if same_tokens "$tmp/$base_path" "$f"; then
				checked+=("$f")
				continue
			fi
			echo "Skipping $f: not PER-CS formatted at $merge_base; convert it in a formatting-only change."
			continue
		elif [ "$status" -ne 0 ]; then
			echo "php-cs-fixer failed with status $status while checking $base_path at $merge_base" >&2
			exit "$status"
		fi
	fi
	checked+=("$f")
done

if [ "${#checked[@]}" -eq 0 ]; then
	echo "No changed PHP files already on PER-CS; nothing to check."
	exit 0
fi

# intersection keeps the config's exclusions in force for the paths given.
# Not exec: the EXIT trap must still remove the temporary directory.
set +e
"$fixer_path" check --config="$config" --path-mode=intersection \
	--using-cache=no --diff -- "${checked[@]}"
status=$?
set -e
exit "$status"
