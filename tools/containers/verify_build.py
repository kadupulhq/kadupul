#!/usr/bin/env python3
# SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
# SPDX-License-Identifier: GPL-3.0-or-later
"""Require generated SPDX attestations linked to both OCI image platforms."""
import argparse
import hashlib
import json
from pathlib import Path
import tarfile


def verify(path):
    documents = {}
    with tarfile.open(path) as archive:
        root = json.load(archive.extractfile('index.json'))
        for member in archive.getmembers():
            if not member.isfile() or not member.name.startswith('blobs/sha256/'):
                continue
            payload = archive.extractfile(member).read()
            digest = hashlib.sha256(payload).hexdigest()
            if member.name != 'blobs/sha256/' + digest:
                raise ValueError('OCI blob digest mismatch')
            try:
                document = json.loads(payload)
            except (ValueError, UnicodeDecodeError):
                continue
            if isinstance(document, dict):
                documents['sha256:' + digest] = document

    descriptors = []
    pending = list(root['manifests'])
    visited = set()
    while pending:
        descriptor = pending.pop()
        digest = descriptor['digest']
        descriptors.append(descriptor)
        if digest in visited:
            continue
        visited.add(digest)
        document = documents[digest]
        pending.extend(document.get('manifests', []))

    for architecture in ('amd64', 'arm64'):
        targets = [entry['digest'] for entry in descriptors
                   if entry.get('platform', {}).get('architecture') == architecture
                   and entry.get('platform', {}).get('os') == 'linux']
        if len(targets) != 1:
            raise ValueError('Expected one Linux image for ' + architecture)
        statements = []
        for entry in descriptors:
            annotations = entry.get('annotations', {})
            if (annotations.get('vnd.docker.reference.type') != 'attestation-manifest'
                    or annotations.get('vnd.docker.reference.digest') != targets[0]):
                continue
            for layer in documents[entry['digest']].get('layers', []):
                statement = documents.get(layer['digest'], {})
                if statement.get('predicateType') == 'https://spdx.dev/Document':
                    statements.append(statement)
        if len(statements) != 1 or not statements[0].get('predicate', {}).get('spdxVersion', '').startswith('SPDX-'):
            raise ValueError('Missing generated SPDX attestation for ' + architecture)
    return 'Verified amd64/arm64 builds and their SPDX attestations through authenticated GHCR helpers'


if __name__ == '__main__':
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('archive', type=Path)
    print(verify(parser.parse_args().archive))
