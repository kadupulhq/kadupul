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

# Files are classified under the rules in force at the merge base, so a change
# that tightens the config cannot make an already formatted file look
# unconverted and skip its edit. The final check still uses this config.
base_config="$tmp/.merge-base-config/$config"
mkdir -p "$tmp/.merge-base-config"
if ! git show "$merge_base:$config" > "$base_config" 2>/dev/null; then
	cp "$config" "$base_config"
fi

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
# A renamed file is compared with its merge-base name. -z keeps names with
# unusual characters unquoted, so they match the file list above; parallel
# arrays keep the script working on the bash 3.2 that macOS ships.
rename_from=()
rename_to=()
while IFS= read -r -d '' _score && IFS= read -r -d '' from && IFS= read -r -d '' to; do
	rename_from+=("$from")
	rename_to+=("$to")
done < <(git diff -z --name-status -M --diff-filter=R "$merge_base" -- '*.php')

# Paths the config's Finder covers (it excludes include/vendor and
# tests/Fixtures). The merge-base copy is checked with --path-mode=override,
# which would otherwise bypass those exclusions.
included=$("$fixer_path" list-files --config="$config" | sed -e "s/^'//" -e "s/'$//" -e 's#^\./##')

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
					// The opening tag token carries the whitespace that follows it.
					if ($t[0] === T_OPEN_TAG || $t[0] === T_OPEN_TAG_WITH_ECHO) {
						$t[1] = rtrim($t[1]);
					}
					$out[] = array($t[0], $t[1]);
				} else {
					$out[] = $t;
				}
			}
			return $out;
		};
		exit($strip($argv[1]) === $strip($argv[2]) ? 0 : 1);
	' -- "$1" "$2"
}

# Paths the Finder covered at the merge base. A rename source no longer exists
# in the working tree, so list-files there cannot say whether it was excluded.
# The Finder only looks at names, so empty placeholder files are enough.
base_included=
if [ "${#rename_to[@]}" -gt 0 ]; then
	base_tree="$tmp/.merge-base-tree"
	mkdir -p "$base_tree"
	cp "$base_config" "$base_tree/$config"
	# ls-tree takes pathspecs as literal prefixes, so '*.php' would match
	# nothing; filter the NUL-delimited names instead.
	git ls-tree -z -r --name-only "$merge_base" > "$tmp/.merge-base-names"
	while IFS= read -r -d '' n; do
		case "$n" in
			*.php) printf '%s\0' "$n" ;;
		esac
	done < "$tmp/.merge-base-names" > "$tmp/.merge-base-files"
	while IFS= read -r -d '' n; do
		case "$n" in
			*/*) printf '%s\0' "${n%/*}" ;;
		esac
	done < "$tmp/.merge-base-files" | sort -zu > "$tmp/.merge-base-dirs"
	(
		cd "$base_tree"
		if [ -s "$tmp/.merge-base-dirs" ]; then
			xargs -0 mkdir -p -- < "$tmp/.merge-base-dirs"
		fi
		if [ -s "$tmp/.merge-base-files" ]; then
			xargs -0 touch -- < "$tmp/.merge-base-files"
		fi
	)
	base_included=$(cd "$base_tree" && "$fixer_path" list-files --config="$config" | sed -e "s/^'//" -e "s/'$//" -e 's#^\./##')
fi

for f in "${files[@]}"; do
	if ! printf '%s\n' "$included" | grep -Fqx -- "$f"; then
		continue
	fi
	base_path=$f
	i=0
	while [ "$i" -lt "${#rename_to[@]}" ]; do
		if [ "${rename_to[$i]}" = "$f" ]; then
			base_path=${rename_from[$i]}
		fi
		i=$((i + 1))
	done
	# A file moved in from outside the Finder was never subject to the rules;
	# treat it as new so it is checked in full.
	if [ "$base_path" != "$f" ] && ! printf '%s\n' "$base_included" | grep -Fqx -- "$base_path"; then
		checked+=("$f")
		continue
	fi
	if git cat-file -e "$merge_base:$base_path" 2>/dev/null; then
		mkdir -p -- "$tmp/$(dirname -- "$base_path")"
		git show "$merge_base:$base_path" > "$tmp/$base_path"
		# override applies the rules to the copy, which lies outside the config's finder
		set +e
		(cd "$tmp" && "$fixer_path" check --config="$base_config" --path-mode=override \
			--using-cache=no -- "$base_path" >/dev/null 2>&1)
		status=$?
		set -e
		# php-cs-fixer check exits 8 when files only need formatting; any other
		# non-zero status is a real failure and must not be read as "unconverted".
		if [ "$status" -eq 8 ]; then
			# A change that only moves whitespace is a PER-CS conversion; it must
			# finish the job, so it is checked in full rather than skipped. A pure
			# rename changes nothing and keeps the exemption.
			if ! cmp -s -- "$tmp/$base_path" "$f" && same_tokens "$tmp/$base_path" "$f"; then
				checked+=("$f")
				continue
			fi
			if [ "$base_path" != "$f" ] && cmp -s -- "$tmp/$base_path" "$f"; then
				echo "Skipping $f: renamed from $base_path without changes."
			else
				echo "Skipping $f: not PER-CS formatted at $merge_base; convert it in a formatting-only change."
			fi
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
