"""Optional measured HTTP/worker coverage for the existing integration suite."""
import hashlib
import json
from pathlib import Path


def configure_coverage(harness, output):
    output = output.resolve()
    output.mkdir(parents=True, exist_ok=False)
    raw = output / 'raw'
    raw.mkdir(mode=0o777)
    raw.chmod(0o777)
    ini = output / 'coverage.ini'
    ini.write_text('pcov.directory=/var/www/html\n'
                   'pcov.exclude="~/(include/vendor|tests)/|^/var/www/html/var/~"\n'
                   'auto_prepend_file=/harness/coverage.php\n')
    override = output / 'compose.json'
    override.write_text(json.dumps({'services': {'web': {
        'build': {'args': {'POLLER_COVERAGE': '1'}},
        'volumes': [f'{raw}:/coverage',
                    f'{ini}:/usr/local/etc/php/conf.d/zz-coverage.ini:ro'],
    }}}))
    harness.dc += ['-f', str(override)]
    command = harness.command

    def instrumented(*args, **kwargs):
        return command(*(arg.replace('auto_prepend_file=/harness/errors.php',
                                     'auto_prepend_file=/harness/coverage.php')
                         if isinstance(arg, str) else arg for arg in args), **kwargs)

    harness.command = instrumented


def http_coverage_sources(root: Path) -> list[str]:
    registry = json.loads((root / 'tests/Symfony/http_coverage_sources.json').read_text())
    paths = registry.get('source_paths')
    if (registry.get('version') != 1 or registry.get('suite') != 'symfony-http'
            or not isinstance(paths, list) or not paths
            or any(not isinstance(path, str) or not path or path.startswith('/')
                   or '\\' in path or '..' in path.split('/') for path in paths)
            or len(paths) != len(set(paths))):
        raise RuntimeError('Invalid HTTP coverage source registry')
    return paths


def publish_coverage(output, database_sessions, checks):
    if not list((output / 'raw').glob('coverage-*.json')):
        raise RuntimeError('No Symfony HTTP coverage recorded')
    root = Path(__file__).resolve().parents[2]
    source_paths = http_coverage_sources(root)
    evidence = {'suite': 'symfony-http',
                'session_handler': 'database' if database_sessions else 'files',
                'checks': checks,
                'source_sha256': {name: hashlib.sha256((root / name).read_bytes()).hexdigest() for name in source_paths}}
    (output / 'observations.json').write_text(json.dumps(evidence, indent=2) + '\n')
