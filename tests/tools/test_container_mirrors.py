#!/usr/bin/env python3
# SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
# SPDX-License-Identifier: GPL-3.0-or-later
"""Protect the manifest identity and private authentication handoff."""
import hashlib
import importlib.util
import json
import os
from pathlib import Path
import subprocess
import unittest
from unittest.mock import patch

ROOT = Path(__file__).resolve().parents[2]
spec = importlib.util.spec_from_file_location('container_mirror', ROOT / 'tools/containers/mirror.py')
mirror = importlib.util.module_from_spec(spec)
spec.loader.exec_module(mirror)


class ContainerMirrorTest(unittest.TestCase):
    def test_inventory_contains_seventeen_unique_immutable_destinations(self):
        images = mirror.images()
        self.assertEqual(17, len(images))
        self.assertEqual(17, len({image['destination'] for image in images}))
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

    def measured_publish(self, observed):
        manifest = b'{"schemaVersion":2}'
        digest = 'sha256:' + hashlib.sha256(manifest).hexdigest()
        image = {'source': 'docker.io/library/php@' + digest,
                 'destination': mirror.PREFIX + 'php:sha256-' + digest[7:], 'digest': digest}
        calls = []

        def run(command, **arguments):
            calls.append((command, arguments))
            return subprocess.CompletedProcess(command, 0, stdout=observed if command[1] == 'inspect' else b'')

        with patch.dict(os.environ, {'GHCR_TOKEN': 'test-only-value', 'GHCR_USERNAME': 'fixture'}, clear=True), patch.object(subprocess, 'run', side_effect=run):
            receipt = mirror.publish(image)
        self.assertEqual(digest, receipt['verified_digest'])
        self.assertEqual('test-only-value', calls[0][1]['input'])
        self.assertNotIn('test-only-value', json.dumps([command for command, _ in calls]))
        self.assertIn('--all', calls[1][0])
        self.assertIn('--preserve-digests', calls[1][0])
        self.assertFalse(Path(calls[0][1]['env']['REGISTRY_AUTH_FILE']).parent.exists())

    def test_authenticated_copy_preserves_every_platform_and_checks_remote_bytes(self):
        self.measured_publish(b'{"schemaVersion":2}')

    def test_remote_digest_mismatch_never_produces_verified_receipt(self):
        with self.assertRaisesRegex(RuntimeError, 'differs from the reviewed digest'):
            self.measured_publish(b'changed manifest')


if __name__ == '__main__':
    unittest.main()
