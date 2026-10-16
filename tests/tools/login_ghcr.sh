#!/usr/bin/env bash
# SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
# SPDX-License-Identifier: GPL-3.0-or-later
set -euo pipefail
set +x

: "${GHCR_TOKEN:?An authenticated GHCR token with read:packages is required.}"
: "${GHCR_USERNAME:?A GHCR username is required.}"
printf '%s' "$GHCR_TOKEN" | docker login ghcr.io --username "$GHCR_USERNAME" --password-stdin
