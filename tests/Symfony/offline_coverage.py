# SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
# SPDX-License-Identifier: GPL-3.0-or-later

"""Verify a real offline archive and measure its compatibility PHP tools."""

import argparse
import hashlib
import json
import os
from pathlib import Path
import subprocess
import tarfile
import tempfile

ROOT = Path(__file__).resolve().parents[2]


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--archive', type=Path, required=True)
    parser.add_argument('--output', type=Path, required=True)
    parser.add_argument('--image', default='kadupul-symfony-auth-web:latest')
    args = parser.parse_args()
    output = args.output.resolve()
    output.mkdir(parents=True, exist_ok=False)
    raw = output / 'raw'
    raw.mkdir()
    diagnostics = output / 'diagnostics'
    diagnostics.mkdir()
    archive = args.archive.resolve()
    expected = Path(str(archive) + '.sha256').read_text().split()[0]
    if hashlib.sha256(archive.read_bytes()).hexdigest() != expected:
        raise RuntimeError('Offline archive checksum mismatch')
    with tempfile.TemporaryDirectory(prefix='offline-coverage-') as directory:
        with tarfile.open(archive) as bundle:
            bundle.extractall(directory, filter='data')
        stage = Path(directory) / 'kadupul'

        def execute(script, network='none', error=None, arguments=()):
            command = ['docker', 'run', '--rm', '--network', network, '--entrypoint', 'php',
                       '--user', f'{os.getuid()}:{os.getgid()}',
                       '--volume', f'{stage}:/var/www/html', '--volume', f'{raw}:/coverage',
                       '--volume', f'{diagnostics}:/artifacts',
                       '--volume', f'{ROOT}/tests/Support/Behavior:/harness:ro',
                       '--workdir', '/var/www/html', args.image,
                       '-d', 'pcov.directory=/var/www/html',
                       '-d', 'pcov.exclude=~/(include/vendor|tests)/|^/var/www/html/var/~',
                       '-d', 'auto_prepend_file=/harness/coverage.php', script, *arguments]
            result = subprocess.run(command, capture_output=True, text=True, timeout=180)
            if error is None:
                if result.returncode:
                    raise RuntimeError(result.stdout + result.stderr)
            elif result.returncode == 0 or error not in result.stdout + result.stderr:
                raise RuntimeError('Expected failure was not observed: ' + error + '\n' + result.stdout + result.stderr)

        def remove_fixture(relative):
            # Remove fixtures through the verifier's filesystem view. Host
            # unlink can leave stale bind-mount metadata on Docker Desktop.
            execute('-r', arguments=[
                'if (!unlink($argv[1])) { throw new RuntimeException("Cannot remove offline fixture"); }',
                relative,
            ])

        execute('tools/verify-offline.php')
        execute('tools/dependencies/install-legacy.php')
        manifest_path = stage / 'tools/dependencies/legacy-files.json'
        manifest = json.loads(manifest_path.read_text())
        selected = next(iter(manifest['files']))
        remove_fixture(selected)
        execute('tools/dependencies/install-legacy.php', network='bridge')
        if hashlib.sha256((stage / selected).read_bytes()).hexdigest() != manifest['files'][selected]:
            raise RuntimeError('Dependency repair produced incorrect bytes')
        execute('tools/verify-offline.php')
        font = stage / 'include/fa/webfonts/fa-solid-900.woff2'
        font_bytes = font.read_bytes()
        remove_fixture('include/fa/webfonts/fa-solid-900.woff2')
        execute('tools/verify-offline.php', error='Missing offline asset: include/fa/webfonts/fa-solid-900.woff2')
        font.write_bytes(font_bytes)
        icon_css = stage / 'include/fa/css/all.css'
        icon_css_text = icon_css.read_text()
        icon_css.write_text('.fa { display: inline-block; }\n')
        execute('tools/verify-offline.php', error='Offline Font Awesome stylesheet references no webfonts')
        icon_css.write_text(icon_css_text)
        execute('tools/verify-offline.php')
        compiled_manifest = json.loads((stage / 'public/assets/manifest.json').read_text())
        compiled_font = stage / 'public' / compiled_manifest['include/fa/webfonts/fa-solid-900.woff2'].lstrip('/')
        compiled_font_bytes = compiled_font.read_bytes()
        compiled_font.unlink()
        execute('tools/verify-offline.php', error='Missing offline compiled asset: ../webfonts/')
        compiled_font.write_bytes(b'')
        execute('tools/verify-offline.php', error='Missing offline compiled asset: ../webfonts/')
        compiled_font.write_bytes(compiled_font_bytes)
        execute('tools/verify-offline.php')
        for fields, message in [
            ({'revision': 'invalid'}, 'Invalid legacy dependency revision'),
            ({'files': {'include/vendor/../escape.php': '0' * 64}}, 'Invalid legacy dependency path'),
            ({'files': {selected: 'invalid'}}, 'Invalid legacy dependency checksum'),
        ]:
            manifest_path.write_text(json.dumps(manifest | fields))
            execute('tools/dependencies/install-legacy.php', error=message)
        manifest_path.write_text(json.dumps(manifest))
        # Use a fresh path: Docker Desktop can retain regular-file metadata
        # briefly when a bind-mounted file is replaced by a symlink.
        link_path = 'include/vendor/coverage-symlink-fixture'
        dependency = stage / link_path
        manifest_path.write_text(json.dumps(manifest | {'files': {link_path: manifest['files'][selected]}}))
        dependency.symlink_to(os.path.relpath(stage / 'composer.json', dependency.parent))
        execute('tools/dependencies/install-legacy.php', error='Refusing symlink')
    if not list(raw.glob('coverage-*.json')):
        raise RuntimeError('No release-tool coverage recorded')
    source = 'tests/Symfony/offline_coverage.py'
    (output / 'observations.json').write_text(json.dumps({
        'suite': 'offline-tools', 'session_handler': 'none',
        'source_sha256': {source: hashlib.sha256((ROOT / source).read_bytes()).hexdigest()},
        'checks': ['disconnected archive verified', 'dependency repair verified', 'missing icon fonts rejected', 'empty compiled icon fonts rejected', 'invalid manifest and symlink rejected'],
    }, indent=2) + '\n')
    print('Offline archive, dependency repair and rejection coverage verified', flush=True)


if __name__ == '__main__':
    main()
