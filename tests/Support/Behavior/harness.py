"""External compatibility scenarios. Standard library only; no production imports."""
import argparse
import difflib
import hashlib
import html
from html.parser import HTMLParser
import http.cookiejar
import json
import os
from pathlib import Path
import re
import subprocess
import sys
import tempfile
import urllib.parse
import urllib.request
import urllib.error

ROOT = Path(__file__).resolve().parents[3]


def run(args, *, data=None, check=True, timeout=180):
    p = subprocess.run(args, input=data, text=True, capture_output=True, timeout=timeout)
    result = dict(exit=p.returncode, stdout=p.stdout, stderr=p.stderr)
    if check and p.returncode:
        raise RuntimeError(f'{args!r}: {result}')
    return result


def write_json(path, value):
    path.parent.mkdir(parents=True, exist_ok=True)
    path.write_text(json.dumps(value, indent=2, ensure_ascii=False) + '\n')


# Wall-clock only. Each pattern is anchored to a full timestamp shape so it
# cannot swallow a version, an id, an OID or a counter value.
CLOCK = re.compile(r'\[\d{2}:\d{2}:\d{2}\]')
DATETIME = re.compile(r'\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}')
ISO = re.compile(r'\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d+)?(?:Z|[+-]\d{2}:\d{2})?')


def normalize(value):
    """Explicit environment and wall-clock substitutions only.

    Log severity, subsystem, message text and ordering all survive. Ids, row
    counts, scalar types and numeric values are never rewritten: a change in any
    of them is a behavioral change, not noise.
    """
    if isinstance(value, dict):
        return {k: normalize(v) for k, v in value.items()}
    if isinstance(value, list):
        return [normalize(v) for v in value]
    if isinstance(value, str):
        value = value.replace('/var/www/html', '<APP>').replace('/harness', '<HARNESS>')
        value = ISO.sub('<TIMESTAMP>', value)
        value = DATETIME.sub('<TIMESTAMP>', value)
        return CLOCK.sub('[<TIME>]', value)
    return value


class Forms(HTMLParser):
    def __init__(self):
        super().__init__()
        self.inputs = []
        self.token = None

    def handle_starttag(self, tag, attrs):
        a = dict(attrs)
        if tag == 'input':
            self.inputs.append({k: a[k] for k in ('name', 'type') if k in a})
            if a.get('name') == '__csrf_magic':
                self.token = a.get('value')


class Session:
    def __init__(self, base):
        self.base = base
        self.opener = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()))
        self.token = None

    def request(self, path, fields=None):
        data = urllib.parse.urlencode(fields).encode() if fields is not None else None
        try:
            response = self.opener.open(self.base + path, data=data, timeout=30)
        except urllib.error.HTTPError as error:
            response = error
        body = response.read().decode('utf-8', errors='replace')
        parser = Forms()
        parser.feed(body)
        self.token = parser.token or self.token
        contract = {'status': response.status, 'url': response.url.replace(self.base, '<BASE>'),
                    'content_type': response.headers.get('Content-Type'), 'inputs': parser.inputs}
        title = re.search(r'<title>(.*?)</title>', body, re.S)
        contract['title'] = html.unescape(title[1]) if title else None
        contract['login_form'] = 'login_username' in body
        contract['admin_layout'] = bool(re.search(r"(?:id=['\"]main_logo|class=['\"]cactiPageHead)", body))
        # JSON is preserved in full, including order, scalar types and IDs.
        try:
            contract['json'] = json.loads(body)
        except ValueError:
            contract['messages'] = [html.unescape(re.sub('<[^>]+>', '', x)).strip() for x in
                                    re.findall(r'<[^>]+class=[\'"][^\'"]*(?:loginErrors|textError)[^\'"]*[\'"][^>]*>(.*?)</[^>]+>', body, re.S)]
        return contract

    def login(self, password):
        self.request('/index.php')
        if not self.token:
            raise RuntimeError('Login form lacks CSRF token')
        return self.request('/index.php', {'action': 'login', 'login_username': 'admin',
                            'login_password': password, 'realm': 'local', '__csrf_magic': self.token})


class Harness:
    def __init__(self, args):
        self.args = args
        self.dc = ['docker', 'compose', '-p', f'kadupul-behavior-{os.getpid()}', '-f', str(ROOT / 'tests/behavior/compose.yml')]
        self.observed = {}
        self.destination = ROOT / 'tests/behavior/results' / args.target

    def compose(self, *args, **kwargs):
        return run(self.dc + list(args), **kwargs)

    def command(self, *args, check=False):
        # Same uid as Apache. CLI-created logs and cache files must stay writable
        # by web requests, and the poller runs as the web user in real deployments.
        return self.compose('exec', '-T', '-u', 'www-data', 'web', *args, check=check)

    def php(self, *args):
        return self.command('php', '-d', 'auto_prepend_file=/harness/errors.php', *args)

    def sql(self, sql):
        return self.compose('exec', '-T', 'db', 'mariadb', '-uroot', '-pbehavior-root', '-N', '-B', 'cacti', data=sql)['stdout']

    def rows(self, query):
        # JSON built by MariaDB preserves NULL, strings and numeric column types.
        return [json.loads(x) for x in self.sql(query).splitlines()]

    def capture(self, name, value):
        if name in self.observed:
            raise RuntimeError('Duplicate scenario ' + name)
        self.observed[name] = normalize(value)
        print('CAPTURE ' + name, flush=True)

    def probe(self, name):
        result = self.php('/harness/probe.php', name)
        if result['exit'] or 'BEHAVIOR_JSON=' not in result['stdout']:
            raise RuntimeError(f'Probe {name} failed: {result}')
        prefix, payload = result['stdout'].rsplit('BEHAVIOR_JSON=', 1)
        return {'result': json.loads(payload), 'stdout': prefix, 'stderr': result['stderr'], 'exit': result['exit']}

    def jsonl(self, path):
        """Read an append-only JSONL artifact. A missing file is an empty record set."""
        result = self.command('sh', '-c', f'test -f {path} && cat {path} || true')
        if result['exit']:
            raise RuntimeError(f'Reading {path} failed: {result}')
        return [json.loads(line) for line in result['stdout'].splitlines() if line.strip()]

    def diagnostics(self):
        """PHP warnings, notices and deprecations, grouped and counted.

        Ordering across processes is not stable, so entries are sorted. Counts
        are kept: losing or gaining a diagnostic is a behavioral change.
        """
        events = self.jsonl('/artifacts/php-errors.jsonl')
        grouped = {}
        for event in events:
            if 'fatal' in event:
                key = ('FATAL', event['fatal'].get('message', ''), event['fatal'].get('file', ''))
            else:
                key = (event.get('severity'), event.get('message', ''), event.get('file', ''))
            entry = grouped.setdefault(key, {'severity': key[0], 'message': key[1],
                                             'file': key[2], 'count': 0, 'suppressed': event.get('suppressed')})
            entry['count'] += 1
        return sorted(grouped.values(), key=lambda e: (str(e['severity']), e['message'], e['file']))

    def devices(self):
        return self.rows("SELECT JSON_OBJECT('id',id,'description',description,'hostname',hostname,'disabled',disabled,'snmp_version',snmp_version,'availability_method',availability_method,'host_template_id',host_template_id,'status',status) FROM host ORDER BY id")

    def plugin_state(self):
        return {'config': self.rows("SELECT JSON_OBJECT('directory',directory,'status',status,'version',version) FROM plugin_config WHERE directory='compatibility_test' ORDER BY id"),
                'hooks': self.rows("SELECT JSON_OBJECT('hook',hook,'function',`function`,'status',status,'file',file) FROM plugin_hooks WHERE name='compatibility_test' ORDER BY hook")}

    def setup(self):
        self.compose('up', '-d', '--build', '--wait', 'db', 'web', 'snmp', timeout=1200)
        self.sql((ROOT / 'cacti.sql').read_text())
        self.capture('database/fresh-schema', {'version': self.sql('SELECT * FROM version'),
                     'tables': self.sql('SHOW TABLES'), 'devices': self.devices(),
                     'columns': self.sql('SHOW COLUMNS FROM host')})
        install = self.php('cli/install_cacti.php', '--accept-eula', '--install', '--mode=1')
        self.capture('upgrade/install', install)
        if install['exit']:
            raise RuntimeError('Installer failed; see captured result')
        version = self.sql('SELECT cacti FROM version').strip()
        if version in ('', 'new_install'):
            raise RuntimeError('Installer did not finalize schema')
        # Fixture changes after installer evidence. Never connect to a developer DB.
        password = self.php('-r', 'echo password_hash("behavior-admin", PASSWORD_BCRYPT);')['stdout'].strip()
        self.sql("UPDATE user_auth SET password='" + password + "', must_change_password='', password_change='', enabled='on' WHERE username='admin';")
        self.sql("REPLACE INTO settings(name,value) VALUES ('path_php_binary','/usr/local/bin/php'),('path_rrdtool','/usr/bin/rrdtool'),('path_snmpget','/usr/bin/snmpget'),('path_snmpwalk','/usr/bin/snmpwalk'); UPDATE host SET disabled='on';")
        port = self.compose('port', 'web', '80')['stdout'].strip()
        self.base = 'http://' + port

    def scenarios(self):
        invalid = Session(self.base)
        self.capture('auth/login-invalid', invalid.login('incorrect'))
        session = Session(self.base)
        login = session.login('behavior-admin')
        self.capture('auth/login-admin', login)
        if login['login_form'] or not login['admin_layout']:
            raise RuntimeError('Admin authentication did not succeed')
        self.capture('ui/devices', session.request('/host.php'))
        self.capture('auth/missing-csrf', Session(self.base).request('/index.php', {'action': 'login'}))
        self.capture('cli/device-help', self.php('cli/add_device.php', '--help'))
        self.capture('cli/device-missing', self.php('cli/add_device.php', '--description=missing-host'))
        self.capture('api/datasource-invalid', self.php('cli/add_datasource.php', '--host-id=oops', '--data-template-id=1'))
        create = self.php('cli/add_device.php', '--description=compat-device', '--ip=snmp', '--template=0', '--version=2', '--community=public', '--avail=none')
        self.capture('devices/create', {'command': create, 'database': self.devices()})
        ids = self.sql("SELECT id FROM host WHERE description='compat-device' ORDER BY id").splitlines()
        if len(ids) != 1:
            raise RuntimeError('Device creation must create exactly one fixture')
        device = ids[0]
        self.capture('api/ajax-hosts', session.request('/utilities.php?action=ajax_hosts&term=compat'))
        self.capture('snmp/get', self.probe('snmp'))
        self.capture('api/type-coercion', self.probe('types'))
        self.capture('api/warning-calibration', self.probe('warnings'))
        ds = self.php('cli/add_datasource.php', '--host-id=' + device, '--data-template-id=1')
        self.capture('graphs/datasource-create', {'command': ds, 'database': self.sql('SELECT * FROM data_local ORDER BY id')})
        graph = self.php('cli/add_graphs.php', '--host-id=' + device, '--graph-type=cg', '--graph-template-id=4')
        self.capture('graphs/create', {'command': graph, 'database': self.sql('SELECT * FROM graph_local ORDER BY id')})
        self.capture('graphs/definition', self.probe('graph'))
        self.capture('plugins/install', {'command': self.php('cli/plugin_manage.php', '--plugin=compatibility_test', '--install'), 'database': self.plugin_state()})
        self.capture('plugins/enable', {'command': self.php('cli/plugin_manage.php', '--plugin=compatibility_test', '--enable'), 'database': self.plugin_state()})
        self.capture('plugins/hook', self.probe('plugin'))
        self.capture('plugins/disable', {'command': self.php('cli/plugin_manage.php', '--plugin=compatibility_test', '--disable'), 'database': self.plugin_state()})
        self.capture('plugins/hook-disabled', self.probe('plugin'))
        self.capture('plugins/uninstall', {'command': self.php('cli/plugin_manage.php', '--plugin=compatibility_test', '--uninstall'), 'database': self.plugin_state()})
        self.capture('plugins/callbacks', self.jsonl('/artifacts/plugin.jsonl'))
        self.capture('devices/delete', {'command': self.php('cli/remove_device.php', '--id=' + device, '--confirm'),
            'database': self.devices(), 'data_local': self.sql('SELECT * FROM data_local ORDER BY id'), 'graph_local': self.sql('SELECT * FROM graph_local ORDER BY id')})
        self.capture('api/php-errors', self.diagnostics())

    def finish(self, error=None):
        runtime = self.command('php', '-r', 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;')['stdout'].strip()
        manifest = {'format': 1, 'target': self.args.target, 'revision': run(['git', '-C', str(ROOT), 'rev-parse', 'HEAD'])['stdout'].strip(),
                    'php': runtime, 'schema_sha256': hashlib.sha256((ROOT / 'cacti.sql').read_bytes()).hexdigest(),
                    'complete': error is None, 'error': error, 'scenarios': self.observed}
        write_json(self.destination / 'observations.json', manifest)
        if error:
            return 2
        golden_root = ROOT / 'tests/Golden' / self.args.target / ('php-' + runtime)
        failures = []
        selected = self.selected()
        skipped = [n for n in self.observed if n not in selected]
        for name, value in {k: v for k, v in self.observed.items() if k in selected}.items():
            path = golden_root / (name + '.json')
            if self.args.update_golden:
                write_json(path, value)
            elif not path.exists():
                failures.append(name + ': MISSING GOLDEN (explicit capture required)')
            elif json.loads(path.read_text()) != value:
                failures.append(name + ': REGRESSION')
                (self.destination / (name.replace('/', '--') + '.diff')).write_text(''.join(difflib.unified_diff(
                    path.read_text().splitlines(True), (json.dumps(value, indent=2, ensure_ascii=False) + '\n').splitlines(True), fromfile='golden', tofile='observed')))
        if skipped:
            print(f'{len(skipped)} scenarios ran but were not verified (--only {" ".join(self.args.only)})')
        print('\n'.join(failures) if failures else f'{len(selected)} contracts verified'
              + (' (goldens captured)' if self.args.update_golden else ''))
        return int(bool(failures))

    def selected(self):
        """Scenario names in scope. --only matches the group before the slash."""
        if not self.args.only:
            return set(self.observed)
        groups = set(self.args.only)
        return {n for n in self.observed if n.split('/', 1)[0] in groups}


def compare(args):
    root = ROOT / 'tests/behavior/results'
    baseline = json.loads((root / args.baseline / 'observations.json').read_text())
    candidate = json.loads((root / args.candidate / 'observations.json').read_text())
    if not baseline['complete'] or not candidate['complete']:
        raise RuntimeError('Cannot compare incomplete runs')
    approvals = json.loads(Path(args.approvals).read_text()) if args.approvals else {}
    repeat = json.loads(Path(args.repeat).read_text()) if args.repeat else None
    report = []
    for name in sorted(baseline['scenarios'].keys() | candidate['scenarios'].keys()):
        b, c = baseline['scenarios'].get(name), candidate['scenarios'].get(name)
        digest = hashlib.sha256(json.dumps({'baseline': b, 'candidate': c}, sort_keys=True).encode()).hexdigest()
        if name not in baseline['scenarios'] or name not in candidate['scenarios']:
            status = 'NEEDS_REVIEW'
        elif repeat and (name not in repeat['scenarios'] or c != repeat['scenarios'][name]):
            status = 'NONDETERMINISTIC'
        elif b == c:
            status = 'IDENTICAL'
        elif approvals.get(name, {}).get('digest') == digest and approvals[name].get('reason'):
            status = 'INTENTIONAL_CHANGE'
        else:
            status = 'REGRESSION'
        report.append({'scenario': name, 'status': status, 'digest': digest, 'baseline': b, 'candidate': c})
    output = Path(args.output)
    write_json(output.with_suffix('.json'), {'baseline': args.baseline, 'candidate': args.candidate, 'differences': report})
    output.with_suffix('.md').write_text('# Behavioral comparison\n\n' + '\n'.join(f"- {r['status']}: `{r['scenario']}`" for r in report) + '\n')
    print(output.with_suffix('.md').read_text())
    return int(any(r['status'] not in ('IDENTICAL', 'INTENTIONAL_CHANGE') for r in report))


def main():
    parser = argparse.ArgumentParser()
    sub = parser.add_subparsers(dest='action', required=True)
    test = sub.add_parser('run')
    test.add_argument('--target', default=os.environ.get('TARGET', 'kadupul'))
    test.add_argument('--update-golden', action='store_true')
    test.add_argument('--keep', action='store_true')
    test.add_argument('--only', nargs='*', default=None, metavar='GROUP',
                      help='Verify only these scenario groups (api, auth, devices, graphs, plugins, cli, poller, ui, database, upgrade, snmp). All scenarios still run, because later ones consume earlier fixtures.')
    diff = sub.add_parser('compare')
    diff.add_argument('--baseline', required=True)
    diff.add_argument('--candidate', required=True)
    diff.add_argument('--repeat')
    diff.add_argument('--approvals')
    diff.add_argument('--output', default=str(ROOT / 'tests/behavior/results/comparison'))
    args = parser.parse_args()
    if args.action == 'compare':
        return compare(args)
    if not re.fullmatch(r'[a-zA-Z0-9_.-]+', args.target) or args.target in ('.', '..'):
        parser.error('Target must be a safe artifact label')
    if args.only and args.update_golden:
        parser.error('--update-golden records every scenario; it cannot be scoped with --only')
    harness = Harness(args)
    error = None
    try:
        harness.setup()
        harness.scenarios()
    except Exception as exc:
        error = str(exc)
        print(error, file=sys.stderr)
    finally:
        try:
            status = harness.finish(error)
        finally:
            if not args.keep:
                harness.compose('down', '--volumes', '--remove-orphans', timeout=120)
            else:
                print('Kept project: ' + ' '.join(harness.dc))
    return status


if __name__ == '__main__':
    sys.exit(main())
