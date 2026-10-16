#!/usr/bin/env python3
# SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
# SPDX-License-Identifier: GPL-3.0-or-later
"""Protect the manifest identity and private authentication handoff."""
import hashlib
import importlib.util
import json
import os
from pathlib import Path
import re
import subprocess
import tempfile
import unittest
from unittest.mock import patch

ROOT = Path(__file__).resolve().parents[2]
spec = importlib.util.spec_from_file_location('container_mirror', ROOT / 'tools/containers/mirror.py')
mirror = importlib.util.module_from_spec(spec)
spec.loader.exec_module(mirror)


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
        for mutation in ('source', 'destination', 'duplicate'):
            inventory = json.loads(json.dumps(original))
            if mutation == 'duplicate':
                inventory['images'].append(inventory['images'][0])
            else:
                inventory['images'][0][mutation] = 'unreviewed.invalid/image'
            with self.subTest(mutation=mutation), patch.object(Path, 'read_text', return_value=json.dumps(inventory)):
                with self.assertRaises(ValueError):
                    mirror.images()

    def measured_publish(self, observed, repository='library/php', tag='8.4-apache', missing_mirror=False):
        manifest = b'{"schemaVersion":2}'
        digest = 'sha256:' + hashlib.sha256(manifest).hexdigest()
        name = repository.rsplit('/', 1)[-1]
        image = {'source': 'docker.io/' + repository + ':' + tag + '@' + digest,
                 'destination': mirror.PREFIX + name + ':sha256-' + digest[7:], 'digest': digest}
        calls = []

        def run(command, **arguments):
            calls.append((command, arguments))
            if command[1] == 'copy' and len(calls) == 2 and missing_mirror:
                return subprocess.CompletedProcess(command, 1, stdout=b'', stderr=b'fixture: mirror not found')
            return subprocess.CompletedProcess(command, 0, stdout=observed if command[1] == 'inspect' else b'')

        with patch.dict(os.environ, {'GHCR_TOKEN': 'test-only-value', 'GHCR_USERNAME': 'fixture'}, clear=True), patch.object(subprocess, 'run', side_effect=run):
            receipt = mirror.publish(image)
        self.assertEqual(digest, receipt['verified_digest'])
        self.assertEqual('test-only-value', calls[0][1]['input'])
        self.assertNotIn('test-only-value', json.dumps([command for command, _ in calls]))
        self.assertIn('--all', calls[1][0])
        self.assertIn('--preserve-digests', calls[1][0])
        self.assertIn('docker://' + mirror.PREFIX + name + '@' + digest, calls[1][0])
        if missing_mirror:
            self.assertIn('docker://mirror.gcr.io/' + repository + '@' + digest, calls[2][0])
        else:
            self.assertFalse(any('mirror.gcr.io' in argument for command, _ in calls for argument in command))
        self.assertEqual('docker://' + mirror.PREFIX + name + ':' + tag, calls[-1][0][-1])
        self.assertFalse(Path(calls[0][1]['env']['REGISTRY_AUTH_FILE']).parent.exists())

    def test_authenticated_copy_preserves_every_platform_and_checks_remote_bytes(self):
        self.measured_publish(b'{"schemaVersion":2}')

    def test_non_library_builder_aliases_stay_in_ghcr(self):
        for repository in ('moby/buildkit', 'docker/buildkit-syft-scanner'):
            with self.subTest(repository=repository):
                self.measured_publish(b'{"schemaVersion":2}', repository, 'stable-1')

    def test_missing_mirror_bootstraps_the_same_digest_from_upstream(self):
        self.measured_publish(b'{"schemaVersion":2}', missing_mirror=True)

    def test_remote_digest_mismatch_never_produces_verified_receipt(self):
        with self.assertRaisesRegex(RuntimeError, 'differs from the reviewed digest'):
            self.measured_publish(b'changed manifest')


if __name__ == '__main__':
    unittest.main()
