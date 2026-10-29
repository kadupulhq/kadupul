#!/usr/bin/env bash
# SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
# SPDX-License-Identifier: GPL-3.0-or-later
set -euo pipefail
root=$(cd "$(dirname "$0")/../.." && pwd)
work=$(mktemp -d)
trap 'rm -rf "$work"' EXIT

repo="$work/repo"
git init -q -b main "$repo"
g() {
	git -C "$repo" -c user.name=t -c user.email=t@example.invalid -c commit.gpgsign=false -c core.hooksPath=/dev/null "$@"
}
mkdir -p "$repo/docs/testing" "$repo/lib" "$repo/.github/workflows"
for file in README.md CHANGELOG.md docs/guide.md docs/testing/surface.md lib/a.php .github/workflows/ci.yml; do
	echo base > "$repo/$file"
done
g add -A && g commit -qm base
base=$(g rev-parse HEAD)

# Builds a test merge of a pull request whose head applies "$@" to the base,
# the shape actions/checkout gives a pull_request run.
pr_merge() {
	g checkout -q --detach "$base"
	"$@"
	g add -A && g commit -qm head
	head=$(g rev-parse HEAD)
	g checkout -q --detach "$base"
	g commit -q --allow-empty -m 'base advances'
	g merge -q --no-ff -m merge "$head"
}

scope() {
	local event=$1 head_sha=$2 relevant=${3:-}
	out="$work/out"
	: > "$out"
	(cd "$repo" && GITHUB_OUTPUT="$out" EVENT_NAME=$event PR_HEAD_SHA=$head_sha RELEVANT_PATHS=$relevant \
		bash "$root/tests/tools/ci_change_scope.sh" >/dev/null)
	tr '\n' ' ' < "$out"
}

expect() {
	local want=$1 got=$2 label=$3
	if [ "$got" != "$want" ]; then
		echo "FAIL: $label: got '$got', want '$want'" >&2
		exit 1
	fi
}

edit() { for file in "$@"; do echo change >> "$repo/$file"; done; }
docs_only() { edit docs/guide.md README.md; mkdir -p "$repo/docs/new"; echo new > "$repo/docs/new/page.md"; }
changelog_only() { edit CHANGELOG.md; }
php_change() { edit lib/a.php docs/guide.md; }
workflow_change() { edit .github/workflows/ci.yml; }
generated_doc() { edit docs/testing/surface.md; }
symlinked_doc() { ln -s ../lib/a.php "$repo/docs/link.md"; }
removed_doc() { rm "$repo/docs/guide.md"; }
moved_into_code() { g mv docs/guide.md lib/guide.md; }

pr_merge docs_only
expect 'code=false relevant=false reason=prose-only ' "$(scope pull_request "$head")" 'documentation'
expect 'code=false relevant=false reason=prose-only ' "$(scope pull_request "$head" 'docs/*')" 'documentation never matches narrowed globs'
pr_merge changelog_only
expect 'code=false relevant=false reason=prose-only ' "$(scope pull_request "$head")" 'changelog'
pr_merge removed_doc
expect 'code=false relevant=false reason=prose-only ' "$(scope pull_request "$head")" 'removed document'
echo 'PASS: prose-only pull requests skip'

pr_merge php_change
expect 'code=true relevant=true reason=code-change ' "$(scope pull_request "$head")" 'PHP change'
expect 'code=true relevant=true reason=code-change ' "$(scope pull_request "$head" $'cli/*\nlib/*')" 'matching glob'
expect 'code=true relevant=false reason=code-change ' "$(scope pull_request "$head" 'cli/*')" 'unmatched glob'
for case in generated_doc symlinked_doc moved_into_code; do
	pr_merge "$case"
	expect 'code=true relevant=true reason=code-change ' "$(scope pull_request "$head")" "$case"
done
pr_merge workflow_change
expect 'code=true relevant=true reason=shared-input ' "$(scope pull_request "$head" 'cli/*')" 'workflow change'
echo 'PASS: code and shared inputs run'

pr_merge docs_only
for event in push merge_group schedule workflow_dispatch ''; do
	expect "code=true relevant=true reason=event-${event:-unknown} " "$(scope "$event" "$head")" "event '$event'"
done
expect 'code=true relevant=true reason=unverified-checkout ' "$(scope pull_request "$base")" 'second parent mismatch'
expect 'code=true relevant=true reason=unverified-checkout ' "$(scope pull_request 'not-a-sha')" 'malformed head'
g checkout -q --detach "$head"
expect 'code=true relevant=true reason=unverified-checkout ' "$(scope pull_request "$head")" 'checkout is not a merge'
echo 'PASS: other events and unverified checkouts run everything'
