#!/usr/bin/env bash
# SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
# SPDX-License-Identifier: GPL-3.0-or-later
#
# Fails unless every job in NEEDS_JSON (toJSON(needs) of an aggregate job)
# succeeded. ALLOWED_SKIPS names the jobs whose skip is intentional for this
# event; any other skip, including one caused by an upstream failure, fails.
# GitHub treats a skipped required check as passing, so the aggregate job that
# calls this must itself always run.
set -euo pipefail

: "${NEEDS_JSON:?NEEDS_JSON must hold the toJSON(needs) of the calling job.}"
allowed=" ${ALLOWED_SKIPS:-} "

results=$(jq -r 'to_entries[] | "\(.key) \(.value.result)"' <<< "$NEEDS_JSON")
if [ -z "$results" ]; then
	echo '::error::No upstream job results were provided.'
	exit 1
fi

status=0
while read -r job result; do
	if [ "$result" = success ]; then
		echo "$job: success"
	elif [ "$result" = skipped ] && [[ "$allowed" == *" $job "* ]]; then
		echo "$job: skipped by design for this event"
	else
		echo "::error::$job finished with result '$result'."
		status=1
	fi
done <<< "$results"
exit "$status"
