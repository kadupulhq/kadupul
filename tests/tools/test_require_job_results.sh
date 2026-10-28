#!/usr/bin/env bash
# SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
# SPDX-License-Identifier: GPL-3.0-or-later
set -euo pipefail
root=$(cd "$(dirname "$0")/../.." && pwd)
gate() {
	NEEDS_JSON=$1 ALLOWED_SKIPS=${2:-} bash "$root/tests/tools/require_job_results.sh"
}

gate '{"build":{"result":"success","outputs":{}},"test":{"result":"success","outputs":{}}}' >/dev/null
gate '{"build":{"result":"success"},"changelog":{"result":"skipped"}}' 'docs changelog' >/dev/null
echo 'PASS: success and allowed skips report success'

for case in failure cancelled skipped; do
	if gate "{\"build\":{\"result\":\"success\"},\"test\":{\"result\":\"$case\"}}" 'changelog' >/dev/null; then
		echo "FAIL: $case was accepted" >&2
		exit 1
	fi
done
if gate '{"test":{"result":"failure"}}' 'test' >/dev/null; then
	echo 'FAIL: an allowed skip excused a failure' >&2
	exit 1
fi
if gate '{"test-e2e":{"result":"skipped"}}' 'test' >/dev/null; then
	echo 'FAIL: a skip allowance matched a longer job name' >&2
	exit 1
fi
echo 'PASS: failures, cancellations and unlisted skips fail'

for invalid in '{}' '[]' 'not-json' ''; do
	if gate "$invalid" >/dev/null 2>&1; then
		echo "FAIL: invalid input '$invalid' was accepted" >&2
		exit 1
	fi
done
echo 'PASS: empty or malformed results fail'
