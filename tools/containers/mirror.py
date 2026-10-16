#!/usr/bin/env python3
# SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
# SPDX-License-Identifier: GPL-3.0-or-later
"""Copy reviewed upstream manifests to GHCR without changing their digests."""
import argparse
import hashlib
import json
import os
from pathlib import Path
import re
import subprocess
import tempfile

ROOT = Path(__file__).resolve().parents[2]
PREFIX = 'ghcr.io/kadupulhq/kadupul-mirrors/'


def images():
    specification = json.loads((ROOT / 'tools/containers/mirrors.json').read_text())
    if specification.get('version') != 1 or not isinstance(specification.get('images'), list) or not specification['images']:
        raise ValueError('Missing mirror inventory')
    seen = set()
    for image in specification['images']:
        if set(image) != {'source', 'destination', 'digest'}:
            raise ValueError('Unexpected mirror fields')
        if not re.fullmatch(r'sha256:[0-9a-f]{64}', image['digest']):
            raise ValueError('Invalid mirror digest')
        source = re.fullmatch(r'docker.io/library/(php|node|composer|mariadb|mysql|nginx)(?::[a-zA-Z0-9_.-]+)?@' + image['digest'], image['source'])
        if source is None or image['destination'] != PREFIX + source[1] + ':sha256-' + image['digest'][7:]:
            raise ValueError('Mirror must retain the reviewed official-image identity')
        if image['destination'] in seen:
            raise ValueError('Duplicate mirror destination')
        seen.add(image['destination'])
    return specification['images']


def publish(image):
    token = os.environ.get('GHCR_TOKEN', '')
    username = os.environ.get('GHCR_USERNAME', '')
    if not token or not re.fullmatch(r'[a-zA-Z0-9_-]{1,100}(?:\[bot\])?', username):
        raise RuntimeError('GHCR_TOKEN and GHCR_USERNAME are required')
    with tempfile.TemporaryDirectory(prefix='kadupul-ghcr-') as directory:
        authentication = Path(directory) / 'auth.json'
        environment = {**os.environ, 'REGISTRY_AUTH_FILE': str(authentication)}
        # Password input never appears in argv, output, reports or source files.
        subprocess.run(['skopeo', 'login', '--username', username, '--password-stdin', 'ghcr.io'],
                       input=token, text=True, capture_output=True, env=environment, check=True, timeout=60)
        copied = False
        for registry in ('mirror.gcr.io', 'public.ecr.aws/docker', 'docker.io'):
            # containers/image rejects references containing both tag and digest.
            # Fetch the immutable identity; retain the version tag in the inventory.
            repository = image['source'].split('@', 1)[0].split(':', 1)[0]
            source = repository.replace('docker.io/', registry + '/', 1) + '@' + image['digest']
            command = ['skopeo', 'copy', '--all', '--preserve-digests', '--retry-times', '3',
                       'docker://' + source, 'docker://' + image['destination']]
            result = subprocess.run(command, capture_output=True, env=environment, timeout=2400)
            if result.returncode == 0:
                copied = True
                break
            diagnostic = result.stderr.decode('utf-8', errors='replace').replace(token, '[redacted]')
            diagnostic = re.sub(r'[\x00-\x1f\x7f]', ' ', diagnostic)[-1500:]
            print('Pinned copy unavailable from ' + registry + ': ' + diagnostic, flush=True)
        if not copied:
            raise RuntimeError('No upstream served the reviewed immutable image')
        manifest = subprocess.run(['skopeo', 'inspect', '--raw', 'docker://' + image['destination']],
                                  capture_output=True, env=environment, check=True, timeout=120).stdout
        observed = 'sha256:' + hashlib.sha256(manifest).hexdigest()
        if observed != image['digest']:
            raise RuntimeError('Published mirror manifest differs from the reviewed digest')
        versioned = image['source'].split('@', 1)[0]
        if ':' in versioned:
            # Preserve upstream family tags for dependency tooling, but only after
            # the admitted immutable manifest has been verified.
            alias = versioned.replace('docker.io/library/', PREFIX, 1)
            subprocess.run(['skopeo', 'copy', '--all', '--preserve-digests', '--retry-times', '3',
                            'docker://' + image['destination'], 'docker://' + alias],
                           capture_output=True, env=environment, check=True, timeout=600)
        return {**image, 'verified_digest': observed}


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--matrix', action='store_true')
    parser.add_argument('--destination')
    parser.add_argument('--report', type=Path)
    arguments = parser.parse_args()
    inventory = images()
    if arguments.matrix:
        print(json.dumps({'image': inventory}, separators=(',', ':')))
        return
    selected = [image for image in inventory if image['destination'] == arguments.destination]
    if len(selected) != 1 or arguments.report is None:
        parser.error('Select one reviewed destination and a report path')
    receipt = publish(selected[0])
    arguments.report.write_text(json.dumps(receipt, indent=2) + '\n')
    print('Verified ' + receipt['destination'] + ' at ' + receipt['verified_digest'])


if __name__ == '__main__':
    main()
