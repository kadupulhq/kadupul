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


def publish_coverage(output, database_sessions, checks):
    if not list((output / 'raw').glob('coverage-*.json')):
        raise RuntimeError('No Symfony HTTP coverage recorded')
    root = Path(__file__).resolve().parents[2]
    sources = ['session_bridge.py', 'inventory_scenarios.py', 'details_scenarios.py', 'site_scenarios.py', 'site_catalog_scenarios.py', 'site_edit_scenarios.py', 'site_create_scenarios.py', 'device_create_scenarios.py', 'device_creation_review_scenarios.py', 'device_bulk_assignment_scenarios.py', 'device_bulk_snmp_scenarios.py', 'device_association_scenarios.py', 'device_maintenance_scenarios.py', 'site_creation_probe.php', 'site_lifecycle_scenarios.py', 'site_collector_scenarios.py', 'site_lifecycle_probe.php', 'site_assignment_probe.php', 'site_disable_probe.php', 'database_failure_probe.php', 'site_authorization_probe.php', 'device_edit_scenarios.py', 'device_template_scenarios.py', 'device_collector_scenarios.py', 'device_state_scenarios.py', 'device_state_connection_probe.php', 'device_removal_scenarios.py', 'device_template_authorization_probe.php', 'coverage_support.py']
    source_paths = [f'tests/Symfony/{name}' for name in sources] + ['tests/Fixtures/plugins/compatibility_test/setup.php']
    evidence = {'suite': 'symfony-http',
                'session_handler': 'database' if database_sessions else 'files',
                'checks': checks,
                'source_sha256': {name: hashlib.sha256((root / name).read_bytes()).hexdigest() for name in source_paths}}
    (output / 'observations.json').write_text(json.dumps(evidence, indent=2) + '\n')
