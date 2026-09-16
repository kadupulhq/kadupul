"""External compatibility scenarios. Standard library only; no production imports."""
import argparse
import difflib
import fcntl
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

# Explicit inventory: removing a capture must never shrink a recording silently.
EXPECTED_SCENARIOS = frozenset(['api/ajax-hosts', 'api/datasource-invalid', 'api/php-errors', 'api/type-coercion', 'api/warning-calibration', 'auth/login-admin', 'auth/login-invalid', 'auth/missing-csrf', 'cli/device-help', 'cli/device-missing', 'database/fresh-schema', 'devices/create', 'devices/delete', 'diagnostics/application-log', 'diagnostics/visible-php-errors', 'faults/database-unreachable', 'faults/missing-rrd-file', 'graphs/create', 'graphs/datasource-create', 'graphs/definition', 'plugins/callbacks', 'plugins/disable', 'plugins/enable', 'plugins/hook', 'plugins/hook-disabled', 'plugins/install', 'plugins/poller-hooks', 'plugins/uninstall', 'poller/device-unreachable', 'poller/rrd-failure', 'poller/run-reachable', 'snmp/get', 'ui/devices', 'upgrade/install'])



def run(args, *, data=None, check=True, timeout=180):
    p = subprocess.run(args, input=data, text=True, capture_output=True, timeout=timeout)
    result = dict(exit=p.returncode, stdout=p.stdout, stderr=p.stderr)
    if check and p.returncode:
        raise RuntimeError(f'{args!r}: {result}')
    return result


def write_json(path, value):
    path.parent.mkdir(parents=True, exist_ok=True)
    path.write_text(json.dumps(value, indent=2, ensure_ascii=False) + '\n')


# Normalize only timestamps in known diagnostic line shapes. Arbitrary dates
# in database rows, UI output, or plugin messages are part of the contract.
_Y = r'(?:19|20)\d{2}'
_M = r'(?:0[1-9]|1[0-2])'
_D = r'(?:0[1-9]|[12]\d|3[01])'
_DATE = _Y + '-' + _M + '-' + _D + r' \d{2}:\d{2}:\d{2}'
CLOCK = re.compile(r'^\[\d{2}:\d{2}:\d{2}\]', re.MULTILINE)
# date_time_format() supports six orders/month forms and three separators.
_MONTH_NAME = r'(?:Jan|Feb|Mar|Apr|May|Jun|Jul|Aug|Sep|Oct|Nov|Dec)'
_POLLER_DATES = [re.escape(separator).join(parts)
                 for separator in ('-', '/', '.')
                 for month in (_M, _MONTH_NAME)
                 for parts in ((_Y, month, _D), (month, _D, _Y), (_D, month, _Y))]
POLLER_TIMESTAMP = re.compile(r'^(?:' + '|'.join(_POLLER_DATES)
                              + r') \d{2}:\d{2}:\d{2}(?= - SYSTEM STATS:)', re.MULTILINE)
INSTALL_TIMESTAMPS = re.compile(
    r'^(\[\d{2}:\d{2}:\d{2}\] \[\s*global always\s*\] Installation was started at )'
    + _DATE + r'(, completed at )' + _DATE + r'$', re.MULTILINE)


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
        # Poller timing lines report per-process CPU and wall clock, which differ
        # on every run. The line's presence and count still matter, its
        # measurements do not. Both patterns are anchored to the poller's own
        # line shapes so an application message carrying the same tokens is
        # still compared.
        value = re.sub(r'(?<=OK )u:\d+\.\d+ s:\d+\.\d+ r:\d+\.\d+', 'u:<T> s:<T> r:<T>', value)
        value = re.sub(r'(?<=SYSTEM STATS: )Time:\d+\.\d+', 'Time:<T>', value)
        value = POLLER_TIMESTAMP.sub('<TIMESTAMP>', value)
        value = INSTALL_TIMESTAMPS.sub(r'\g<1><TIMESTAMP>\g<2><TIMESTAMP>', value)
        return CLOCK.sub('[<TIME>]', value)
    return value

def visible_diagnostics(events):
    """Observed diagnostics enabled by both shipped and current reporting policy."""
    return [event for event in events if event['severity'] == 'FATAL'
            or (event.get('suppressed') is False and event.get('suppressed_here') is False)]


def application_diagnostics(contents):
    """Keep application-handler PHP records in log order; strip only log time."""
    records = []
    timestamp = re.compile(r'^(?:' + '|'.join(_POLLER_DATES) + r') \d{2}:\d{2}:\d{2}$')
    for line in contents.splitlines():
        prefix, separator, message = line.partition(' - ')
        if not separator or not timestamp.fullmatch(prefix):
            continue
        match = re.match(r'([A-Z][A-Z0-9_]*) (PHP .*:.*)$', message)
        if match:
            records.append({'subsystem': match[1], 'message': normalize(match[2])})
    return records


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
        # One stable project name. Keying it to the pid built a fresh image set on
        # every run, which accumulated to tens of gigabytes locally and would do
        # the same on CI.
        self.dc = ['docker', 'compose', '-p', 'kadupul-behavior', '-f', str(ROOT / 'tests/behavior/compose.yml')]
        # The shared project name means a second concurrent run would tear down
        # the first one's containers in setup(). Refuse it instead.
        self.lock = open(Path(tempfile.gettempdir()) / 'kadupul-behavior.lock', 'w')
        try:
            fcntl.flock(self.lock, fcntl.LOCK_EX | fcntl.LOCK_NB)
        except BlockingIOError:
            raise RuntimeError('Another behavioral harness run holds the kadupul-behavior project') from None
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
        """Run SQL against the fixture database.

        run() checks the exit status, so a failed import raises rather than
        yielding empty rows. Warnings arrive on stderr with a zero exit though,
        and silently dropping those would let a truncated import look clean.
        """
        result = self.compose('exec', '-T', 'db', 'mariadb', '-uroot', '-pbehavior-root',
                              '-N', '-B', 'cacti', data=sql)
        noise = (result['stderr'] or '').strip()
        if noise and 'Using a password' not in noise:
            raise RuntimeError('Database reported a problem on a zero exit: ' + noise[:300])
        return result['stdout']

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
                key = ('FATAL', event['fatal'].get('message', ''), event['fatal'].get('file', ''), None, None)
            else:
                # Both suppression flags are part of the event, or the first arrival
                # decides which value an entry records.
                key = (event.get('severity'), event.get('message', ''), event.get('file', ''),
                       event.get('suppressed'), event.get('suppressed_here'))
            entry = grouped.setdefault(key, {'severity': key[0], 'message': key[1],
                                             'file': key[2], 'count': 0,
                                             'suppressed': event.get('suppressed'),
                                             'suppressed_here': event.get('suppressed_here')})
            entry['count'] += 1
        return sorted(grouped.values(), key=lambda e: (str(e['severity']), e['message'], e['file'],
                                                      str(e['suppressed']), str(e['suppressed_here'])))

    def rrd_calls(self):
        """rrdtool invocations recorded by the image shim, normalized.

        RRD behaviour is a contract about the arguments Cacti builds. Rendered
        pixels are not asserted; the command is.
        """
        result = self.command('sh', '-c',
            'for f in /artifacts/rrd-argv.log /artifacts/rrd-stdin.log; do test -f "$f" && cat "$f"; done || true')
        calls = []
        for line in result['stdout'].splitlines():
            line = line.strip()
            if not line:
                continue
            # The first token is the subcommand; keep it and the file it acts on,
            # drop absolute paths and epoch arguments that move every run.
            call = re.sub(r'/var/www/html', '<APP>', line)
            # Only the update timestamp, which is followed by the value colon.
            # A bare ten-digit run is a DS maximum or an RRA row count.
            call = re.sub(r'(?<=\s)1[0-9]{9}(?=:)', '<EPOCH>', call)
            call = re.sub(r'--start \S+|--end \S+', lambda m: m[0].split()[0] + ' <TIME>', call)
            # An update carries measured values: process count, load average,
            # free memory. Those are the machine, not the behaviour. Keep the
            # count and order of the fields, which IS the contract, and drop the
            # readings so the scenario is reproducible.
            call = re.sub(r'(<EPOCH>)((?::[^\s:]+)+)',
                          lambda m: m[1] + ':<V>' * m[2].count(':'), call)
            calls.append(call)
        return calls

    def truncate_artifacts(self, *names):
        for name in names:
            self.command('sh', '-c', 'rm -f /artifacts/' + name, check=True)

    def poller_state(self):
        return {
            'poller_item': self.rows("SELECT JSON_OBJECT('host_id',host_id,'action',action,'rrd_name',rrd_name,'rrd_path',REPLACE(rrd_path,'/var/www/html','<APP>')) FROM poller_item ORDER BY local_data_id, rrd_name"),
            'poller_output_rows': self.sql('SELECT COUNT(*) FROM poller_output').strip(),
            'host_status': self.rows("SELECT JSON_OBJECT('description',description,'status',status,'status_event_count',status_event_count,'availability_method',availability_method) FROM host ORDER BY id"),
        }

    def devices(self):
        return self.rows("SELECT JSON_OBJECT('id',id,'description',description,'hostname',hostname,'disabled',disabled,'snmp_version',snmp_version,'availability_method',availability_method,'host_template_id',host_template_id,'status',status) FROM host ORDER BY id")

    def plugin_state(self):
        return {'config': self.rows("SELECT JSON_OBJECT('directory',directory,'status',status,'version',version) FROM plugin_config WHERE directory='compatibility_test' ORDER BY id"),
                'hooks': self.rows("SELECT JSON_OBJECT('hook',hook,'function',`function`,'status',status,'file',file) FROM plugin_hooks WHERE name='compatibility_test' ORDER BY hook")}

    def setup(self):
        # The project name is stable so images are reused, which means a previous
        # run's database and append-only artifacts survive. Drop them first, or a
        # baseline can be recorded against state this run never created.
        self.compose('down', '--volumes', '--remove-orphans', check=False, timeout=120)
        self.compose('up', '-d', '--build', '--wait', 'db', 'web', 'snmp', timeout=1200)
        self.truncate_artifacts('php-errors.jsonl', 'plugin.jsonl', 'rrd-argv.log', 'rrd-stdin.log')
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
        self.capture('plugins/install', {'command': self.php('cli/plugin_manage.php', '--plugin=compatibility_test', '--install'), 'database': self.plugin_state()})
        self.capture('plugins/enable', {'command': self.php('cli/plugin_manage.php', '--plugin=compatibility_test', '--enable'), 'database': self.plugin_state()})
        self.capture('plugins/hook', self.probe('plugin'))

        # A poller pass while the plugin is enabled. Without it the registered
        # poller_top and poller_bottom hooks are never dispatched, so the plugin
        # contract covers only the hooks the probe calls directly.
        self.truncate_artifacts('plugin-poller.jsonl')
        poller_hooks_before = len(self.jsonl('/artifacts/plugin.jsonl'))
        poller_pass = self.php('poller.php', '--force')
        poller_hooks = self.jsonl('/artifacts/plugin.jsonl')[poller_hooks_before:]
        poller_hooks_after = poller_hooks_before + len(poller_hooks)
        if not any(h.get('callback') == 'event' for h in poller_hooks):
            raise RuntimeError('A poller pass dispatched no plugin hook; poller_top/bottom are unverified')
        # Record the contract, not the schedule. The interleaved config_settings
        # calls depend on how many workers the poller forks, so committing the
        # raw log would report a regression whenever process scheduling differed.
        # Which lifecycle hooks fired, and in what order, is the actual contract.
        events = [c['args'][0][0] for c in poller_hooks
                  if c.get('callback') == 'event' and c.get('args')]
        missing_events = {'poller_top', 'poller_bottom'} - set(events)
        if missing_events:
            raise RuntimeError('The poller pass did not dispatch ' + ', '.join(sorted(missing_events)))
        self.capture('plugins/poller-hooks', {
            'command': {k: poller_pass[k] for k in ('exit',)},
            'events_in_order': events,
            'other_callbacks_seen': sorted({c['callback'] for c in poller_hooks
                                            if c.get('callback') != 'event'}),
        })
        self.capture('plugins/disable', {'command': self.php('cli/plugin_manage.php', '--plugin=compatibility_test', '--disable'), 'database': self.plugin_state()})
        self.capture('plugins/hook-disabled', self.probe('plugin'))
        self.capture('plugins/uninstall', {'command': self.php('cli/plugin_manage.php', '--plugin=compatibility_test', '--uninstall'), 'database': self.plugin_state()})
        # The poller pass is recorded above as its lifecycle. Its config_settings
        # count follows worker scheduling, so that slice stays out of the raw log.
        callbacks = self.jsonl('/artifacts/plugin.jsonl')
        self.capture('plugins/callbacks', callbacks[:poller_hooks_before] + callbacks[poller_hooks_after:])
        self.capture('devices/delete', {'command': self.php('cli/remove_device.php', '--id=' + device, '--confirm'),
            'database': self.devices(), 'data_local': self.sql('SELECT * FROM data_local ORDER BY id'), 'graph_local': self.sql('SELECT * FROM graph_local ORDER BY id')})

    def poller_scenarios(self):
        """The poller is the compatibility boundary most likely to break under a
        rewrite, and the one least visible over HTTP. Drive it directly.

        The installer's own device already carries five data sources with real
        RRD files, so it is polled rather than a fixture device: a run that
        collects nothing would record an empty contract that proves nothing.
        """
        device = self.sql("SELECT id FROM host WHERE description='Local Linux Machine' ORDER BY id LIMIT 1").strip()
        if not device:
            raise RuntimeError('Installer default device missing; cannot exercise the poller')

        # setup() disables every host so nothing polls by accident.
        self.sql("UPDATE host SET disabled='' WHERE id=" + device + ";")

        self.truncate_artifacts('rrd-argv.log', 'rrd-stdin.log')
        run = self.php('poller.php', '--force')
        if run['exit']:
            raise RuntimeError(f"Poller exited {run['exit']}; refusing to record a failed run")
        state = self.poller_state()
        if not any(row['host_id'] == int(device) for row in state['poller_item']):
            raise RuntimeError('Poller cache holds nothing for the polled device')
        # A populated cache proves setup, not collection. Only an RRD update shows
        # this run gathered something worth recording.
        rrd_calls = self.rrd_calls()
        if not any(call.startswith('update ') for call in rrd_calls):
            raise RuntimeError('Poller made no RRD updates; refusing to record a hollow run')
        self.capture('poller/run-reachable', {
            'command': {k: run[k] for k in ('exit', 'stdout', 'stderr')},
            'database': state,
            'rrd_calls': rrd_calls,
        })

        # A graph probe that returns no source records "RRD file does not exist"
        # and asserts nothing about graph generation, while looking like a
        # captured contract. Refuse it rather than publish a hollow baseline.
        definition = self.probe('graph')
        result = definition['result']
        if not isinstance(result, dict) or not str(result.get('source', '')).strip():
            raise RuntimeError('Graph definition probe produced no rrdtool source: ' + json.dumps(result)[:200])
        self.capture('graphs/definition', definition)

        # The same poll with rrdtool failing, to record how Cacti reports a tool
        # that exits non-zero rather than how it behaves when everything works.
        self.truncate_artifacts('rrd-argv.log', 'rrd-stdin.log')
        self.command('sh', '-c', 'touch /artifacts/rrd-fail && test -f /artifacts/rrd-fail', check=True)
        failed = self.php('poller.php', '--force')
        self.command('sh', '-c', 'rm -f /artifacts/rrd-fail', check=True)

        # Negative control. Without it a no-op injection records an ordinary
        # poll as the failure contract, and the two scenarios after it inherit
        # the mistake.
        if 'injected rrdtool failure' not in ((failed['stdout'] or '') + (failed['stderr'] or '')):
            raise RuntimeError('rrdtool failure was never injected; the scenario would record a normal poll')

        self.capture('poller/rrd-failure', {
            'command': {k: failed[k] for k in ('exit', 'stdout', 'stderr')},
            'database': self.poller_state(),
            'rrd_calls': self.rrd_calls(),
        })

        # An address that cannot answer, exercising availability and timeout
        # handling without waiting on a real network.
        self.sql("UPDATE host SET hostname='203.0.113.1', availability_method=1 WHERE id=" + device + ";")
        self.truncate_artifacts('rrd-argv.log', 'rrd-stdin.log')
        unreachable = self.php('poller.php', '--force')
        self.capture('poller/device-unreachable', {
            'command': {k: unreachable[k] for k in ('exit', 'stdout', 'stderr')},
            'database': self.poller_state(),
        })
        self.sql("UPDATE host SET hostname='127.0.0.1', availability_method=0 WHERE id=" + device + ";")

    def fault_scenarios(self):
        """Cacti's failure semantics under a broken dependency are a contract
        too. A rewrite that turns a logged error into a fatal is a regression."""
        # A data source whose RRD file has been removed underneath it.
        count = "find /var/www/html/rra -name '*.rrd' | wc -l"
        if int(self.command('sh', '-c', count, check=True)['stdout'].strip() or 0) == 0:
            raise RuntimeError('No RRD files to remove; the missing-RRD fault would record an ordinary poll')
        self.command('sh', '-c', "find /var/www/html/rra -name '*.rrd' -delete", check=True)
        if int(self.command('sh', '-c', count, check=True)['stdout'].strip() or 0) != 0:
            raise RuntimeError('RRD files survived deletion; the missing-RRD fault is not in place')
        self.truncate_artifacts('rrd-argv.log', 'rrd-stdin.log')
        missing = self.php('poller.php', '--force')
        self.capture('faults/missing-rrd-file', {
            'command': {k: missing[k] for k in ('exit', 'stdout', 'stderr')},
            'rrd_calls': self.rrd_calls(),
        })

        # A CLI run against a database that refuses connections.
        #
        # set -e covers the setup only. It must not span the application call:
        # a non-zero exit there would abort the script before the config was
        # restored, leaving every later scenario pointed at a dead database and
        # recording the shell's status instead of the application's.
        script = (
            'set -e; cp /var/www/html/include/config.php /tmp/config.bak; '
            "sed -i \"s/\\$database_hostname *= *'[^']*'/\\$database_hostname = 'no-such-host'/\" /var/www/html/include/config.php; "
            'grep -q "no-such-host" /var/www/html/include/config.php; '
            'set +e; '
            'php /var/www/html/cli/add_device.php --description=db-down --ip=1.2.3.4 '
            '--template=0 --version=1 --community=public --avail=none; '
            'status=$?; set -e; cp /tmp/config.bak /var/www/html/include/config.php; '
            'grep -q "\'db\'" /var/www/html/include/config.php; '
            'exit $status')
        broken = self.command('sh', '-c', script)

        # The point of the scenario is the application's own failure report. If
        # the harness broke instead, or the application never spoke, recording
        # the result would publish a contract about nothing.
        combined = (broken['stdout'] or '') + (broken['stderr'] or '')
        if 'Parse error' in combined or 'cannot stat' in combined:
            raise RuntimeError('Fault scenario failed to run rather than exercising the fault: ' + combined[:200])
        if 'database' not in combined.lower():
            raise RuntimeError('Fault scenario produced no database failure report: ' + combined[:200])

        self.capture('faults/database-unreachable', {k: broken[k] for k in ('exit', 'stdout', 'stderr')})

    def diagnostics_scenario(self):
        """PHP diagnostics the recorder sees during the run.

        This has to be captured last. When it ran at the end of scenarios() it
        saw only the probe's own process, so the poller's fwrite notice on the
        broken rrdtool pipe went unrecorded while the scenario claimed to
        characterize PHP diagnostics.
        """
        events = self.diagnostics()

        # Negative control: a diagnostic raised outside the probe must arrive.
        # Without it, a recorder that silently stops working still looks green.
        # Scope, stated rather than implied. include/global.php calls
        # set_error_handler('CactiErrorHandler'), which displaces this recorder in
        # every process that bootstraps the application. probe.php re-arms it
        # explicitly; poller.php and its workers cannot without editing
        # production code, so their diagnostics go to Cacti's own log instead and
        # are visible in each scenario's stderr, not here.
        #
        # What this scenario therefore covers is diagnostics raised before or
        # outside that handler swap. The control below keeps it honest: at least
        # one event must come from application code rather than the probe, so a
        # recorder that silently stops working still fails.
        # Events are still raw here; normalize() only rewrites /harness later.
        outside_probe = [e for e in events
                         if not any(m in str(e.get('file', '')) for m in ('/harness/', '<HARNESS>'))]

        if not outside_probe:
            raise RuntimeError('Diagnostics captured nothing from application code; '
                               'the recorder is no longer reaching lib/')

        self.capture('api/php-errors', events)
        self.capture('diagnostics/visible-php-errors', visible_diagnostics(events))

        # This process leaves the application's handler installed. Its warning
        # must arrive through the real log, independently of the prepend recorder.
        calibration = self.php('-r', "chdir('/var/www/html'); $no_http_headers=true; include 'include/global.php'; trigger_error('behavior application-handler calibration', E_USER_WARNING);")
        if calibration['exit']:
            raise RuntimeError('Application handler calibration failed: ' + json.dumps(calibration))
        log = self.command('cat', '/var/www/html/log/cacti.log', check=True)['stdout']
        records = application_diagnostics(log)
        if not any('behavior application-handler calibration' in row['message'] for row in records):
            raise RuntimeError('The application log missed the post-bootstrap calibration warning')
        self.capture('diagnostics/application-log', records)

    def base_image_digest(self):
        """The base image this run was built on.

        The Dockerfile takes a version argument and resolves a mutable tag, which
        the matrix needs. Pinning a digest there would fix one version and break
        the rest, so record what was actually used instead: a base refresh then
        shows up as a diff in the manifest rather than silently moving a golden.
        """
        result = self.command('sh', '-c', 'cat /etc/os-release | head -2; php -v | head -1', check=False)
        image = run(['docker', 'image', 'inspect', '--format', '{{index .RepoDigests 0}}',
                     f'php:{os.environ.get("PHP_VERSION", "8.2")}-apache'], check=False)
        db = run(['docker', 'image', 'inspect', '--format', '{{index .RepoDigests 0}}', 'mariadb:10.11'], check=False)
        packages = self.command('sh', '-c', "dpkg-query -W -f='${Package}=${Version}\\n' rrdtool snmp snmpd", check=False)
        return {'ref': (image['stdout'] or '').strip() or 'unresolved',
                'db_ref': (db['stdout'] or '').strip() or 'unresolved',
                # The base digest does not pin apt, so a rebuild can change these.
                'packages': (packages['stdout'] or '').strip(),
                'runtime': (result['stdout'] or '').strip()}

    def finish(self, error=None):
        runtime = None
        base_image = None
        if error is None and set(self.observed) != EXPECTED_SCENARIOS:
            missing = sorted(EXPECTED_SCENARIOS - set(self.observed))
            unexpected = sorted(set(self.observed) - EXPECTED_SCENARIOS)
            error = f'Scenario inventory mismatch: missing={missing}, unexpected={unexpected}'
        if error is None:
            try:
                result = self.command('php', '-r', 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;', check=True)
                runtime = result['stdout'].strip()
                if not re.fullmatch(r'\d+\.\d+', runtime):
                    raise RuntimeError('Web container did not report a valid PHP runtime')
                base_image = self.base_image_digest()
            except (OSError, RuntimeError, subprocess.TimeoutExpired) as probe_error:
                error = 'Cannot record runtime provenance: ' + str(probe_error)
        if error is None:
            try:
                self.selected()
                target_root = ROOT / 'tests/Golden' / self.args.target
                orphans = set()
                for golden_root in target_root.glob('php-*'):
                    recorded = {str(path.relative_to(golden_root))[:-5] for path in golden_root.rglob('*.json')}
                    orphans.update(golden_root.name + '/' + name for name in recorded - set(self.observed))
                if orphans:
                    raise RuntimeError('Goldens have no observations: ' + ', '.join(sorted(orphans)))
            except RuntimeError as selection_error:
                error = str(selection_error)
        manifest = {'format': 1, 'target': self.args.target, 'revision': run(['git', '-C', str(ROOT), 'rev-parse', 'HEAD'])['stdout'].strip(),
                    'php': runtime, 'schema_sha256': hashlib.sha256((ROOT / 'cacti.sql').read_bytes()).hexdigest(),
                    'base_image': base_image,
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
        # A golden with no observation means a scenario was renamed or removed.
        # Walking only what ran would let that disappear silently.
        if not self.args.update_golden and golden_root.exists():
            recorded = {str(p.relative_to(golden_root))[:-5] for p in golden_root.rglob('*.json')}
            for orphan in sorted(recorded - set(self.observed)):
                failures.append(orphan + ': GOLDEN HAS NO OBSERVATION (scenario removed or renamed)')

        if skipped:
            print(f'{len(skipped)} scenarios ran but were not verified (--only {" ".join(self.args.only)})')
        print('\n'.join(failures) if failures else f'{len(selected)} contracts verified'
              + (' (goldens captured)' if self.args.update_golden else ''))
        return int(bool(failures))

    def selected(self):
        """Scenario names in scope. --only matches the group before the slash.

        A group that matches nothing is an error, not an empty pass. Reporting
        '0 contracts verified' and exiting 0 is a gate that succeeded because it
        checked nothing, which is the failure this harness exists to prevent.
        """
        if self.args.only is None:
            return set(self.observed)
        if not self.args.only:
            raise RuntimeError('--only needs at least one scenario group')

        groups = set(self.args.only)
        known = {n.split('/', 1)[0] for n in self.observed}
        unknown = groups - known
        if unknown:
            raise RuntimeError('--only named no known scenario group: ' + ', '.join(sorted(unknown))
                               + '. Known groups: ' + ', '.join(sorted(known)))

        chosen = {n for n in self.observed if n.split('/', 1)[0] in groups}
        if not chosen:
            raise RuntimeError('--only selected no scenarios')
        return chosen


def compare(args):
    root = ROOT / 'tests/behavior/results'
    baseline = json.loads((root / args.baseline / 'observations.json').read_text())
    candidate = json.loads((root / args.candidate / 'observations.json').read_text())
    if not baseline['complete'] or not candidate['complete']:
        raise RuntimeError('Cannot compare incomplete runs')
    approvals = json.loads(Path(args.approvals).read_text()) if args.approvals else {}
    repeat = json.loads(Path(args.repeat).read_text()) if args.repeat else None
    # A partial control run would label every later difference NONDETERMINISTIC.
    if repeat is not None and not repeat['complete']:
        raise RuntimeError('Cannot use an incomplete run as the repeat control')
    report = []
    # Matching scenarios prove little if the runs used different runtimes or packages.
    for key in ('php', 'base_image'):
        if baseline.get(key) != candidate.get(key):
            report.append({'scenario': '<environment>/' + key, 'status': 'NEEDS_REVIEW', 'digest': '',
                           'baseline': baseline.get(key), 'candidate': candidate.get(key)})
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
                      help='Verify only these scenario groups (api, auth, devices, graphs, plugins, cli, poller, ui, database, upgrade, snmp, faults, diagnostics). All scenarios still run, because later ones consume earlier fixtures.')
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
    status = 2
    try:
        harness.setup()
        harness.scenarios()
        harness.poller_scenarios()
        harness.fault_scenarios()
        harness.diagnostics_scenario()
    except Exception as exc:
        error = str(exc)
        print(error, file=sys.stderr)
    finally:
        try:
            status = harness.finish(error)
        finally:
            if not args.keep:
                try:
                    harness.compose('down', '--volumes', '--remove-orphans', timeout=120)
                except (OSError, RuntimeError, subprocess.TimeoutExpired) as cleanup_error:
                    print('Container cleanup failed: ' + str(cleanup_error), file=sys.stderr)
                    status = status or 2
            else:
                print('Kept project: ' + ' '.join(harness.dc))
    return status


if __name__ == '__main__':
    sys.exit(main())
