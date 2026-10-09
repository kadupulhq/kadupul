#!/usr/bin/env bash
# SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
# SPDX-License-Identifier: GPL-3.0-or-later
set -euo pipefail

# Only ephemeral GitHub-hosted Linux daemons belong to this job. Preserve
# operator-managed self-hosted and local Docker configuration.
if [[ ${GITHUB_ACTIONS:-} != true || ${RUNNER_ENVIRONMENT:-} != github-hosted || ${RUNNER_OS:-} != Linux ]]; then
    echo 'Hosted Docker cache setup does not apply to this runner.'
    exit 0
fi

configuration=$(mktemp)
updated=$(mktemp)
trap 'rm -f "$configuration" "$updated"' EXIT
if sudo test -f /etc/docker/daemon.json; then
    sudo cat /etc/docker/daemon.json | tee "$configuration" >/dev/null
else
    printf '{}\n' > "$configuration"
fi
jq '. + {"registry-mirrors": (["https://mirror.gcr.io"] + (."registry-mirrors" // []) | unique)}' \
    "$configuration" > "$updated"
sudo dockerd --validate --config-file "$updated"
daemon_pid=$(sudo systemctl show docker.service --property MainPID --value)
[[ $daemon_pid =~ ^[0-9]+$ && $daemon_pid -gt 1 ]]
sudo install -m 0644 "$updated" /etc/docker/daemon.json
# Docker supports reloading registry mirrors with SIGHUP. Do not restart the
# daemon or interrupt containers that GitHub already started for this job.
sudo kill -HUP "$daemon_pid"
for attempt in {1..20}; do
    if docker info --format '{{json .RegistryConfig.Mirrors}}' | \
        jq -e 'map(rtrimstr("/")) | index("https://mirror.gcr.io") != null' >/dev/null; then
        echo 'Docker Hub public cache enabled; original image references and digests are preserved.'
        exit 0
    fi
    sleep 0.5
done
echo "Docker did not load the requested public cache configuration after $attempt checks." >&2
exit 1
