#!/usr/bin/env bash
# SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
# SPDX-License-Identifier: GPL-3.0-or-later
set -euo pipefail

ROOT_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/../.." &> /dev/null && pwd)"
BASELINE="${1:-${ROOT_DIR}/tests/security/baselines/entry_points.baseline.tsv}"
PYTHON="${PYTHON:-python3}"
TMP_CUR="$(mktemp)"
TMP_BASELINE="$(mktemp)"
TMP_DIFF="$(mktemp)"
trap 'rm -f "$TMP_CUR" "$TMP_BASELINE" "$TMP_DIFF"' EXIT

if [ ! -f "$BASELINE" ]; then
	echo "ERROR: baseline not found: $BASELINE" >&2
	exit 1
fi

tr -d '\r' < "$BASELINE" | LC_ALL=C sort -u > "$TMP_BASELINE"
"$PYTHON" "${ROOT_DIR}/tests/security/build_entry_point_inventory.py" | tr -d '\r' | LC_ALL=C sort -u > "$TMP_CUR"

status=0

# An unclassified entry point is a gap even when someone baselined it.
# awk reports the match in its exit status. grep -q in a pipe can exit early,
# and under pipefail the SIGPIPE would read as "no unknown entries".
if awk -F '\t' '$2 == "unknown" { found = 1 } END { exit !found }' "$TMP_CUR"; then
	echo "ERROR: entry points without a recognised gate:"
	awk -F '\t' '$2 == "unknown" { print "  " $1 "\t" $3 }' "$TMP_CUR"
	status=1
fi

if ! diff -u "$TMP_BASELINE" "$TMP_CUR" > "$TMP_DIFF"; then
	echo "ERROR: entry-point inventory drift detected:"
	cat "$TMP_DIFF"
	echo "If intentional, review each changed gate and refresh the baseline:"
	echo "  python3 tests/security/build_entry_point_inventory.py > tests/security/baselines/entry_points.baseline.tsv"
	status=1
fi

if [ "$status" -eq 0 ]; then
	echo "OK: entry-point inventory matches baseline ($(($(wc -l < "$TMP_CUR") - 1)) entries, none unknown)"
fi
exit "$status"
