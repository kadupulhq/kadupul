#!/usr/bin/env bash
# SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
# SPDX-License-Identifier: GPL-3.0-or-later
#
# Decides which expensive pull request jobs a change can affect and writes
# code=, relevant= and reason= to GITHUB_OUTPUT.
#
# code=false only when every changed file is prose no job reads. Anything
# else, any event other than pull_request (merge groups are the last gate),
# and any doubt about the checkout or the diff yields code=true. RELEVANT_PATHS
# optionally narrows relevant= to a workflow's own inputs (newline-separated
# globs); without it relevant= equals code=. Toolchain, dependency, container
# and CI changes are relevant to every workflow.
#
# The caller checks out the pull request's test merge with fetch-depth 2, so
# HEAD^1 is the base tip and HEAD^2 must be the pull request head.
set -euo pipefail

: "${GITHUB_OUTPUT:?GITHUB_OUTPUT must name the step output file.}"

emit() {
	printf 'code=%s\nrelevant=%s\nreason=%s\n' "$1" "$2" "$3" >> "$GITHUB_OUTPUT"
	echo "code=$1 relevant=$2 ($3)"
	exit 0
}

full() {
	emit true true "$1"
}

# Generated files and documents that tests or tools read stay out of this
# list; a new consumer of a listed path must remove it here.
is_prose() {
	case "$1" in
		docs/testing/*|docs/reviews/*|docs/migration/functions-inventory/*|docs/cdef-reference-integrity.md) return 1 ;;
		docs/*.md|README.md|CHANGELOG.md|CONTRIBUTING.md|CODE_OF_CONDUCT.md|SECURITY.md|VERSIONING.md) return 0 ;;
	esac
	return 1
}

affects_every_workflow() {
	case "$1" in
		.github/*|tests/tools/*|mise.toml|.node-version|composer.json|composer.lock|*/composer.json|*/composer.lock|package.json|package-lock.json|*/package.json|*/package-lock.json|Dockerfile*|*/Dockerfile*|.dockerignore|*/.dockerignore) return 0 ;;
	esac
	return 1
}

matches_relevant() {
	local pattern
	while IFS= read -r pattern; do
		[ -z "$pattern" ] && continue
		# The unquoted pattern is intentional: case applies it as a glob.
		# shellcheck disable=SC2254
		case "$1" in
			$pattern) return 0 ;;
		esac
	done <<< "$RELEVANT_PATHS"
	return 1
}

[ "${EVENT_NAME:-}" = pull_request ] || full "event-${EVENT_NAME:-unknown}"
[[ "${PR_HEAD_SHA:-}" =~ ^[0-9a-f]{40}$ ]] || full unverified-checkout
second_parent=$(git rev-parse --verify --quiet 'HEAD^2^{commit}' 2>/dev/null) || full unverified-checkout
git rev-parse --verify --quiet 'HEAD^3^{commit}' >/dev/null 2>&1 && full unverified-checkout
[ "$second_parent" = "$PR_HEAD_SHA" ] || full unverified-checkout

raw=$(mktemp)
trap 'rm -f "$raw"' EXIT
git diff --raw -z --no-renames --no-abbrev --no-ext-diff 'HEAD^1' HEAD -- > "$raw" 2>/dev/null || full unreadable-change
[ -s "$raw" ] || full empty-change

code=false
relevant=false
while IFS= read -r -d '' header && IFS= read -r -d '' path; do
	read -r old_mode new_mode _ _ _ <<< "${header#:}"
	if ! { [ "$old_mode" = 100644 ] || [ "$old_mode" = 000000 ]; } ||
		! { [ "$new_mode" = 100644 ] || [ "$new_mode" = 000000 ]; } ||
		! is_prose "$path"; then
		code=true
	fi
	if affects_every_workflow "$path"; then
		full "shared-input"
	fi
	if [ -n "${RELEVANT_PATHS:-}" ] && matches_relevant "$path"; then
		relevant=true
	fi
done < "$raw"

if [ -z "${RELEVANT_PATHS:-}" ]; then
	relevant=$code
fi
# Prose cannot make a narrowed workflow relevant, whatever its globs say.
[ "$code" = true ] || relevant=false
emit "$code" "$relevant" "$([ "$code" = true ] && echo code-change || echo prose-only)"
