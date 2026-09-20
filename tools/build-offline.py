#!/usr/bin/env python3
"""Build an installable archive from source plus locked production dependencies."""

# SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
# SPDX-License-Identifier: GPL-3.0-or-later

import argparse
import hashlib
import json
from pathlib import Path
import shutil
import subprocess
import tarfile
import tempfile


ROOT = Path(__file__).resolve().parents[1]


def run(*args, cwd):
    subprocess.run(args, cwd=cwd, check=True)


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--output', type=Path, default=ROOT / 'dist')
    args = parser.parse_args()
    output = args.output.resolve()
    output.mkdir(parents=True, exist_ok=True)
    # The index is the source inventory: stage new source files before building.
    # Ignored local secrets, generated vendors and development state never enter.
    paths = subprocess.check_output(['git', 'ls-files', '-z'], cwd=ROOT).decode().split('\0')
    if any(path.startswith(('include/vendor/', 'include/fa/')) for path in paths):
        raise RuntimeError('Generated dependency directories must not be tracked')
    php, node, composer, npm = (shutil.which(name) for name in ('php', 'node', 'composer', 'npm'))
    # mise resolves direct executables even when a shell has reordered PATH.
    if shutil.which('mise'):
        selected = {}
        for tool in ('php', 'node'):
            result = subprocess.run(['mise', 'which', tool], cwd=ROOT, text=True, capture_output=True)
            if result.returncode == 0 and Path(result.stdout.strip()).is_file():
                selected[tool] = result.stdout.strip()
        php, node = selected.get('php', php), selected.get('node', node)
    if not all((php, node, composer, npm)):
        raise RuntimeError('Build requires PHP, Composer, Node and npm; run through mise')
    revision = subprocess.check_output(['git', 'rev-parse', 'HEAD'], cwd=ROOT, text=True).strip()
    with tempfile.TemporaryDirectory(prefix='kadupul-offline-') as temporary:
        stage = Path(temporary) / 'kadupul'
        stage.mkdir()
        source_hashes = {}
        for relative in paths:
            if not relative or relative.startswith(('.github/', '.githooks/', 'tests/', 'var/')):
                continue
            source = ROOT / relative
            if source.is_symlink() or not source.is_file():
                raise RuntimeError('Invalid source input: ' + relative)
            destination = stage / relative
            destination.parent.mkdir(parents=True, exist_ok=True)
            shutil.copy2(source, destination)
            source_hashes[relative] = hashlib.sha256(source.read_bytes()).hexdigest()
        run(php, str(Path(composer).resolve()), 'install', '--no-dev', '--prefer-dist',
            '--no-interaction', '--no-progress', '--optimize-autoloader', cwd=stage)
        run(node, str(Path(npm).resolve()), 'ci', '--ignore-scripts', '--no-audit', '--no-fund', cwd=stage)
        run(node, 'tools/dependencies/build.mjs', cwd=stage)
        run(php, 'bin/console', 'lint:container', '--env=prod', '--no-debug', cwd=stage)
        # Cache is environment-specific; do not ship build-host paths or sessions.
        shutil.rmtree(stage / 'var', ignore_errors=True)
        (stage / 'var').mkdir()
        (stage / 'var/.htaccess').write_text('Require all denied\n')
        shutil.rmtree(stage / 'node_modules')
        secret = stage / 'include/vendor/csrf/csrf-secret.php'
        if secret.exists():
            raise RuntimeError('Offline releases must not contain an installation CSRF secret')
        (stage / 'BUILD-MANIFEST.json').write_text(json.dumps({
            'revision': revision,
            'working_tree_dirty': bool(subprocess.check_output(['git', 'status', '--porcelain'], cwd=ROOT)),
            'source_sha256': source_hashes,
            'dependencies': 'composer.lock and package-lock.json; production PHP dependencies only',
        }, indent=2) + '\n')
        artifact = output / 'kadupul-offline.tar.gz'
        with tarfile.open(artifact, 'w:gz') as archive:
            archive.add(stage, arcname='kadupul')
        digest = hashlib.sha256(artifact.read_bytes()).hexdigest()
        (output / 'kadupul-offline.tar.gz.sha256').write_text(f'{digest}  {artifact.name}\n')
        print(artifact)


if __name__ == '__main__':
    main()
