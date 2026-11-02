#!/usr/bin/env bash
# SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
# SPDX-License-Identifier: GPL-3.0-or-later
set -euo pipefail

: "${RUNNER_TEMP:?}" "${GITHUB_RUN_ID:?}" "${GITHUB_RUN_ATTEMPT:?}"

# Keep browser receipts in their original checkout. Each complete session run
# owns its containers and PHP reports; browser reports use unique filenames.
mise exec python@3.12.12 -- python tests/Symfony/session_bridge.py \
  --project "kadupul-sonar-${GITHUB_RUN_ID}-${GITHUB_RUN_ATTEMPT}-files" \
  --coverage-output "$RUNNER_TEMP/symfony-files" &
files_pid=$!
mise exec python@3.12.12 -- python tests/Symfony/session_bridge.py \
  --project "kadupul-sonar-${GITHUB_RUN_ID}-${GITHUB_RUN_ATTEMPT}-database" \
  --database-sessions --coverage-output "$RUNNER_TEMP/symfony-database" &
database_pid=$!

# Wait for both producers even when the first fails. A partial run must never
# reach the merger, and the other harness must finish its container cleanup.
status=0
wait "$files_pid" || status=1
wait "$database_pid" || status=1
exit "$status"
