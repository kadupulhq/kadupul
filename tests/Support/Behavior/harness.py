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
import uuid
import urllib.parse
import urllib.request
import urllib.error

CONTROLLER_ROOT = Path(__file__).resolve().parents[3]
ROOT = CONTROLLER_ROOT

# Explicit inventory: removing a capture must never shrink a recording silently.
EXPECTED_SCENARIOS = frozenset(['api/ajax-hosts', 'api/datasource-invalid', 'api/php-errors', 'api/type-coercion', 'api/warning-calibration', 'auth/login-admin', 'auth/login-invalid', 'auth/missing-csrf', 'cli/device-help', 'cli/device-missing', 'database/fresh-schema', 'devices/create', 'devices/delete', 'diagnostics/application-log', 'diagnostics/visible-php-errors', 'faults/database-unreachable', 'faults/missing-rrd-file', 'graphs/create', 'graphs/datasource-create', 'graphs/definition', 'plugins/callbacks', 'plugins/disable', 'plugins/enable', 'plugins/hook', 'plugins/hook-disabled', 'plugins/install', 'plugins/poller-hooks', 'plugins/uninstall', 'poller/device-unreachable', 'poller/rrd-failure', 'poller/run-reachable', 'snmp/get', 'ui/devices', 'upgrade/install'])

# Minimum supported input inventory. Extra inputs remain hashed and compared.
# Keep old captures readable when adding optional helpers; a required runtime
# dependency must be added here and old captures lacking it must be recaptured.
REQUIRED_INPUTS = frozenset(
    ['tests/Support/Behavior/' + name for name in (
        'coverage.php', 'coverage_selftest.py', 'errors.php', 'function_capture.php', 'function_codec.php',
        'harness.py', 'inventory.py', 'merge_poller_coverage.php', 'poller_coverage.py', 'probe.php',
        'release_readiness.py', 'release_selftest.py', 'selftest.py', 'wait-php.php')]
    + ['tests/Fixtures/plugins/compatibility_test/INFO', 'tests/Fixtures/plugins/compatibility_test/setup.php',
       'tests/Fixtures/snmp/snmpd.conf', 'tests/Fixtures/snmp/value.sh',
       'tests/behavior/compose.yml', 'tests/behavior/Dockerfile', '.dockerignore'])



def run(args, *, data=None, check=True, timeout=180):
    env = None
    if args[0] == 'git':
        # Hooks export repository-local settings that override even git -C.
        # A baseline command must never reuse the candidate's index or gitdir.
        local_names = {'GIT_ALTERNATE_OBJECT_DIRECTORIES', 'GIT_CONFIG',
                       'GIT_CONFIG_PARAMETERS', 'GIT_CONFIG_COUNT', 'GIT_OBJECT_DIRECTORY',
                       'GIT_DIR', 'GIT_WORK_TREE', 'GIT_IMPLICIT_WORK_TREE',
                       'GIT_GRAFT_FILE', 'GIT_INDEX_FILE', 'GIT_NO_REPLACE_OBJECTS',
                       'GIT_REPLACE_REF_BASE', 'GIT_PREFIX', 'GIT_SHALLOW_FILE', 'GIT_COMMON_DIR'}
        env = {key: value for key, value in os.environ.items()
               if key not in local_names and not key.startswith(('GIT_CONFIG_KEY_', 'GIT_CONFIG_VALUE_'))}
    p = subprocess.run(args, input=data, text=True, capture_output=True, timeout=timeout, env=env)
    result = dict(exit=p.returncode, stdout=p.stdout, stderr=p.stderr)
    if check and p.returncode:
        raise RuntimeError(f'{args!r}: {result}')
    return result


def write_json(path, value):
    path.parent.mkdir(parents=True, exist_ok=True)
    path.write_text(json.dumps(value, indent=2, ensure_ascii=False) + '\n')


def source_provenance():
    """Identify the executing harness independently from the application tree."""
    source = Path(__file__).resolve()
    harness_root = source.parents[3]
    def git(root, *arguments):
        return run(['git', '-C', str(root), *arguments])['stdout'].strip()
    def input_hashes(root):
        inputs = {}
        for relative in ('tests/Support/Behavior', 'tests/Fixtures/plugins/compatibility_test',
                         'tests/Fixtures/snmp', 'tests/behavior/compose.yml', 'tests/behavior/Dockerfile', '.dockerignore'):
            path = root / relative
            paths = path.rglob('*') if path.is_dir() else [path]
            for item in sorted(paths):
                if item.is_file() and '__pycache__' not in item.parts:
                    inputs[str(item.relative_to(root))] = hashlib.sha256(item.read_bytes()).hexdigest()
        return inputs
    return {'harness_revision': git(harness_root, 'rev-parse', 'HEAD'),
            'harness_dirty': bool(git(harness_root, 'status', '--porcelain', '--untracked-files=all', '--', '.', ':(exclude)tests/behavior/results/**')),
            'application_dirty': bool(git(ROOT, 'status', '--porcelain', '--untracked-files=all', '--', '.', ':(exclude)tests/behavior/results/**')),
            'harness_sha256': hashlib.sha256(source.read_bytes()).hexdigest(),
            'harness_inputs_sha256': input_hashes(harness_root),
            'application_inputs_sha256': input_hashes(ROOT)}


def validate_application_inputs():
    """Require the mounted application helpers to match the recorded controller."""
    if not ROOT.is_dir() or not (ROOT / 'cacti.sql').is_file():
        raise RuntimeError('Application root must be a checkout containing cacti.sql')
    run(['git', '-C', str(ROOT), 'rev-parse', '--verify', 'HEAD'])
    provenance = source_provenance()
    controller = provenance['harness_inputs_sha256']
    application = provenance['application_inputs_sha256']
    if not REQUIRED_INPUTS.issubset(controller) or not REQUIRED_INPUTS.issubset(application):
        raise RuntimeError('Application or controller is missing required harness inputs')
    mismatched = sorted(name for name in set(controller) | set(application) if application.get(name) != controller.get(name))
    if mismatched:
        raise RuntimeError('Application harness overlay differs from controller: ' + ', '.join(mismatched))


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


def normalize_failed_write_size(value):
    """Normalize write sizes only in complete Cacti or native PHP records."""
    prefix = r'(?:(?:' + '|'.join(_POLLER_DATES) + r') \d{2}:\d{2}:\d{2} - [A-Z][A-Z0-9_]* |Total\[\d+\.\d+\] )'
    root = r'(?:/var/www/html|/harness|<APP>|<HARNESS>)/[^\r\n]*\.php'
    shapes = (
        (prefix + r'PHP (?:NOTICE|WARNING):', r' in file: ' + root + r'\s+on line: \d+'),
        (r'(?:' + prefix + r')?PHP (?:Notice|Warning):', r' in ' + root + r' on line \d+'),
    )
    for start, location in shapes:
        value = re.sub(r'^(' + start + r'[ \t]+fwrite\(\): Write of )\d+'
                       + r'( bytes failed with errno=\d+[^\r\n]*' + location + r')(?=\r?$)',
                       r'\1<BYTES>\2', value, flags=re.MULTILINE)
    return value


def normalize_php_locations(value):
    """Ignore source movement only in recognized PHP diagnostic locations."""
    lines = []
    root = r'(?:<APP>|<HARNESS>|/var/www/html|/harness)'
    for line in value.splitlines(keepends=True):
        log_prefix = r'(?:(?:' + '|'.join(_POLLER_DATES) + r') \d{2}:\d{2}:\d{2} - [A-Z][A-Z0-9_]* |Total\[\d+\.\d+\] )'
        severity = r'PHP (?:(?:USER_)?(?:NOTICE|WARNING|ERROR|DEPRECATED)|(?:CORE|COMPILE)_(?:ERROR|WARNING)|RECOVERABLE_ERROR|PARSE|ALL|STRICT|Unknown Error)'
        cacti_record = (r'^(' + log_prefix + severity + r"(?: in  Plugin '[^\r\n']+')?:[^\r\n]* in file:\s+"
                        + root + r'/[^\r\n]*?\.php\s+on line:\s*)\d+(\s*)$')
        # Raw uppercase severity text can be a warning payload. Cacti records
        # require their logger prefix; native PHP diagnostics have a separate shape.
        line = re.sub(cacti_record, r'\1<LINE>\2', line)
        native_record = (r'^((?:' + log_prefix + r')?PHP (?:Notice|Warning|Deprecated|Fatal error|Parse error):[^\r\n]* in '
                         + root + r'/[^\r\n]*?\.php on line )\d+(\s*)$')
        line = re.sub(native_record, r'\1<LINE>\2', line)
        # cacti_debug_backtrace emits a distinct record, with comma-separated
        # file[line]:function() frames. A path-shaped warning payload is data.
        log_prefix = r'(?:(?:' + '|'.join(_POLLER_DATES) + r') \d{2}:\d{2}:\d{2} - [A-Z][A-Z0-9_]* )?'
        trace = re.fullmatch(log_prefix + r'(PHP ERROR(?: [A-Z_]+)? Backtrace:\s*\()(.*)(\)\s*)', line)
        if trace:
            frames = re.sub(r'(^|, )((?:' + root + r')?/[^\s\[\]]+\.php)\[\d+\](?=:[^(),\r\n]+\(\)(?:, |$))',
                            r'\1\2[<LINE>]', trace[2])
            line = line[:trace.start(2)] + frames + line[trace.end(2):]
        lines.append(line)
    return ''.join(lines)


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
        value = normalize_php_locations(normalize_failed_write_size(value))
        value = normalize_known_roots(value)
        # Poller timing lines report per-process CPU and wall clock, which differ
        # on every run. The line's presence and count still matter, its
        # measurements do not. Both patterns are anchored to the poller's own
        # line shapes so an application message carrying the same tokens is
        # still compared.
        value = re.sub(r'^OK u:\d+(?:\.\d+)? s:\d+(?:\.\d+)? r:\d+(?:\.\d+)?(?=\r?$)', 'OK u:<T> s:<T> r:<T>', value, flags=re.MULTILINE)
        value = POLLER_TIMESTAMP.sub('<TIMESTAMP>', value)
        # cacti_log's CLI elapsed-time prefix is volatile; keep the warning
        # and its issue count / data-source IDs intact.
        value = re.sub(r'^Total\[\d+(?:\.\d+)?\](?= WARNING: Poller Output Table not Empty\.  Issues: \d+, DS\[)', 'Total[<T>]', value, flags=re.MULTILINE)
        value = re.sub(r'^(?P<prefix>(?:<TIMESTAMP> - )?SYSTEM STATS: )Time:\d+(?:\.\d+)?(?=\s|$)', r'\g<prefix>Time:<T>', value, flags=re.MULTILINE)
        value = INSTALL_TIMESTAMPS.sub(r'\g<1><TIMESTAMP>\g<2><TIMESTAMP>', value)
        return CLOCK.sub('[<TIME>]', value)
    return value

def poller_command_contract(result):
    """Count RRD child acknowledgements independently of parent stdout timing."""
    command = {key: result[key] for key in ('exit', 'stdout', 'stderr')}
    acknowledgements = 0
    output = []
    for line in command['stdout'].splitlines(keepends=True):
        if re.fullmatch(r'OK u:\d+(?:\.\d+)? s:\d+(?:\.\d+)? r:\d+(?:\.\d+)?\r?\n?', line):
            acknowledgements += 1
        else:
            output.append(line)
    command['stdout'] = ''.join(output)
    command['rrd_acknowledgements'] = acknowledgements
    return command


def normalize_known_roots(value):
    roots = {'/var/www/html': '<APP>', '/harness': '<HARNESS>'}
    return re.sub(r'(?<![\w./-])(?:/var/www/html|/harness)(?=/|\r?$)',
                  lambda match: roots[match[0]], value, flags=re.MULTILINE)


def visible_diagnostics(events):
    """Observed diagnostics enabled by both shipped and current reporting policy."""
    return [event for event in events if event['severity'] == 'FATAL'
            or (event.get('suppressed') is False and event.get('suppressed_here') is False)]


def application_diagnostics(contents):
    """Retain diagnostic multiplicity without unstable cross-process log order."""
    timestamp = re.compile(r'^(?:' + '|'.join(_POLLER_DATES) + r') \d{2}:\d{2}:\d{2}$')
    entries = []
    for line in contents.splitlines():
        prefix, separator, message = line.partition(' - ')
        if separator and timestamp.fullmatch(prefix):
            entries.append([prefix, message])
        elif entries:
            # A multi-line diagnostic continues until the next timestamped record.
            entries[-1][1] += '\n' + line
    records = []
    for prefix, message in entries:
        # POLLER records carry a per-process PID; dropping that prefix keeps
        # the subsystem and message comparable across runs.
        message = re.sub(r'^(POLLER): Poller\[\d+\] PID\[\d+\] ', r'\1 ', message)
        match = re.fullmatch(r'([A-Z][A-Z0-9_]*) (PHP [^\n]*:.*)', message, re.DOTALL)
        if match:
            normalized = normalize_php_locations(normalize_failed_write_size(prefix + ' - ' + message))
            detail = normalized.partition(' - ')[2].partition(' ')[2]
            records.append({'subsystem': match[1], 'message': normalize_known_roots(detail)})
        elif re.search(r'\bPHP [A-Z][A-Za-z_ ]*:', message.partition('\n')[0]):
            # An unrecognized logger prefix must surface as a contract change,
            # not disappear from the capture.
            records.append({'subsystem': '<UNPARSED>', 'message': normalize_known_roots(message)})
    return sorted(records, key=lambda row: (row['subsystem'], row['message']))


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
        form = self.request('/index.php')
        if not self.token:
            raise RuntimeError('Login form lacks CSRF token')
        # Follow the displayed form's page after the console hands login to its
        # dedicated entry. Upstream versions still serve it on index.php.
        target = form['url']
        if not target.startswith('<BASE>/'):
            raise RuntimeError('Login form left the fixture origin')
        return self.request(target[len('<BASE>'):], {'action': 'login', 'login_username': 'admin',
                            'login_password': password, '__csrf_magic': self.token})


class Harness:
    def __init__(self, args):
        self.args = args
        # One stable project name. Keying it to the pid built a fresh image set on
        # every run, which accumulated to tens of gigabytes locally and would do
        # the same on CI.
        project = getattr(args, 'project', 'kadupul-behavior')
        self.dc = ['docker', 'compose', '-p', project, '-f', str(ROOT / 'tests/behavior/compose.yml')]
        # The shared project name means a second concurrent run would tear down
        # the first one's containers in setup(). Refuse it instead.
        self.lock = open(Path(tempfile.gettempdir()) / (project + '.lock'), 'w')
        try:
            fcntl.flock(self.lock, fcntl.LOCK_EX | fcntl.LOCK_NB)
        except BlockingIOError:
            raise RuntimeError('Another behavioral harness run holds the ' + project + ' project') from None
        self.setup_started = False
        self.observed = {}
        self.destination = CONTROLLER_ROOT / 'tests/behavior/results' / args.target

    def compose(self, *args, **kwargs):
        return run(self.dc + list(args), **kwargs)

    def command(self, *args, check=False):
        # Same uid as Apache. CLI-created logs and cache files must stay writable
        # by web requests, and the poller runs as the web user in real deployments.
        return self.compose('exec', '-T', '-u', 'www-data', 'web', *args, check=check)

    def php(self, *args):
        if args and args[0] == 'poller.php':
            completion = '/tmp/poller-observation-' + uuid.uuid4().hex
            try:
                result = self.command('php', '-d', 'auto_prepend_file=', '/harness/wait-php.php', completion, '-d',
                                      'auto_prepend_file=/harness/errors.php', *args)
                observed = self.command('cat', completion)
                if observed['exit'] != 0 or observed['stdout'] != 'complete\n':
                    raise RuntimeError('Poller observation boundary failed: ' + result['stderr'])
                return result
            finally:
                self.command('rm', '-f', completion)
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
        # Application log messages have already had only known roots replaced.
        # The general timing normalizer would erase meaningful warning text.
        self.observed[name] = value if name == 'diagnostics/application-log' else normalize(value)
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
            call = normalize_known_roots(line)
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
        items = self.rows("SELECT JSON_OBJECT('host_id',host_id,'action',action,'rrd_name',rrd_name,'rrd_path',rrd_path) FROM poller_item ORDER BY local_data_id, rrd_name")
        for item in items:
            if isinstance(item.get('rrd_path'), str):
                item['rrd_path'] = normalize_known_roots(item['rrd_path'])
        return {
            'poller_item': items,
            'poller_output_rows': self.sql('SELECT COUNT(*) FROM poller_output').strip(),
            'host_status': self.rows("SELECT JSON_OBJECT('description',description,'status',status,'status_event_count',status_event_count,'availability_method',availability_method) FROM host ORDER BY id"),
        }

    def devices(self):
        return self.rows("SELECT JSON_OBJECT('id',id,'description',description,'hostname',hostname,'disabled',disabled,'snmp_version',snmp_version,'availability_method',availability_method,'host_template_id',host_template_id,'status',status) FROM host ORDER BY id")

    def plugin_state(self):
        return {'config': self.rows("SELECT JSON_OBJECT('directory',directory,'status',status,'version',version) FROM plugin_config WHERE directory='compatibility_test' ORDER BY id"),
                'hooks': self.rows("SELECT JSON_OBJECT('hook',hook,'function',`function`,'status',status,'file',file) FROM plugin_hooks WHERE name='compatibility_test' ORDER BY hook")}

    def setup(self):
        validate_application_inputs()
        self.setup_started = True
        # The project name is stable so images are reused, which means a previous
        # run's database and append-only artifacts survive. Drop them first, or a
        # baseline can be recorded against state this run never created.
        self.compose('down', '--volumes', '--remove-orphans', check=False, timeout=120)
        self.compose('up', '-d', '--build', '--wait', 'db', 'web', 'snmp', timeout=1200)
        self.truncate_artifacts('php-errors.jsonl', 'plugin.jsonl', 'rrd-argv.log', 'rrd-stdin.log')
        self.command('sh', '-c', ': > /var/www/html/log/cacti.log', check=True)
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
        self.sql("REPLACE INTO settings(name,value) VALUES ('path_php_binary','/usr/local/bin/php'),('path_rrdtool','/usr/bin/rrdtool'),('path_snmpget','/usr/bin/snmpget'),('path_snmpwalk','/usr/bin/snmpwalk'); UPDATE host SET disabled='on'; UPDATE automation_networks SET enabled='';")
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
        missing_csrf = Session(self.base)
        login_form = missing_csrf.request('/index.php')
        login_target = login_form['url']
        if not login_target.startswith('<BASE>/'):
            raise RuntimeError('Login form left the fixture origin')
        self.capture('auth/missing-csrf', missing_csrf.request(login_target[len('<BASE>'):], {'action': 'login'}))
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
            'command': poller_command_contract(run),
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
        self.command('sh', '-c', 'rm -f /artifacts/rrd-fail-crashed && touch /artifacts/rrd-fail && test -f /artifacts/rrd-fail', check=True)
        failed = self.php('poller.php', '--force')
        self.command('sh', '-c', 'rm -f /artifacts/rrd-fail /artifacts/rrd-fail-crashed', check=True)

        # Negative control. Without it a no-op injection records an ordinary
        # poll as the failure contract, and the two scenarios after it inherit
        # the mistake.
        if 'injected rrdtool failure' not in ((failed['stdout'] or '') + (failed['stderr'] or '')):
            raise RuntimeError('rrdtool failure was never injected; the scenario would record a normal poll')

        self.capture('poller/rrd-failure', {
            'command': poller_command_contract(failed),
            'database': self.poller_state(),
            'rrd_calls': self.rrd_calls(),
        })

        # An address that cannot answer, exercising availability and timeout
        # handling without waiting on a real network.
        self.sql("UPDATE host SET hostname='203.0.113.1', availability_method=1 WHERE id=" + device + ";")
        self.truncate_artifacts('rrd-argv.log', 'rrd-stdin.log')
        unreachable = self.php('poller.php', '--force')
        self.capture('poller/device-unreachable', {
            'command': poller_command_contract(unreachable),
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
            'command': poller_command_contract(missing),
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
        """Capture two diagnostic contracts after all application scenarios.

        The prepend recorder observes diagnostics before or outside the
        application's handler swap. The independent application-log capture
        observes post-bootstrap diagnostics from pollers and workers, including
        the broken RRDtool pipe. Both paths have their own calibration control.
        """
        events = self.diagnostics()

        # The prepend recorder must see an event from application code, not
        # merely its probe. The application-log control below separately proves
        # that the installed CactiErrorHandler records post-bootstrap warnings.
        outside_probe = [e for e in events
                         if not any(m in str(e.get('file', '')) for m in ('/harness/', '<HARNESS>'))]

        if not outside_probe:
            raise RuntimeError('Diagnostics captured nothing from application code; '
                               'the recorder is no longer reaching lib/')

        self.capture('api/php-errors', events)
        self.capture('diagnostics/visible-php-errors', visible_diagnostics(events))

        self.capture_application_diagnostics()

    def capture_application_diagnostics(self):
        before_log = self.command('cat', '/var/www/html/log/cacti.log', check=True)['stdout']
        # This process leaves the application's handler installed. Its warning
        # must arrive through the real log, independently of the prepend recorder.
        calibration = self.php('-r', "chdir('/var/www/html'); $no_http_headers=true; include 'include/global.php'; trigger_error('behavior application-handler calibration', E_USER_WARNING);")
        if calibration['exit']:
            raise RuntimeError('Application handler calibration failed: ' + json.dumps(calibration))
        log = self.command('cat', '/var/www/html/log/cacti.log', check=True)['stdout']
        if not log.startswith(before_log):
            raise RuntimeError('Application log rotated or truncated during calibration')
        appended = application_diagnostics(log[len(before_log):])
        if not any('behavior application-handler calibration' in row['message'] for row in appended):
            raise RuntimeError('The application log missed the post-bootstrap calibration warning')
        self.capture('diagnostics/application-log', application_diagnostics(log))

    def base_image_digest(self):
        """The base image this run was built on.

        Each selectable PHP stage is digest-pinned. Read the inherited base label
        from the running container, rather than inspecting a mutable PHP tag that
        may have moved since the build.
        """
        operating_system = self.command('cat', '/etc/os-release', check=True)['stdout'].strip()
        php_version = self.command('php', '-v', check=True)['stdout'].strip()
        if not operating_system or not php_version.startswith('PHP '):
            raise RuntimeError('Missing or invalid runtime provenance')
        runtime = '\n'.join(operating_system.splitlines()[:2] + php_version.splitlines()[:1])
        container = self.compose('ps', '-q', 'web')['stdout'].strip()
        if not re.fullmatch(r'[0-9a-f]{64}', container):
            raise RuntimeError('Missing or invalid web container identity')
        image = run(['docker', 'inspect', '--format',
                     '{{index .Config.Labels "org.kadupul.behavior.php-base"}}',
                     container], check=True)
        base_ref = (image['stdout'] or '').strip()
        if not re.fullmatch(r'php:8\.[1-4]-apache@sha256:[0-9a-f]{64}', base_ref):
            raise RuntimeError('Missing or invalid pinned PHP base provenance')
        db_container = self.compose('ps', '-q', 'db')['stdout'].strip()
        if not re.fullmatch(r'[0-9a-f]{64}', db_container):
            raise RuntimeError('Missing or invalid database container identity')
        db_image = run(['docker', 'inspect', '--format', '{{.Image}}', db_container], check=True)['stdout'].strip()
        if not re.fullmatch(r'sha256:[0-9a-f]{64}', db_image):
            raise RuntimeError('Missing or invalid database image identity')
        db = run(['docker', 'image', 'inspect', '--format', '{{index .RepoDigests 0}}', db_image], check=True)
        if not re.fullmatch(r'[^\s@]+@sha256:[0-9a-f]{64}', (db['stdout'] or '').strip()):
            raise RuntimeError('Missing or invalid database image provenance')
        packages = self.command('sh', '-c', "dpkg-query -W -f='${Package}=${Version}\\n' rrdtool snmp snmpd", check=True)
        return {'ref': base_ref,
                'db_ref': (db['stdout'] or '').strip(),
                # The base digest does not pin apt, so a rebuild can change these.
                'packages': (packages['stdout'] or '').strip(),
                'runtime': runtime}

    def application_image_digests(self):
        """Identify the immutable images actually used, including COPY build inputs.

        Git revision and dirty flags cannot identify different uncommitted builds.
        Container image IDs include all layers and their configuration; inspecting
        the running containers also avoids resolving a tag that moved after setup.
        Mounted helpers remain covered by the separate input hash inventories.
        """
        images = {}
        for service in ('web', 'snmp', 'db'):
            container = self.compose('ps', '-q', service)['stdout'].strip()
            if not re.fullmatch(r'[0-9a-f]{12,64}', container):
                raise RuntimeError('Cannot identify application container: ' + service)
            image = run(['docker', 'container', 'inspect', '--format', '{{.Image}}', container])['stdout'].strip()
            if not re.fullmatch(r'sha256:[0-9a-f]{64}', image):
                raise RuntimeError('Cannot identify application image: ' + service)
            images[service] = image
        return images

    def finish(self, error=None):
        runtime = None
        base_image = None
        application_images = None
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
                validate_base_image(base_image, 'capture')
                application_images = self.application_image_digests()
            except (OSError, RuntimeError, subprocess.TimeoutExpired) as probe_error:
                error = 'Cannot record runtime provenance: ' + str(probe_error)
        missing = set()
        if error is None:
            try:
                self.selected()
                target_root = CONTROLLER_ROOT / 'tests/Golden' / self.args.target
                orphans = set()
                missing = set()
                current_root = target_root / ('php-' + runtime)
                runtime_roots = set(target_root.glob('php-*')) | {current_root}
                for golden_root in runtime_roots:
                    recorded = {str(path.relative_to(golden_root))[:-5] for path in golden_root.rglob('*.json')}
                    orphans.update(golden_root.name + '/' + name for name in recorded - set(self.observed))
                    missing.update(golden_root.name + '/' + name for name in set(self.observed) - recorded)
                if orphans:
                    raise RuntimeError('Goldens have no observations: ' + ', '.join(sorted(orphans)))
                initial_capture = self.args.update_golden and not any(target_root.glob('php-*'))
                if missing and not (initial_capture or (self.args.update_golden and getattr(self.args, 'bootstrap_goldens', False))):
                    raise RuntimeError('Runtime goldens are missing observations: ' + ', '.join(sorted(missing)))
            except RuntimeError as selection_error:
                error = str(selection_error)
        revision = schema_hash = provenance = None
        try:
            revision = run(['git', '-C', str(ROOT), 'rev-parse', 'HEAD'])['stdout'].strip()
            schema_hash = hashlib.sha256((ROOT / 'cacti.sql').read_bytes()).hexdigest()
            provenance = source_provenance()
        except (OSError, RuntimeError, subprocess.TimeoutExpired) as probe_error:
            detail = 'Cannot record source provenance: ' + str(probe_error)
            error = error + '; ' + detail if error else detail
        manifest = {'format': 2, 'target': self.args.target, 'revision': revision,
                    'php': runtime, 'schema_sha256': schema_hash,
                    'base_image': base_image,
                    'application_images': application_images,
                    'complete': error is None and not missing, 'error': error, 'scenarios': self.observed,
                    'provenance': provenance}
        write_json(self.destination / 'observations.json', manifest)
        if error:
            return 2
        golden_root = CONTROLLER_ROOT / 'tests/Golden' / self.args.target / ('php-' + runtime)
        failures = []
        selected = self.selected()
        skipped = [n for n in self.observed if n not in selected]
        golden_originals = {}
        created_directories = set()
        def restore_goldens():
            for path, original in golden_originals.items():
                if original is None:
                    path.unlink(missing_ok=True)
                else:
                    path.write_bytes(original)
            for path in sorted(created_directories, key=lambda p: len(p.parts), reverse=True):
                if path.exists():
                    path.rmdir()
        try:
            for name, value in {k: v for k, v in self.observed.items() if k in selected}.items():
                path = golden_root / (name + '.json')
                if self.args.update_golden:
                    golden_originals[path] = path.read_bytes() if path.exists() else None
                    parent = path.parent
                    while not parent.exists():
                        created_directories.add(parent)
                        parent = parent.parent
                    write_json(path, value)
                elif not path.exists():
                    failures.append(name + ': MISSING GOLDEN (explicit capture required)')
                elif json.loads(path.read_text()) != value:
                    failures.append(name + ': REGRESSION')
                    (self.destination / (name.replace('/', '--') + '.diff')).write_text(''.join(difflib.unified_diff(
                        path.read_text().splitlines(True), (json.dumps(value, indent=2, ensure_ascii=False) + '\n').splitlines(True), fromfile='golden', tofile='observed')))
        except Exception as write_error:
            # A partly rewritten golden set would be verified against later.
            if self.args.update_golden:
                restore_goldens()
            manifest['complete'] = False
            manifest['error'] = 'Cannot record goldens: ' + str(write_error)
            write_json(self.destination / 'observations.json', manifest)
            return 2
        # Capture permission does not certify the other runtime inventories.
        missing_after = set()
        for runtime_root in runtime_roots:
            recorded = {str(path.relative_to(runtime_root))[:-5] for path in runtime_root.rglob('*.json')}
            missing_after.update(runtime_root.name + '/' + name for name in set(self.observed) - recorded)
        manifest['complete'] = not missing_after
        # Successful manifests need no partial-inventory failure detail.
        if missing_after:
            manifest['inventory_missing'] = sorted(missing_after)
        if self.args.update_golden:
            # Golden writes can change both working-tree dirty flags. Record the
            # resulting state so a bootstrap followed by verification agrees.
            try:
                manifest['provenance'] = source_provenance()
            except (OSError, RuntimeError, subprocess.TimeoutExpired) as probe_error:
                restore_goldens()
                manifest['complete'] = False
                manifest['error'] = 'Cannot record final source provenance: ' + str(probe_error)
                write_json(self.destination / 'observations.json', manifest)
                return 2
        write_json(self.destination / 'observations.json', manifest)
        # The pre-recording inventory validation above owns orphan detection.
        if not manifest['complete']:
            print('Incomplete capture; runtime goldens are missing observations: '
                  + ', '.join(manifest['inventory_missing']), file=sys.stderr)
            return 2

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


def validate_base_image(base_image, role):
    if not isinstance(base_image, dict) or any(not isinstance(base_image.get(key), str) or not base_image[key].strip()
                                              for key in ('ref', 'db_ref', 'packages', 'runtime')):
        raise RuntimeError(f'Missing or invalid {role} base image provenance')
    if any(not re.fullmatch(r'[^\s@]+@sha256:[0-9a-f]{64}', base_image[key]) for key in ('ref', 'db_ref')):
        raise RuntimeError(f'Unpinned {role} base image provenance')


def compare(args):
    root = Path(getattr(args, 'results_root', None) or CONTROLLER_ROOT / 'tests/behavior/results')
    paths = {'baseline': root / args.baseline / 'observations.json',
             'candidate': root / args.candidate / 'observations.json'}
    if args.repeat:
        paths['repeat'] = Path(args.repeat)
    payloads = {role: path.read_bytes() for role, path in paths.items()}
    captures = {role: json.loads(payload) for role, payload in payloads.items()}
    baseline, candidate = captures['baseline'], captures['candidate']
    repeat = captures.get('repeat')
    manifests = list(captures.items())
    for role, manifest in manifests:
        if not isinstance(manifest, dict) or manifest.get('complete') is not True:
            raise RuntimeError(f'Cannot compare incomplete {role} run')
        if type(manifest.get('format')) is not int or manifest['format'] != 2:
            raise RuntimeError(f'Missing or unsupported {role} manifest format')
        if not isinstance(manifest.get('target'), str) or not manifest['target'].strip():
            raise RuntimeError(f'Missing or invalid {role} target')
        if 'error' not in manifest or manifest['error'] is not None or manifest.get('inventory_missing'):
            raise RuntimeError(f'Cannot compare failed {role} run')
        if not isinstance(manifest.get('php'), str) or not re.fullmatch(r'\d+\.\d+', manifest['php']):
            raise RuntimeError(f'Missing or invalid {role} PHP runtime')
        validate_base_image(manifest.get('base_image'), role)
        images = manifest.get('application_images')
        if not isinstance(images, dict) or set(images) != {'web', 'snmp', 'db'} or any(
                not isinstance(value, str) or not re.fullmatch(r'sha256:[0-9a-f]{64}', value)
                for value in images.values()):
            raise RuntimeError(f'Missing or invalid {role} application image provenance')
        scenarios = manifest.get('scenarios')
        if not isinstance(scenarios, dict) or set(scenarios) != EXPECTED_SCENARIOS:
            names = set(scenarios) if isinstance(scenarios, dict) else set()
            raise RuntimeError(f'Invalid {role} scenario inventory: missing={sorted(EXPECTED_SCENARIOS - names)}; unexpected={sorted(names - EXPECTED_SCENARIOS)}')
        def digest(value, lengths=(64,)):
            return isinstance(value, str) and len(value) in lengths and re.fullmatch(r'[0-9a-f]+', value)
        provenance = manifest.get('provenance')
        if not digest(manifest.get('revision'), (40, 64)) or not digest(manifest.get('schema_sha256')) or not isinstance(provenance, dict):
            raise RuntimeError(f'Missing or invalid {role} application provenance')
        if not digest(provenance.get('harness_revision'), (40, 64)) or not digest(provenance.get('harness_sha256')):
            raise RuntimeError(f'Missing or invalid {role} harness provenance')
        for key in ('harness_dirty', 'application_dirty'):
            if not isinstance(provenance.get(key), bool):
                raise RuntimeError(f'Missing or invalid {role} provenance {key}')
        for key in ('harness_inputs_sha256', 'application_inputs_sha256'):
            inputs = provenance.get(key)
            if not isinstance(inputs, dict) or not REQUIRED_INPUTS.issubset(inputs) or not all(isinstance(name, str) and name and digest(value) for name, value in inputs.items()):
                raise RuntimeError(f'Missing or invalid {role} provenance {key}')
        if provenance['harness_inputs_sha256']['tests/Support/Behavior/harness.py'] != provenance['harness_sha256']:
            raise RuntimeError(f'Inconsistent {role} harness source hash')
        if provenance['harness_inputs_sha256'] != provenance['application_inputs_sha256']:
            raise RuntimeError(f'Inconsistent {role} application/controller input hashes')
    approvals = json.loads(Path(args.approvals).read_text()) if args.approvals else {}
    report = []
    controller = source_provenance()
    controller_matches = True
    for role, manifest in manifests:
        for key in ('harness_sha256', 'harness_inputs_sha256'):
            if manifest['provenance'][key] != controller[key]:
                controller_matches = False
                report.append({'scenario': '<controller>/' + role + '/' + key,
                               'status': 'NEEDS_REVIEW', 'digest': '',
                               'baseline': manifest['provenance'][key], 'candidate': controller[key]})
    # Matching scenarios prove little if the runs used different runtimes or packages.
    for key in ('php', 'base_image'):
        if baseline.get(key) != candidate.get(key):
            report.append({'scenario': '<environment>/' + key, 'status': 'NEEDS_REVIEW', 'digest': '',
                           'baseline': baseline.get(key), 'candidate': candidate.get(key)})
    for key in ('harness_sha256', 'harness_inputs_sha256', 'application_inputs_sha256'):
        if baseline['provenance'][key] != candidate['provenance'][key]:
            report.append({'scenario': '<environment>/' + key, 'status': 'NEEDS_REVIEW', 'digest': '',
                           'baseline': baseline['provenance'][key], 'candidate': candidate['provenance'][key]})
    repeat_environment_matches = True
    if repeat:
        for key in ('php', 'base_image', 'application_images', 'revision', 'schema_sha256', 'provenance'):
            if candidate.get(key) != repeat.get(key):
                repeat_environment_matches = False
                report.append({'scenario': '<repeat-environment>/' + key, 'status': 'NEEDS_REVIEW', 'digest': '',
                               'baseline': candidate.get(key), 'candidate': repeat.get(key)})
    for name in sorted(baseline['scenarios'].keys() | candidate['scenarios'].keys()):
        b, c = baseline['scenarios'].get(name), candidate['scenarios'].get(name)
        digest = hashlib.sha256(json.dumps({'baseline': b, 'candidate': c}, sort_keys=True).encode()).hexdigest()
        if name not in baseline['scenarios'] or name not in candidate['scenarios']:
            status = 'NEEDS_REVIEW'
        elif not controller_matches or not repeat_environment_matches:
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
    output = Path(args.output) if args.output else root / 'comparison'
    write_json(output.with_suffix('.json'), {
        'baseline': args.baseline, 'candidate': args.candidate, 'differences': report,
        'contracts': len(EXPECTED_SCENARIOS), 'controller': controller,
        'manifest_sha256': {role: hashlib.sha256(payload).hexdigest() for role, payload in payloads.items()},
        'captures': {role: {key: manifest[key] for key in ('revision', 'schema_sha256', 'provenance', 'application_images')}
                     for role, manifest in manifests},
    })
    output.with_suffix('.md').write_text('# Behavioral comparison\n\n' + '\n'.join(f"- {r['status']}: `{r['scenario']}`" for r in report) + '\n')
    print(output.with_suffix('.md').read_text())
    return int(any(r['status'] not in ('IDENTICAL', 'INTENTIONAL_CHANGE') for r in report))


FUNCTION_CASES = Path(__file__).resolve().with_name('function_cases')
FUNCTION_GOLDENS = CONTROLLER_ROOT / 'tests/Golden/functions'
FUNCTION_CASE_KEYS = frozenset(['id', 'args', 'ambient', 'prelude', 'between', 'isolated', 'timeout', 'note', 'record_sql', 'record_tables', 'record_state', 'mask_generated', 'unordered'])
FUNCTION_AMBIENT_KEYS = frozenset(['settings', 'config', 'session'])
# Fields that echo what the author wrote. normalize() rewrites paths and clock
# shapes, which would silently edit an input such as a path under /var/www/html.
FUNCTION_INPUT_KEYS = frozenset(['id', 'args', 'ambient', 'note'])


def canonical(value):
    """Text form that keeps 1 and 1.0 apart, which == on parsed JSON does not."""
    return json.dumps(value, sort_keys=True, separators=(',', ':'), ensure_ascii=False)


def validate_function_steps(where, steps):
    """Check prelude/between steps the way the probe will read them.

    A malformed step otherwise fails inside PHP after a container is already up.
    """
    if not isinstance(steps, list):
        raise RuntimeError(f'{where}: must be a list of steps')
    for step in steps:
        if not isinstance(step, dict):
            raise RuntimeError(f'{where}: every step must be an object')
        if 'setting' in step:
            extra = set(step) - {'setting'}
            values = step['setting']
            if extra or not isinstance(values, dict) or not values:
                raise RuntimeError(f'{where}: a setting step is exactly {{"setting": {{name: value}}}}')
            for key, value in values.items():
                if value is not None and not isinstance(value, str):
                    raise RuntimeError(f'{where}: setting {key} must be a string or null')
        elif 'request' in step:
            if set(step) != {'request'} or not isinstance(step['request'], dict) or not step['request']:
                raise RuntimeError(f'{where}: a request step is exactly {{"request": {{name: value}}}}')
        elif 'sql' in step:
            extra = set(step) - {'sql', 'params'}
            if extra or not isinstance(step['sql'], str) or not step['sql'].strip() or not isinstance(step.get('params', []), list):
                raise RuntimeError(f'{where}: an sql step is {{"sql": text, "params": [...]}}')
        elif isinstance(step.get('fn'), str) and step['fn']:
            extra = set(step) - {'fn', 'args'}
            if extra:
                raise RuntimeError(f'{where}: unknown step keys ' + ', '.join(sorted(extra)))
            if not isinstance(step.get('args', []), list):
                raise RuntimeError(f'{where}: step args must be a list')
        else:
            raise RuntimeError(f'{where}: a step needs "setting", "sql", "request" or a string "fn"')


def load_function_cases(directory, only=None):
    """Read and validate case files. An empty file is an error, not a pass.

    A function with no cases records nothing, and a golden of nothing verifies
    forever. The same goes for a name that selects no file.
    """
    files = sorted(Path(directory).glob('*.json'))
    if not files:
        raise RuntimeError('No function case files in ' + str(directory))
    names = {path.stem for path in files}
    unknown = set(only or ()) - names
    if unknown:
        raise RuntimeError('No case file for: ' + ', '.join(sorted(unknown)))
    cases = {}
    for path in files:
        name = path.stem
        if only and name not in only:
            continue
        try:
            spec = json.loads(path.read_text())
        except ValueError as error:
            raise RuntimeError(f'{name}: case file is not valid JSON: {error}') from None
        if not isinstance(spec, dict) or spec.get('function') != name:
            raise RuntimeError(f'{name}: "function" must equal the file name')
        items = spec.get('cases')
        if not isinstance(items, list) or not items:
            raise RuntimeError(f'{name}: case file has no cases')
        seen = set()
        for case in items:
            if not isinstance(case, dict) or not isinstance(case.get('id'), str) or not case['id']:
                raise RuntimeError(f'{name}: every case needs a string id')
            if case['id'] in seen:
                raise RuntimeError(f'{name}: duplicate case id {case["id"]}')
            seen.add(case['id'])
            if not isinstance(case.get('args'), list):
                raise RuntimeError(f'{name}/{case["id"]}: args must be a list')
            where = f'{name}/{case["id"]}'
            ambient = case.get('ambient', {})
            if not isinstance(ambient, dict):
                raise RuntimeError(f'{where}: ambient must be an object')
            extra = set(case) - FUNCTION_CASE_KEYS
            extra |= set(ambient) - FUNCTION_AMBIENT_KEYS
            if extra:
                raise RuntimeError(f'{where}: unknown keys ' + ', '.join(sorted(extra)))
            for key in ambient:
                if not isinstance(ambient[key], dict):
                    raise RuntimeError(f'{where}: ambient.{key} must be an object')
            for key, value in ambient.get('settings', {}).items():
                if value is not None and not isinstance(value, str):
                    raise RuntimeError(f'{where}: ambient.settings.{key} must be a string or null')
            for key in ('prelude', 'between'):
                if key in case:
                    validate_function_steps(f'{where}: {key}', case[key])
            state = case.get('record_state', False)
            if not (isinstance(state, bool) or (isinstance(state, list) and state and set(state) <= {'session', 'config', 'settings_user'})):
                raise RuntimeError(f'{where}: record_state must be true, false or a list of session, config, settings_user')
            for flag in ('isolated', 'record_sql', 'record_tables', 'mask_generated', 'unordered'):
                if flag in case and not isinstance(case[flag], bool):
                    raise RuntimeError(f'{where}: {flag} must be true or false')
            timeout = case.get('timeout')
            if 'timeout' in case and (isinstance(timeout, bool) or not isinstance(timeout, int) or timeout <= 0):
                raise RuntimeError(f'{where}: timeout must be a positive whole number of seconds')
        cases[name] = spec
    if not cases:
        raise RuntimeError('--only selected no function')
    return cases


def parse_function_probe(name, result):
    """Extract the record from a probe run. Anything else is a failed capture."""
    if result['exit'] or 'BEHAVIOR_JSON=' not in result['stdout']:
        raise RuntimeError(f'Function probe {name} failed: exit {result["exit"]}: '
                           + (result['stderr'] or result['stdout'])[-600:])
    prefix, payload = result['stdout'].rsplit('BEHAVIOR_JSON=', 1)
    if prefix.strip():
        # Output outside the captured call means the recorder, not the function, wrote it.
        raise RuntimeError(f'Function probe {name} printed outside the capture: ' + prefix.strip()[:300])
    return json.loads(payload)


def capture_function(name, spec, run_probe):
    """Run every case of one function and return its record.

    run_probe(name, extra_args) returns one parsed probe payload. Cases that
    share a process go in one call; each isolated case gets its own.
    """
    shared = [case['id'] for case in spec['cases'] if not case.get('isolated')]
    batches = ([(shared, [])] if shared else []) + [
        ([case['id']], ['--case', case['id']]) for case in spec['cases'] if case.get('isolated')]
    records = {}
    signature = None
    for ids, extra in batches:
        payload = run_probe(name, extra)
        if payload.get('function') != name:
            raise RuntimeError(f'{name}: probe answered for {payload.get("function")}')
        got = [record['id'] for record in payload.get('cases', [])]
        if got != ids:
            raise RuntimeError(f'{name}: expected cases {ids}, recorded {got}')
        if signature is not None and payload['signature'] != signature:
            raise RuntimeError(f'{name}: signature changed between processes')
        signature = payload['signature']
        records.update({record['id']: record for record in payload['cases']})
    ordered = [records[case['id']] for case in spec['cases']]
    if not ordered:
        raise RuntimeError(f'{name}: no case was recorded')
    return {'function': name, 'signature': signature, 'cases': ordered}


def collapse_harness_frames(match):
    """The outermost frames of a backtrace belong to the capture harness; keep only how many."""
    frames = match[2].split(', ')
    count = 0
    while count < len(frames) and re.search(r'(?:^|:)(?:behavior_\w+|array_map)\(\)$', frames[count]):
        count += 1
    if not count:
        return match[0]
    return match[1] + ', '.join(['<harness frames: %d>' % count] + frames[count:]) + match[3]


def normalize_logging_shapes(value):
    """Wall clock, PID and random ids in the output of the logging functions.

    A timestamp keeps its format and loses its digits, so a change of format
    still shows. Frame line numbers in a backtrace are the harness's, not the
    application's, and move whenever either file is edited.
    """
    value = re.sub(r'^([0-9A-Za-z/.-]+ \d{2}:\d{2}:\d{2})(?= - )',
                   lambda m: '<TIMESTAMP:' + re.sub(r'\d', '9', m[1]) + '>', value, flags=re.MULTILINE)
    value = re.sub(r'^(Total\[)\s*\d+\.\d{4}(\] )', r'\1<T>\2', value, flags=re.MULTILINE)
    value = re.sub(r'(POLLER: Poller\[\d+\] PID\[)\d+\]', r'\1<PID>]', value)
    value = re.sub(r'^<\[ \d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}\.\d{6} \| ( *\d+) \]> -- ',
                   lambda m: '<[ <DATETIME> | <PID:' + str(len(m[1])) + '> ]> -- ', value, flags=re.MULTILINE)
    value = re.sub(r"((?:copyToClipboard|clipboardData|clipboardHeader))[0-9a-f]{32}", r'\1<UID>', value)
    value = re.sub(r'((?:<HARNESS>|/harness)/[\w./-]+\.php)(?:\[\d+\]|\(\d+\)(?= : eval\(\)))', r'\1[<LINE>]', value)
    if 'Backtrace:' in value:
        value = re.sub(r'(\.php)\[\d+\]', r'\1[<LINE>]', value)
    value = re.sub(r'(Backtrace:\s*\(|^\()([^\r\n]*?)(\)\s*)$', collapse_harness_frames, value, flags=re.MULTILINE)
    return re.sub(r'(in file: (?:<HARNESS>|/harness)/\S+\.php\s+on line: )\d+', r'\1<LINE>', value)


def normalize_function_tree(value):
    if isinstance(value, dict):
        return {k: normalize_function_tree(v) for k, v in value.items()}
    if isinstance(value, list):
        return [normalize_function_tree(v) for v in value]
    value = normalize(value)
    return normalize_logging_shapes(value) if isinstance(value, str) else value


def normalize_function_record(record):
    """normalize() everywhere except the fields of a case that echo the author's input.

    The exemption is by position, not by name: a by-reference parameter called
    `id` or `args` sits deeper in the case and must still be normalized.
    """
    out = summarize_large({k: normalize_function_tree(v) for k, v in record.items() if k != 'cases'})
    # The case list is the record itself, however many cases a function has;
    # only what is inside a case is summarized.
    out['cases'] = [collapse_warm_repeat(summarize_large({k: v if k in FUNCTION_INPUT_KEYS else normalize_function_tree(v) for k, v in case.items()}))
                    for case in record['cases']]
    return out


def collapse_warm_repeat(case):
    # The recorder compares the two calls before normalization, so a field that
    # carries an elapsed time (Total[0.0012]) differs between them on every run.
    # Once the shapes are normalized the repeat may be identical after all.
    warm = case.get('warm')
    if isinstance(warm, dict) and warm.get('same') is False and warm.get('result') == case.get('result'):
        case['warm'] = {'same': True}
    return case


FUNCTION_SUMMARY_THRESHOLD = 64
FUNCTION_SUMMARY_HEAD = 4


def json_type(value):
    if isinstance(value, bool):
        return 'bool'
    if value is None:
        return 'null'
    return {int: 'int', float: 'float', str: 'string', list: 'array', dict: 'object'}[type(value)]


def summarize_large(value):
    """Replace a list or object of more than 64 elements with a digest summary.

    A 10,000 element argument would otherwise take 10,000 lines of golden. The
    sha256 covers the whole original value in canonical form, so changing any
    element still changes the golden; `head` only keeps the golden readable.
    Smaller containers are walked, since a large one may sit inside them.
    """
    if isinstance(value, (list, dict)):
        if len(value) > FUNCTION_SUMMARY_THRESHOLD:
            is_list = isinstance(value, list)
            elements = value if is_list else list(value.values())
            head = value[:FUNCTION_SUMMARY_HEAD] if is_list else dict(sorted(value.items())[:FUNCTION_SUMMARY_HEAD])
            return {'$': 'list' if is_list else 'object', 'count': len(value),
                    'types': sorted({json_type(element) for element in elements}),
                    'head': summarize_large(head),
                    'sha256': hashlib.sha256(canonical(value).encode()).hexdigest()}
        if isinstance(value, list):
            return [summarize_large(element) for element in value]
        return {key: summarize_large(element) for key, element in value.items()}
    return value


def compact_json(value, width=100, depth=0, lead=0, trailing=0):
    """Serialize with sorted keys, keeping a container on one line when its line fits in `width` columns.

    `lead` is what already precedes the value on its line (indentation and key) and
    `trailing` the comma that follows it. Larger containers get one element per line,
    two spaces per level. Fully expanded goldens ran to ~45k lines of short scalars,
    which made the generated diff unreviewable.
    """
    flat = json.dumps(value, sort_keys=True, ensure_ascii=False, separators=(', ', ': '))
    if not isinstance(value, (dict, list, tuple)) or not value or lead + len(flat) + trailing <= width:
        return flat
    pad = '  ' * (depth + 1)
    is_dict = isinstance(value, dict)
    items = sorted(value.items()) if is_dict else [(None, item) for item in value]
    opener, closer = ('{', '}') if is_dict else ('[', ']')
    lines = []
    for index, (key, item) in enumerate(items):
        if is_dict and not isinstance(key, str):
            raise TypeError('compact_json supports string keys only, got ' + repr(key))
        prefix = json.dumps(key, ensure_ascii=False) + ': ' if is_dict else ''
        lines.append(pad + prefix + compact_json(item, width, depth + 1, len(pad) + len(prefix), 0 if index == len(items) - 1 else 1))
    return opener + '\n' + ',\n'.join(lines) + '\n' + '  ' * depth + closer


def write_function_json(path, value):
    path.parent.mkdir(parents=True, exist_ok=True)
    path.write_text(compact_json(value) + '\n')


FUNCTION_CASE_DEFAULTS = {'note': '', 'warm': {'same': True}}
FUNCTION_RESULT_DEFAULTS = {'diagnostics': [], 'log': [], 'output': ''}
FUNCTION_EFFECT_KEYS = frozenset(['sql', 'sql_truncated', 'table_diff', 'state'])


def strip_function_defaults(record):
    """The golden form of a record: fields that hold their documented default are left out.

    Most cases return a value and nothing else, so spelling out an empty log,
    empty diagnostics and empty output five lines per case buried the value.
    `restore_function_defaults` is the reader's half; the pair is lossless for
    records the probe writes, which always carry every one of these fields.
    """
    def keep(mapping, defaults):
        return {k: v for k, v in mapping.items() if not (k in defaults and canonical(v) == canonical(defaults[k]))}
    cases = []
    for case in record['cases']:
        case = keep(case, FUNCTION_CASE_DEFAULTS)
        if isinstance(case.get('result'), dict):
            recorded_effects = FUNCTION_EFFECT_KEYS & set(case['result'])
            case['result'] = keep(case['result'], FUNCTION_RESULT_DEFAULTS)
            # A warm result is spelled out in full in older goldens, which stay as
            # they are. One that follows a result with recorded effects is
            # stripped like any other result, because those cases repeat it often.
            warm = case.get('warm')
            if recorded_effects and isinstance(warm, dict) and isinstance(warm.get('result'), dict):
                case['warm'] = dict(warm, result=keep(warm['result'], FUNCTION_RESULT_DEFAULTS))
        cases.append(case)
    return dict(record, cases=cases)


def restore_function_defaults(record):
    cases = []
    for case in record['cases']:
        case = dict(json.loads(canonical(FUNCTION_CASE_DEFAULTS)), **case)
        if isinstance(case.get('result'), dict):
            case['result'] = dict(json.loads(canonical(FUNCTION_RESULT_DEFAULTS)), **case['result'])
        warm = case.get('warm')
        if isinstance(warm, dict) and isinstance(warm.get('result'), dict):
            case['warm'] = dict(warm, result=dict(json.loads(canonical(FUNCTION_RESULT_DEFAULTS)), **warm['result']))
        cases.append(case)
    return dict(record, cases=cases)


def function_golden_root(target, runtime):
    return FUNCTION_GOLDENS / target / ('php-' + runtime)


def settle_function_goldens(args, records, runtime):
    """Compare records with goldens, or write them. Returns the failure list."""
    root = function_golden_root(args.target, runtime)
    failures = []
    if not args.update_golden:
        # A golden whose case file is gone would otherwise sit unchecked forever.
        orphans = {path.stem for path in root.glob('*.json')} - {path.stem for path in FUNCTION_CASES.glob('*.json')}
        failures += [name + ': golden has no case file' for name in sorted(orphans)]
    for name in sorted(records):
        path = root / (name + '.json')
        if args.update_golden:
            write_function_json(path, strip_function_defaults(records[name]))
        elif not path.exists():
            failures.append(name + ': MISSING GOLDEN (explicit capture required)')
        elif canonical(restore_function_defaults(json.loads(path.read_text()))) != canonical(records[name]):
            failures.append(name + ': REGRESSION')
            destination = CONTROLLER_ROOT / 'tests/behavior/results' / args.target
            destination.mkdir(parents=True, exist_ok=True)
            (destination / ('functions--' + name + '.diff')).write_text(''.join(difflib.unified_diff(
                path.read_text().splitlines(True), (compact_json(strip_function_defaults(records[name])) + '\n').splitlines(True),
                fromfile='golden', tofile='observed')))
    return failures


def run_function_captures(args, run_probe, runtime, php_version, cases):
    records = {}
    for name, spec in cases.items():
        records[name] = normalize_function_record(capture_function(name, spec, run_probe))
        print('CAPTURE functions/' + name + ' (' + str(len(spec['cases'])) + ' cases)', flush=True)
    destination = CONTROLLER_ROOT / 'tests/behavior/results' / args.target
    write_json(destination / 'functions.json', {'format': 1, 'target': args.target, 'php': runtime,
                                                'php_version': php_version, 'functions': records})
    return records


def report_function_run(args, records, runtime, goldens=True):
    # The empty-selection check lives in load_function_cases; a run that
    # recorded no cases must still never read as a pass.
    count = sum(len(record['cases']) for record in records.values())
    if not records or not count:
        print('No function cases were recorded', file=sys.stderr)
        return 2
    if not goldens:
        print(f'{len(records)} functions, {count} cases captured')
        return 0
    failures = settle_function_goldens(args, records, runtime)
    print('\n'.join(failures) if failures else
          f'{len(records)} functions, {count} cases ' + ('captured' if args.update_golden else 'verified'))
    return int(bool(failures))


def functions_in_container(args):
    """Capture through the container harness, the path that has a real database."""
    global ROOT
    ROOT = args.application_root.resolve()
    cases = load_function_cases(FUNCTION_CASES, args.only)
    harness = Harness(args)
    status = 2
    try:
        harness.setup()
        def probe(name, extra):
            return parse_function_probe(name, harness.php('/harness/probe.php', 'function', name, *extra))

        runtime = harness.command('php', '-r', 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;', check=True)['stdout'].strip()
        full = harness.command('php', '-r', 'echo PHP_VERSION;', check=True)['stdout'].strip()
        records = run_function_captures(args, probe, runtime, full, cases)
        status = report_function_run(args, records, runtime)
    except Exception as exc:
        print(exc, file=sys.stderr)
    finally:
        if not args.keep and getattr(harness, 'setup_started', True):
            try:
                harness.compose('down', '--volumes', '--remove-orphans', timeout=120)
            except (OSError, RuntimeError, subprocess.TimeoutExpired) as cleanup_error:
                print('Container cleanup failed: ' + str(cleanup_error), file=sys.stderr)
                status = status or 2
        elif args.keep:
            print('Kept project: ' + ' '.join(harness.dc))
    return status


def functions_in_reference(args):
    """Capture through a running upstream instance, using the same probe and cases.

    The harness image builds from a Kadupul checkout (package.json, the asset
    stage, the overlay hash check), so it cannot be pointed at a Cacti develop
    tree. This copies the probe into the instance's /tmp and runs it there as
    the web user. The instance's files and database are left as they were: every
    case runs in a rolled-back savepoint.
    """
    cases = load_function_cases(FUNCTION_CASES, args.only)
    # A fixed path would let two runs, or a stale directory from a killed run,
    # share staged files; the log lives inside it so one removal cleans both.
    staging = None
    try:
        staging = run(['docker', 'exec', args.container, 'mktemp', '-d', '/tmp/fnwt_probe.XXXXXX'])['stdout'].strip()
        docker = ['docker', 'exec', '-u', 'www-data', '-e', 'BEHAVIOR_APP_ROOT=' + args.app_dir,
                  '-e', 'BEHAVIOR_CASE_DIR=' + staging + '/function_cases', '-e', 'BEHAVIOR_LOG_PATH=' + staging + '/probe.log', args.container]
        # mktemp creates the directory 0700 as the exec user; the probe runs as www-data.
        run(['docker', 'exec', args.container, 'chmod', '755', staging])
        here = Path(__file__).resolve().parent
        for name in ('probe.php', 'function_capture.php', 'function_codec.php', 'function_cases'):
            run(['docker', 'cp', str(here / name), args.container + ':' + staging + '/' + name])
        def probe(name, extra):
            return parse_function_probe(name, run(docker + ['php', staging + '/probe.php', 'function', name, *extra], check=False))

        runtime = run(docker + ['php', '-r', 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;'])['stdout'].strip()
        full = run(docker + ['php', '-r', 'echo PHP_VERSION;'])['stdout'].strip()
        records = run_function_captures(args, probe, runtime, full, cases)
        return report_function_run(args, records, runtime, goldens=False)
    except Exception as exc:
        print(exc, file=sys.stderr)
        return 2
    finally:
        if staging:
            run(['docker', 'exec', args.container, 'rm', '-rf', staging], check=False)


def compare_functions(args):
    """List every function and case as same or differs between two captures.

    The status is observation only. A difference is written as unreviewed; the
    reviewer decides whether it is intentional or a defect on either side.
    """
    root = Path(getattr(args, 'results_root', None) or CONTROLLER_ROOT / 'tests/behavior/results')
    sides = {}
    for role in ('baseline', 'candidate'):
        path = root / getattr(args, role) / 'functions.json'
        if not path.is_file():
            raise RuntimeError('Missing ' + str(path))
        sides[role] = json.loads(path.read_text())
    base, cand = sides['baseline']['functions'], sides['candidate']['functions']
    shared = sorted(set(base) & set(cand))
    if not shared:
        raise RuntimeError('The two captures share no function')
    report = {'baseline': args.baseline, 'candidate': args.candidate,
              'baseline_php': sides['baseline'].get('php_version'), 'candidate_php': sides['candidate'].get('php_version'),
              'only_in_baseline': sorted(set(base) - set(cand)), 'only_in_candidate': sorted(set(cand) - set(base)),
              'functions': {}}
    totals = {'same': 0, 'differs': 0}
    only_candidate_cases = {}

    def observed(r):
        return {'result': r['result'], 'warm': r['warm']}

    for name in shared:
        entry = {'signature': {'status': 'same'}, 'cases': []}
        if canonical(base[name]['signature']) != canonical(cand[name]['signature']):
            entry['signature'] = {'status': 'differs', 'baseline': base[name]['signature'],
                                  'candidate': cand[name]['signature'], 'review': 'unreviewed'}
        by_id = {record['id']: record for record in cand[name]['cases']}
        extra = sorted(set(by_id) - {record['id'] for record in base[name]['cases']})
        if extra:
            only_candidate_cases[name] = extra
        for record in base[name]['cases']:
            other = by_id.get(record['id'])
            if other is not None and canonical(observed(record)) == canonical(observed(other)):
                row = {'case': record['id'], 'status': 'same'}
            else:
                row = {'case': record['id'], 'status': 'differs', 'baseline': observed(record),
                       'candidate': observed(other) if other else None, 'review': 'unreviewed'}
                if record.get('note'):
                    row['note'] = record['note']
            totals[row['status']] += 1
            entry['cases'].append(row)
        report['functions'][name] = entry
    report['totals'] = dict(totals, functions=len(shared))
    if only_candidate_cases:
        # Present only when non-empty so a clean report keeps its existing shape.
        report['only_in_candidate_cases'] = only_candidate_cases
    output = Path(args.output) if args.output else root / (args.candidate + '-vs-' + args.baseline + '.functions-comparison.json')
    write_function_json(output, report)
    print(f'{len(shared)} functions: {totals["same"]} cases same, {totals["differs"]} differ -> {output}')
    if only_candidate_cases:
        # A case the baseline never ran cannot be compared, so it must not read as a clean pass.
        print('Cases only in the candidate: ' + '; '.join(f'{n}: {", ".join(ids)}' for n, ids in only_candidate_cases.items()), file=sys.stderr)
        return 1
    return 0


def main():
    global ROOT
    parser = argparse.ArgumentParser()
    sub = parser.add_subparsers(dest='action', required=True)
    test = sub.add_parser('run')
    test.add_argument('--target', default=os.environ.get('TARGET', 'kadupul'))
    test.add_argument('--application-root', type=Path, default=CONTROLLER_ROOT,
                      help='Application checkout to exercise; harness provenance still identifies this controller checkout.')
    test.add_argument('--project', default='kadupul-behavior',
                      help='Compose project name. The default is shared, so concurrent runs are refused; pass another to run beside it.')
    test.add_argument('--update-golden', action='store_true')
    test.add_argument('--bootstrap-goldens', action='store_true',
                      help='Allow missing runtime entries during an explicit full capture; verification still requires complete inventories.')
    test.add_argument('--keep', action='store_true')
    test.add_argument('--only', nargs='*', default=None, metavar='GROUP',
                      help='Verify only these scenario groups (api, auth, devices, graphs, plugins, cli, poller, ui, database, upgrade, snmp, faults, diagnostics). All scenarios still run, because later ones consume earlier fixtures.')
    fn = sub.add_parser('functions', help='Capture function-level behavior through the container harness.')
    fn.add_argument('--target', default=os.environ.get('TARGET', 'kadupul'))
    fn.add_argument('--application-root', type=Path, default=CONTROLLER_ROOT)
    fn.add_argument('--project', default='fnwt_functions')
    fn.add_argument('--update-golden', action='store_true')
    fn.add_argument('--keep', action='store_true')
    fn.add_argument('--only', nargs='*', default=None, metavar='FUNCTION')
    ref = sub.add_parser('functions-reference', help='Capture the same cases through a running upstream instance.')
    ref.add_argument('--target', required=True)
    ref.add_argument('--container', required=True)
    ref.add_argument('--app-dir', required=True)
    ref.add_argument('--only', nargs='*', default=None, metavar='FUNCTION')
    fcmp = sub.add_parser('functions-compare', help='List each function case as same or differs between two captures.')
    fcmp.add_argument('--results-root', type=Path)
    fcmp.add_argument('--baseline', required=True)
    fcmp.add_argument('--candidate', required=True)
    fcmp.add_argument('--output')
    diff = sub.add_parser('compare')
    diff.add_argument('--results-root', type=Path, help='Directory containing capture labels; defaults to this checkout tests/behavior/results.')
    diff.add_argument('--baseline', required=True)
    diff.add_argument('--candidate', required=True)
    diff.add_argument('--repeat')
    diff.add_argument('--approvals')
    diff.add_argument('--output', help='Report path prefix; defaults to comparison under the selected results root.')
    args = parser.parse_args()
    if args.action == 'compare':
        return compare(args)
    if args.action == 'functions-compare':
        return compare_functions(args)
    for action in ('functions', 'functions-reference'):
        if args.action == action:
            if not re.fullmatch(r'[a-zA-Z0-9_.-]+', args.target) or args.target in ('.', '..'):
                parser.error('Target must be a safe artifact label')
            if args.only is not None and not args.only:
                parser.error('--only needs at least one function')
            if args.only and getattr(args, 'update_golden', False):
                parser.error('--update-golden records every function; it cannot be scoped with --only')
            return functions_in_container(args) if action == 'functions' else functions_in_reference(args)
    ROOT = args.application_root.resolve()
    if not re.fullmatch(r'[a-zA-Z0-9_.-]+', args.target) or args.target in ('.', '..'):
        parser.error('Target must be a safe artifact label')
    if args.only and args.update_golden:
        parser.error('--update-golden records every scenario; it cannot be scoped with --only')
    if args.bootstrap_goldens and (not args.update_golden or args.only is not None):
        parser.error('--bootstrap-goldens requires --update-golden without --only')
    harness = Harness(args)
    error = None
    status = 2
    try:
        harness.setup()
        if args.target == 'ci-smoke':
            from audit_schema_scenarios import assert_baseline_reproducible
            assert_baseline_reproducible(harness)
            from audit_schema_scenarios import assert_clean_schema_audit
            assert_clean_schema_audit(harness, 'Fresh install')
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
            if not args.keep and getattr(harness, 'setup_started', True):
                try:
                    harness.compose('down', '--volumes', '--remove-orphans', timeout=120)
                except (OSError, RuntimeError, subprocess.TimeoutExpired) as cleanup_error:
                    print('Container cleanup failed: ' + str(cleanup_error), file=sys.stderr)
                    status = status or 2
            elif args.keep:
                print('Kept project: ' + ' '.join(harness.dc))
    return status


if __name__ == '__main__':
    sys.exit(main())
