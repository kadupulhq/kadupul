#!/usr/bin/env python3
# SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
# SPDX-License-Identifier: GPL-3.0-or-later
"""Protect the manifest identity and private authentication handoff."""
import hashlib
import importlib.util
import io
import json
import os
from pathlib import Path
import re
import subprocess
import tarfile
import tempfile
import unittest
from unittest.mock import patch

ROOT = Path(__file__).resolve().parents[2]
spec = importlib.util.spec_from_file_location('container_mirror', ROOT / 'tools/containers/mirror.py')
mirror = importlib.util.module_from_spec(spec)
spec.loader.exec_module(mirror)
build_spec = importlib.util.spec_from_file_location('mirror_build', ROOT / 'tools/containers/verify_build.py')
build_proof = importlib.util.module_from_spec(build_spec)
build_spec.loader.exec_module(build_proof)


class MirrorBuildProofTest(unittest.TestCase):
    def proof(self, missing=None, invalid_spdx=False, corrupt=False):
        blobs = {}

        def blob(document, media='application/vnd.oci.image.manifest.v1+json'):
            payload = json.dumps(document).encode()
            digest = 'sha256:' + hashlib.sha256(payload).hexdigest()
            blobs['blobs/sha256/' + digest[7:]] = payload
            return {'mediaType': media, 'digest': digest, 'size': len(payload)}

        sbom = blob({'predicateType': 'https://spdx.dev/Document',
                     'predicate': {'spdxVersion': '' if invalid_spdx else 'SPDX-2.3'}}, 'application/vnd.in-toto+json')
        descriptors = []
        for architecture in ('amd64', 'arm64'):
            config = blob({'architecture': architecture, 'os': 'linux'}, 'application/vnd.oci.image.config.v1+json')
            image = blob({'schemaVersion': 2, 'config': config, 'layers': []})
            image['platform'] = {'architecture': architecture, 'os': 'linux'}
            descriptors.append(image)
            if missing == architecture:
                continue
            attestation = blob({'schemaVersion': 2, 'config': config, 'layers': [sbom]})
            attestation['annotations'] = {'vnd.docker.reference.type': 'attestation-manifest',
                                          'vnd.docker.reference.digest': image['digest']}
            descriptors.append(attestation)
        # OCI indexes can wrap another index, as Buildx's exporter does.
        index = blob({'schemaVersion': 2, 'manifests': descriptors}, 'application/vnd.oci.image.index.v1+json')
        blobs['index.json'] = json.dumps({'schemaVersion': 2, 'manifests': [index]}).encode()
        if corrupt:
            blobs['blobs/sha256/' + sbom['digest'][7:]] += b' '
        with tempfile.TemporaryDirectory() as directory:
            path = Path(directory) / 'proof.tar'
            with tarfile.open(path, 'w') as archive:
                for name, payload in blobs.items():
                    member = tarfile.TarInfo(name)
                    member.size = len(payload)
                    archive.addfile(member, io.BytesIO(payload))
            return build_proof.verify(path)

    def test_platforms_can_share_one_spdx_blob(self):
        self.assertIn('amd64/arm64', self.proof())

    def test_missing_platform_attestation_is_refused(self):
        with self.assertRaisesRegex(ValueError, 'attestation for arm64'):
            self.proof(missing='arm64')

    def test_invalid_spdx_document_is_refused(self):
        with self.assertRaisesRegex(ValueError, 'Missing generated SPDX'):
            self.proof(invalid_spdx=True)

    def test_corrupted_oci_blob_is_refused(self):
        with self.assertRaisesRegex(ValueError, 'digest mismatch'):
            self.proof(corrupt=True)


class ContainerMirrorTest(unittest.TestCase):
    def test_live_container_references_have_admitted_ghcr_digests(self):
        admitted = {(image['destination'].split(':', 1)[0], image['digest']) for image in mirror.images()}
        files = subprocess.run(['git', 'ls-files', '-z'], cwd=ROOT, capture_output=True, check=True).stdout.decode().split('\0')
        checked = 0
        for filename in files:
            path = Path(filename)
            dockerfile = path.name.startswith('Dockerfile')
            yaml = (filename.startswith('.github/workflows/') or 'compose' in path.name) and path.suffix in ('.yml', '.yaml')
            python = filename == 'tests/security/nginx_private_paths.py'
            if not (dockerfile or yaml or python) or filename.startswith('tests/behavior/evidence/'):
                continue
            source = (ROOT / path).read_text()
            references = []
            if dockerfile:
                for line in source.splitlines():
                    if line.startswith('FROM '):
                        reference = line.split()[1]
                        if reference in ('php-${PHP_VERSION}', 'scratch'):
                            continue
                        references.append(reference)
                    references.extend(re.findall(r'--from=([^\s]+/[^\s]+)', line))
            elif yaml:
                references = re.findall(r'^\s*image:\s*([^\s]+)', source, re.MULTILINE)
                references.extend(re.findall(r'^\s*(?:buildkit-image|sbom-generator|driver-opts|sbom):\s*(?:image=|generator=)?([^\s]+)', source, re.MULTILINE))
                references = [ref for ref in references if ref not in ('${{', 'true', 'false', 'cacti-e2e:latest')]
            else:
                references = re.findall(r"ghcr\.io/[^'\s]+", source)
            for reference in references:
                with self.subTest(file=filename, image=reference):
                    self.assertTrue(reference.startswith(mirror.PREFIX), reference)
                    repository, separator, digest = reference.partition('@')
                    self.assertEqual('@', separator, reference)
                    self.assertIn((repository.split(':', 1)[0], digest), admitted)
                    checked += 1
        self.assertGreaterEqual(checked, 25, 'Container reference discovery must not be empty')

    def test_login_helper_uses_stdin_and_refuses_missing_credentials(self):
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            docker = root / 'docker'
            docker.write_text('#!/bin/sh\nprintf "%s\\n" "$@" > "$AUTH_ARGV"\ncat > "$AUTH_STDIN"\n')
            docker.chmod(0o700)
            environment = {**os.environ, 'PATH': str(root) + os.pathsep + os.environ['PATH'],
                           'GHCR_TOKEN': 'fixture-read-token', 'GHCR_USERNAME': 'fixture',
                           'AUTH_ARGV': str(root / 'arguments'), 'AUTH_STDIN': str(root / 'input')}
            command = ['bash', str(ROOT / 'tests/tools/login_ghcr.sh')]
            result = subprocess.run(command, env=environment, capture_output=True, text=True)
            self.assertEqual(0, result.returncode, result.stderr)
            self.assertEqual('fixture-read-token', (root / 'input').read_text())
            self.assertEqual(['login', 'ghcr.io', '--username', 'fixture', '--password-stdin'], (root / 'arguments').read_text().splitlines())
            self.assertNotIn('fixture-read-token', result.stdout + result.stderr)
            (root / 'arguments').unlink()
            environment.pop('GHCR_TOKEN')
            result = subprocess.run(command, env=environment, capture_output=True, text=True)
            self.assertNotEqual(0, result.returncode)
            self.assertFalse((root / 'arguments').exists())

    def test_inventory_contains_nineteen_unique_immutable_destinations(self):
        images = mirror.images()
        self.assertEqual(19, len(images))
        self.assertEqual(19, len({image['destination'] for image in images}))
        for image in images:
            self.assertTrue(image['source'].endswith('@' + image['digest']))
            self.assertTrue(image['destination'].endswith(image['digest'][7:]))

    def test_unreviewed_destinations_and_duplicate_inventory_are_refused(self):
        original = json.loads((ROOT / 'tools/containers/mirrors.json').read_text())
        for mutation in ('source', 'destination', 'duplicate', 'registry_host'):
            inventory = json.loads(json.dumps(original))
            if mutation == 'duplicate':
                inventory['images'].append(inventory['images'][0])
            elif mutation == 'registry_host':
                inventory['images'][0]['source'] = inventory['images'][0]['source'].replace('docker.io/', 'dockerXio/')
            else:
                inventory['images'][0][mutation] = 'unreviewed.invalid/image'
            with self.subTest(mutation=mutation), patch.object(Path, 'read_text', return_value=json.dumps(inventory)):
                with self.assertRaises(ValueError):
                    mirror.images()

    def measured_publish(self, observed, repository='library/php', tag='8.4-apache', first_unavailable=False):
        manifest = b'{"schemaVersion":2}'
        digest = 'sha256:' + hashlib.sha256(manifest).hexdigest()
        name = repository.rsplit('/', 1)[-1]
        image = {'source': 'docker.io/' + repository + ':' + tag + '@' + digest,
                 'destination': mirror.PREFIX + name + ':sha256-' + digest[7:], 'digest': digest}
        calls = []

        def run(command, **arguments):
            calls.append((command, arguments))
            if command[1] == 'copy' and len(calls) == 2 and first_unavailable:
                return subprocess.CompletedProcess(command, 1, stdout=b'', stderr=b'fixture: upstream unavailable')
            return subprocess.CompletedProcess(command, 0, stdout=observed if command[1] == 'inspect' else b'')

        with patch.dict(os.environ, {'GHCR_TOKEN': 'test-only-value', 'GHCR_USERNAME': 'fixture'}, clear=True), patch.object(subprocess, 'run', side_effect=run):
            receipt = mirror.publish(image)
        self.assertEqual(digest, receipt['verified_digest'])
        self.assertEqual('test-only-value', calls[0][1]['input'])
        self.assertNotIn('test-only-value', json.dumps([command for command, _ in calls]))
        self.assertIn('--all', calls[1][0])
        self.assertIn('--preserve-digests', calls[1][0])
        self.assertIn('docker://mirror.gcr.io/' + repository + '@' + digest, calls[1][0])
        registry = 'public.ecr.aws/docker' if first_unavailable else 'mirror.gcr.io'
        self.assertEqual(registry + '/' + repository + '@' + digest, receipt['copied_from'])
        if first_unavailable:
            self.assertIn('docker://public.ecr.aws/docker/' + repository + '@' + digest, calls[2][0])
        self.assertEqual('docker://' + mirror.PREFIX + name + ':' + tag, calls[-1][0][-1])
        self.assertEqual('docker://' + mirror.PREFIX + name + '@' + digest, calls[-1][0][-2])
        self.assertFalse(Path(calls[0][1]['env']['REGISTRY_AUTH_FILE']).parent.exists())

    def test_authenticated_copy_preserves_every_platform_and_checks_remote_bytes(self):
        self.measured_publish(b'{"schemaVersion":2}')

    def test_non_library_builder_aliases_stay_in_ghcr(self):
        for repository in ('moby/buildkit', 'docker/buildkit-syft-scanner'):
            with self.subTest(repository=repository):
                self.measured_publish(b'{"schemaVersion":2}', repository, 'stable-1')

    def test_unavailable_upstream_tries_the_next_admitted_source(self):
        self.measured_publish(b'{"schemaVersion":2}', first_unavailable=True)

    def test_cached_ghcr_bytes_cannot_replace_upstream_provenance(self):
        manifest = b'{"schemaVersion":2}'
        digest = 'sha256:' + hashlib.sha256(manifest).hexdigest()
        image = {'source': 'docker.io/library/php:8.4-apache@' + digest,
                 'destination': mirror.PREFIX + 'php:sha256-' + digest[7:], 'digest': digest}
        calls = []

        def run(command, **arguments):
            calls.append(command)
            if command[1] == 'copy':
                cached = command[-2].startswith('docker://' + mirror.PREFIX)
                return subprocess.CompletedProcess(command, 0 if cached else 1, stdout=b'', stderr=b'upstream unavailable')
            return subprocess.CompletedProcess(command, 0, stdout=manifest)

        with patch.dict(os.environ, {'GHCR_TOKEN': 'test-only-value', 'GHCR_USERNAME': 'fixture'}, clear=True), patch.object(subprocess, 'run', side_effect=run):
            with self.assertRaisesRegex(RuntimeError, 'No upstream served'):
                mirror.publish(image)
        self.assertEqual(3, len([command for command in calls if command[1] == 'copy']))
        self.assertFalse(any(command[1] == 'inspect' for command in calls))

    def test_remote_digest_mismatch_never_produces_verified_receipt(self):
        with self.assertRaisesRegex(RuntimeError, 'differs from the reviewed digest'):
            self.measured_publish(b'changed manifest')


if __name__ == '__main__':
    unittest.main()
