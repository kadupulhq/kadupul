#!/usr/bin/env bash
# SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
# SPDX-License-Identifier: GPL-3.0-or-later
#
# Converts PHP files that need PER-CS formatting in a formatting-only commit of
# their own, then reformats pending edits to those converted files so the
# remaining diff holds only the real change. Files already clean at HEAD are
# left untouched.
#
# Each file must exist at HEAD and be covered by the fixer config's Finder.
# Its HEAD version is fixed until it stops changing (some files need a second
# pass) and must then hold the same tokens as before, so the commit changes
# whitespace only. The commit is built from a temporary index based on HEAD,
# which keeps anything else that is staged out of it. Every copy is formatted
# before anything is written, so a failure leaves the branch, the index and
# the working tree as they were.
#
# Usage: tests/tools/convert_php_style.sh <file>...
# Set PHP_CS_FIXER to use a fixer binary that is not on PATH.

set -euo pipefail

if [ "$#" -eq 0 ]; then
	echo "Usage: $0 <file>..." >&2
	exit 2
fi

fixer=${PHP_CS_FIXER:-php-cs-fixer}

# shellcheck source=tests/tools/php_style_lib.sh
. "$(cd "$(dirname "$0")" && pwd)/php_style_lib.sh"

# Resolve the fixer before leaving the caller's directory, where a relative
# PHP_CS_FIXER path would no longer point at the binary.
if ! fixer_path=$(command -v "$fixer"); then
	echo "php-cs-fixer not found: $fixer" >&2
	exit 2
fi
case "$fixer_path" in
	/*) ;;
	*) fixer_path="$(pwd)/$fixer_path" ;;
esac

# Arguments are relative to the caller's directory. ls-tree resolves them from
# here and prints each path from the top of the repository; a directory comes
# back as a tree and is refused.
paths=()
head_modes=()
head_blobs=()
for arg in "$@"; do
	count=0
	entry=
	while IFS= read -r -d '' line; do
		count=$((count + 1))
		entry=$line
	done < <(git ls-tree -z --full-name HEAD -- "$arg" 2>/dev/null)
	meta=${entry%%$'\t'*}
	path=${entry#*$'\t'}
	case "$count:$meta" in
		1:100644\ blob\ * | 1:100755\ blob\ *) ;;
		*)
			printf 'Refusing %q: not a regular file at HEAD.\n' "$arg" >&2
			exit 2
			;;
	esac
	# The Finder's file list is read one path per line.
	case "$path" in
		*$'\n'*)
			printf 'Refusing a PHP path that contains a newline: %q\n' "$path" >&2
			exit 2
			;;
	esac
	dup=0
	j=0
	while [ "$j" -lt "${#paths[@]}" ]; do
		if [ "${paths[$j]}" = "$path" ]; then
			dup=1
		fi
		j=$((j + 1))
	done
	if [ "$dup" -eq 1 ]; then
		continue
	fi
	paths+=("$path")
	head_modes+=("${meta%% *}")
	head_blobs+=("${meta##* }")
done

root=$(git rev-parse --show-toplevel)
cd "$root"

if [ -n "$(git ls-files -u)" ]; then
	echo "The index has unmerged entries; finish or abort the merge first." >&2
	exit 2
fi

if [ -f .php-cs-fixer.php ]; then
	config=.php-cs-fixer.php
elif [ -f .php-cs-fixer.dist.php ]; then
	config=.php-cs-fixer.dist.php
else
	echo "No .php-cs-fixer.php or .php-cs-fixer.dist.php at the repository root." >&2
	exit 2
fi

included=$(php_style_list_files "$fixer_path" "$config")
for path in "${paths[@]}"; do
	if ! printf '%s\n' "$included" | grep -Fqx -- "$path"; then
		printf 'Refusing %s: the Finder in %s does not include it.\n' "$path" "$config" >&2
		exit 2
	fi
done

tmp=$(mktemp -d)
trap 'rm -rf "$tmp"' EXIT

# Fixes $1/$2 in place until a pass changes nothing, at most three passes,
# then requires a clean check. The copy lies outside the Finder, so override
# applies the rules to it.
format_copy() {
	local dir=$1 path=$2 pass=0 status
	while [ "$pass" -lt 3 ]; do
		pass=$((pass + 1))
		cp -- "$dir/$path" "$dir.prev"
		if ! (cd "$dir" && "$fixer_path" fix --config="$root/$config" --path-mode=override \
			--using-cache=no -- "$path") > "$tmp/fixer.log" 2>&1; then
			cat "$tmp/fixer.log" >&2
			return 1
		fi
		if cmp -s -- "$dir/$path" "$dir.prev"; then
			break
		fi
	done
	set +e
	(cd "$dir" && "$fixer_path" check --config="$root/$config" --path-mode=override \
		--using-cache=no -- "$path") > "$tmp/fixer.log" 2>&1
	status=$?
	set -e
	if [ "$status" -ne 0 ]; then
		cat "$tmp/fixer.log" >&2
		return 1
	fi
}

# Copies blob $1 to $2/$3, creating the directories the path needs.
blob_copy() {
	mkdir -p -- "$2/$(dirname -- "$3")"
	git cat-file blob "$1" > "$2/$3"
}

# Everything is computed first; HEAD, the index and the working tree are not
# updated until every file passes. git hash-object may leave unreachable blobs
# for garbage collection when a later file is refused.
# --index-info input, NUL-terminated so any path survives.
: > "$tmp/conversion-info"
: > "$tmp/index-info"
convert=()
worktree_from=()
worktree_to=()
i=0
while [ "$i" -lt "${#paths[@]}" ]; do
	path=${paths[$i]}
	dir="$tmp/$i"
	blob_copy "${head_blobs[$i]}" "$dir/head" "$path"

	set +e
	(cd "$dir/head" && "$fixer_path" check --config="$root/$config" --path-mode=override \
		--using-cache=no -- "$path") > "$tmp/fixer.log" 2>&1
	status=$?
	set -e
	if [ "$status" -eq 0 ]; then
		echo "Skipping $path: already PER-CS formatted at HEAD."
		i=$((i + 1))
		continue
	elif [ "$status" -ne 8 ]; then
		cat "$tmp/fixer.log" >&2
		echo "php-cs-fixer failed with status $status on $path at HEAD; nothing was changed." >&2
		exit 1
	fi

	blob_copy "${head_blobs[$i]}" "$dir/conv" "$path"
	if ! format_copy "$dir/conv" "$path"; then
		echo "Could not format $path at HEAD; nothing was changed." >&2
		exit 1
	fi
	if ! same_tokens "$dir/head/$path" "$dir/conv/$path"; then
		echo "Formatting $path changes more than whitespace; nothing was changed." >&2
		exit 1
	fi
	conv_blob=$(git hash-object -w --no-filters -- "$dir/conv/$path")
	convert+=("$path")
	printf '%s %s\t%s\0' "${head_modes[$i]}" "$conv_blob" "$path" >> "$tmp/conversion-info"

	# Carry the staged copy forward. An unstaged file follows the conversion;
	# a staged edit is formatted the same way. A staged deletion stays as is.
	staged=$(git ls-files -s -- ":(literal)$path")
	if [ -n "$staged" ]; then
		staged_mode=${staged%% *}
		staged_blob=${staged#* }
		staged_blob=${staged_blob%% *}
		if [ "$staged_blob" = "${head_blobs[$i]}" ]; then
			new_blob=$conv_blob
		else
			blob_copy "$staged_blob" "$dir/index" "$path"
			if ! format_copy "$dir/index" "$path"; then
				echo "Could not format the staged $path; nothing was changed." >&2
				exit 1
			fi
			new_blob=$(git hash-object -w --no-filters -- "$dir/index/$path")
		fi
		printf '%s %s\t%s\0' "$staged_mode" "$new_blob" "$path" >> "$tmp/index-info"
	fi

	# Carry the working-tree copy forward the same way.
	if [ -f "$path" ] && [ ! -L "$path" ]; then
		mkdir -p -- "$dir/tree/$(dirname -- "$path")"
		cp -- "$path" "$dir/tree/$path"
		if ! format_copy "$dir/tree" "$path"; then
			echo "Could not format $path in the working tree; nothing was changed." >&2
			exit 1
		fi
		if ! cmp -s -- "$path" "$dir/tree/$path"; then
			worktree_from+=("$dir/tree/$path")
			worktree_to+=("$path")
		fi
	fi
	i=$((i + 1))
done

if [ "${#convert[@]}" -eq 0 ]; then
	echo "Nothing to convert."
	exit 0
fi

if [ "${#convert[@]}" -eq 1 ]; then
	subject="style: convert ${convert[0]} to PER-CS 2.0"
	body=
else
	subject="style: convert ${#convert[@]} files to PER-CS 2.0"
	body=$(printf '%s\n' "${convert[@]}")
fi

# The commit sees only the temporary index, so the hooks check exactly the
# converted files and whatever the developer staged stays out of it.
conversion_index="$tmp/index"
GIT_INDEX_FILE=$conversion_index git read-tree HEAD
GIT_INDEX_FILE=$conversion_index git update-index -z --index-info < "$tmp/conversion-info"
if [ -n "$body" ]; then
	GIT_INDEX_FILE=$conversion_index git commit -q -s -m "$subject" -m "$body"
else
	GIT_INDEX_FILE=$conversion_index git commit -q -s -m "$subject"
fi

git update-index -z --index-info < "$tmp/index-info"
i=0
while [ "$i" -lt "${#worktree_to[@]}" ]; do
	# Writing through the existing file keeps its mode.
	cat -- "${worktree_from[$i]}" > "${worktree_to[$i]}"
	i=$((i + 1))
done

echo "Committed $(git rev-parse --short HEAD): $subject"
echo "Staged and working-tree edits to these files were reformatted to match."
