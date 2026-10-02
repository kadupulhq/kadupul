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

        def execute(script, network='none', error=None, arguments=(), input_bytes=None):
            command = ['docker', 'run', '--rm', '--network', network, '--entrypoint', 'php',
                       '--user', f'{os.getuid()}:{os.getgid()}',
                       '--volume', f'{stage}:/var/www/html', '--volume', f'{raw}:/coverage',
                       '--volume', f'{diagnostics}:/artifacts',
                       '--volume', f'{ROOT}/tests/Support/Behavior:/harness:ro',
                       '--workdir', '/var/www/html', args.image,
                       '-d', 'pcov.directory=/var/www/html',
                       '-d', 'pcov.exclude=~/(include/vendor|tests)/|^/var/www/html/var/~',
                       '-d', 'auto_prepend_file=/harness/coverage.php', script, *arguments]
            if input_bytes is not None:
                command.insert(3, '--interactive')
            result = subprocess.run(command, input=input_bytes, capture_output=True, timeout=180)
            output_text = (result.stdout + result.stderr).decode(errors='replace')
            if error is None:
                if result.returncode:
                    raise RuntimeError(output_text)
            elif result.returncode == 0 or error not in output_text:
                raise RuntimeError('Expected failure was not observed: ' + error + '\n' + output_text)

        def remove_fixture(relative):
            # Remove fixtures through the verifier's filesystem view. Host
            # unlink can leave stale bind-mount metadata on Docker Desktop.
            execute('-r', arguments=[
                'if (!unlink($argv[1])) { throw new RuntimeException("Cannot remove offline fixture"); }',
                relative,
            ])

        def write_fixture(relative, contents):
            # Mutate through the same filesystem view as the verifier and
            # confirm exact bytes; host writes can retain stale guest metadata.
            execute('-r', arguments=[
                '$bytes = stream_get_contents(STDIN); '
                'if ($bytes === false || strlen($bytes) !== (int) $argv[2] '
                '|| file_put_contents($argv[1], $bytes) !== (int) $argv[2] '
                '|| hash_file("sha256", $argv[1]) !== $argv[3]) { '
                'throw new RuntimeException("Offline fixture bytes were not confirmed"); }',
                relative, str(len(contents)), hashlib.sha256(contents).hexdigest(),
            ], input_bytes=contents)

        execute('tools/verify-offline.php')
        execute('tools/dependencies/install-legacy.php')
        manifest_path = stage / 'tools/dependencies/legacy-files.json'
        manifest = json.loads(manifest_path.read_text())
        selected = next(iter(manifest['files']))
        patched, patch = next(iter(manifest['patches'].items()))
        remove_fixture(selected)
        execute('tools/dependencies/install-legacy.php', network='bridge')
        if hashlib.sha256((stage / selected).read_bytes()).hexdigest() != manifest['files'][selected]:
            raise RuntimeError('Dependency repair produced incorrect bytes')
        execute('tools/verify-offline.php')
        font = stage / 'include/fa/webfonts/fa-solid-900.woff2'
        font_bytes = font.read_bytes()
        remove_fixture('include/fa/webfonts/fa-solid-900.woff2')
        execute('tools/verify-offline.php', error='Missing offline asset: include/fa/webfonts/fa-solid-900.woff2')
        write_fixture('include/fa/webfonts/fa-solid-900.woff2', font_bytes)
        icon_css = stage / 'include/fa/css/all.css'
        icon_css_text = icon_css.read_text()
        write_fixture('include/fa/css/all.css', b'.fa { display: inline-block; }\n')
        execute('tools/verify-offline.php', error='Offline Font Awesome stylesheet references no webfonts')
        write_fixture('include/fa/css/all.css', icon_css_text.encode())
        execute('tools/verify-offline.php')
        compiled_manifest = json.loads((stage / 'public/assets/manifest.json').read_text())
        compiled_font = stage / 'public' / compiled_manifest['include/fa/webfonts/fa-solid-900.woff2'].lstrip('/')
        compiled_font_bytes = compiled_font.read_bytes()
        remove_fixture(compiled_font.relative_to(stage).as_posix())
        execute('tools/verify-offline.php', error='Missing offline compiled asset: ../webfonts/')
        write_fixture(compiled_font.relative_to(stage).as_posix(), b'')
        execute('tools/verify-offline.php', error='Missing offline compiled asset: ../webfonts/')
        write_fixture(compiled_font.relative_to(stage).as_posix(), compiled_font_bytes)
        execute('tools/verify-offline.php')
        for fields, message in [
            ({'revision': 'invalid'}, 'Invalid legacy dependency revision'),
            ({'files': {'include/vendor/../escape.php': '0' * 64}, 'patches': {}}, 'Invalid legacy dependency path'),
            ({'files': {selected: 'invalid'}, 'patches': {}}, 'Invalid legacy dependency checksum'),
            ({'files': {}}, 'Invalid legacy dependency patch'),
            ({'patches': {patched: 'invalid'}}, 'Invalid legacy dependency patch'),
            ({'patches': {patched: patch | {'replacements': ['invalid']}}}, 'Invalid legacy dependency patch'),
        ]:
            write_fixture('tools/dependencies/legacy-files.json', json.dumps(manifest | fields).encode())
            execute('tools/dependencies/install-legacy.php', error=message)
        # A patched file that fails any check must not be written.
        patched_bytes = (stage / patched).read_bytes()
        remove_fixture(patched)
        for fields, message in [
            ({'patches': {patched: patch | {'source_sha256': '0' * 64}}}, 'Legacy dependency source checksum mismatch'),
            ({'patches': {patched: patch | {'replacements': [{'before': 'kadupul-absent-anchor', 'after': ''}]}}},
             'Legacy dependency patch no longer applies'),
            ({'files': manifest['files'] | {patched: '0' * 64}}, 'Legacy dependency patched checksum mismatch'),
        ]:
            write_fixture('tools/dependencies/legacy-files.json', json.dumps(manifest | fields).encode())
            execute('tools/dependencies/install-legacy.php', network='bridge', error=message)
            if (stage / patched).exists():
                raise RuntimeError('Rejected dependency patch was written: ' + patched)
        write_fixture(patched, patched_bytes)
        write_fixture('tools/dependencies/legacy-files.json', json.dumps(manifest).encode())
        # Use a fresh path: Docker Desktop can retain regular-file metadata
        # briefly when a bind-mounted file is replaced by a symlink.
        link_path = 'include/vendor/coverage-symlink-fixture'
        dependency = stage / link_path
        write_fixture('tools/dependencies/legacy-files.json', json.dumps(manifest | {'files': {link_path: manifest['files'][selected]}, 'patches': {}}).encode())
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
