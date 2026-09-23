# SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
# SPDX-License-Identifier: GPL-3.0-or-later
"""Print every HTTP entry point with the gate that protects it, as TSV.

Gates are read from the code that enforces them: the Nginx deny locations,
Apache .htaccess denies, the include/auth.php realm map, the CLI guard, and the
Symfony Route attributes plus the IdentityAccess checks each controller reaches.
The few pages that authorize themselves are listed in SELF_GATED with a source
fingerprint, so an edit to their check turns them back into unknown.
"""
import re
import subprocess
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]

# Pages that bootstrap with include/global.php or nothing at all and then apply
# their own check. The fingerprint must still be present in the file, or the
# entry is reported as unknown for review.
SELF_GATED = {
    'auth_changepassword.php': ('authenticated', "if (!isset($_SESSION['sess_user_id']))",
                                'own session check; action=checkpass answers anonymously with the password policy verdict'),
    'csp_report.php': ('anonymous-allowed', "require_once(__DIR__ . '/lib/csp_report_endpoint.php')",
                       'CSP violation report sink; browsers post reports without credentials'),
    'link.php': ('realm:10000+id', "is_realm_allowed($page['id']+10000)",
                 'own realm check per external link id'),
    'remote_agent.php': ('anonymous-allowed', 'if (!remote_client_authorized())',
                         'no user session; remote_client_authorized() admits registered poller addresses only'),
    'service_check.php': ('anonymous-allowed', 'SELECT cacti FROM version',
                          'service probe; prints success or fail for the schema version only'),
}

# Reachable without the gate they need. Each is reported, not fixed, here, and
# must leave this list in the pull request that adds the gate.
UNGATED = {
    'include/themes/midwinter/update_hash.php':
        'FINDING: theme build script runs over HTTP with no CLI guard and rewrites theme CSS files; expected cli-only or web-server-denied',
}

# Symfony routes that deliberately answer without an actor.
ANONYMOUS_ROUTES = {
    'health': 'liveness probe; returns a fixed status document',
}

BOOTSTRAP = {
    'include/auth.php': 'auth',
    'include/global.php': 'global',
    'include/cli_check.php': 'cli',
    'install/cli_check.php': 'cli',
    'config/bootstrap.php': 'symfony',
    'public/index.php': 'symfony',
}

INERT = re.compile(r'^(?:(?:final |abstract |readonly )*(?:function|class|interface|trait|enum)\b|namespace\b|use\b|declare\b|global\b|define\s*\(|error_reporting\s*\(|\$[\w\[\]\x00\d\'" -]+\s*=\s*(?:array\s*\(|\[|\x00|-?\d|true\b|false\b|null\b))', re.I)


DECLARATION = re.compile(r'^(?:(?:final |abstract |readonly )*(?:function|class|interface|trait|enum)\b)', re.I)
_inert_files = {}


def inert_file(path):
    """True when requesting the file only declares symbols and assigns literals."""
    if path not in _inert_files:
        _inert_files[path] = False
        if (ROOT / path).is_file():
            code, strings = lex((ROOT / path).read_text(errors='replace'))
            _inert_files[path] = all(inert_statement(s, strings, path) for s in statements(code))
    return _inert_files[path]


def inert_statement(stmt, strings, source):
    if INERT.match(stmt):
        return True
    # Upgrade steps pull in function libraries through $config before declaring
    # their own function; that is still declaration only.
    m = re.fullmatch(r'(?:include|include_once|require|require_once)\b\s*\(?(.*?)\)?\s*;', stmt, re.S)
    if m:
        target = resolve(m[1], strings, source)
        return bool(target) and inert_file(target)
    return False


def git_files(pattern):
    out = subprocess.run(['git', '-C', str(ROOT), 'ls-files', pattern],
                         capture_output=True, text=True, check=True).stdout
    return sorted(out.splitlines())


def lex(text):
    """Return PHP code with comments dropped and strings replaced by \\x00N\\x00.

    Braces and semicolons inside string literals would otherwise corrupt the
    statement split. Inline HTML outside PHP tags becomes an echo, because it
    is output.
    """
    code, strings, i, n = [], [], 0, len(text)
    in_php = False
    while i < n:
        if not in_php:
            j = text.find('<?php', i)
            end = n if j < 0 else j
            chunk = text[i:end]
            if i == 0 and chunk.startswith('#!'):
                chunk = chunk.partition('\n')[2]
            if chunk.strip():
                code.append(' echo HTML;')
            if j < 0:
                break
            i, in_php = j + 5, True
            continue
        c = text[i]
        if text.startswith('?>', i):
            in_php, i = False, i + 2
            if i < n and text[i] == '\n':
                i += 1
            code.append(';')
        elif c == '#' and not text.startswith('#[', i) or text.startswith('//', i):
            while i < n and text[i] != '\n' and not text.startswith('?>', i):
                i += 1
        elif text.startswith('/*', i):
            j = text.find('*/', i + 2)
            i = n if j < 0 else j + 2
        elif c in '\'"':
            j = i + 1
            while j < n and text[j] != c:
                j += 2 if text[j] == '\\' else 1
            strings.append(text[i + 1:j])
            code.append('\x00%d\x00' % (len(strings) - 1))
            i = j + 1
        elif text.startswith('<<<', i):
            m = re.match(r"<<<[ \t]*(['\"]?)(\w+)\1\r?\n", text[i:])
            if not m:
                code.append(c)
                i += 1
                continue
            body_start = i + m.end()
            end = re.compile(r'^[ \t]*' + m[2] + r'\b', re.M).search(text, body_start)
            stop = n if end is None else end.end()
            strings.append(text[body_start:stop])
            code.append('\x00%d\x00' % (len(strings) - 1))
            i = stop
        else:
            code.append(c)
            i += 1
    return ''.join(code), strings


def statements(code):
    """Split code into top-level statements; a block ends its statement."""
    out, start, depth, i = [], 0, 0, 0
    while i < len(code):
        c = code[i]
        if c in '([{':
            depth += 1
        elif c in ')]}':
            depth -= 1
            if c == '}' and depth == 0:
                rest = code[i + 1:].lstrip()
                if not re.match(r'(?:else|elseif|catch|finally)\b', rest):
                    out.append(code[start:i + 1].strip())
                    start = i + 1
        elif c == ';' and depth == 0:
            out.append(code[start:i + 1].strip())
            start = i + 1
        i += 1
    tail = code[start:].strip()
    if tail:
        out.append(tail)
    return [s for s in out if s and s != ';']


def expand(statement, strings):
    return re.sub(r'\x00(\d+)\x00', lambda m: "'" + strings[int(m[1])] + "'", statement)


INCLUDE = re.compile(r'\b(?:include|include_once|require|require_once)\b\s*\(?(.*?)\)?\s*;', re.S)
PREFIXES = {
    "$config['base_path']": '', "$config['include_path']": 'include', "$config['library_path']": 'lib',
}


def resolve(expression, strings, source):
    """Resolve a static include expression to a repository path, or None."""
    here = str(Path(source).parent) if '/' in source else ''
    base = None
    literal = ''
    for part in [p.strip() for p in expression.split('.') if p.strip()] if '\x00' in expression else []:
        m = re.fullmatch(r'\x00(\d+)\x00', part)
        if m:
            literal += strings[int(m[1])]
        elif part in ('__DIR__', 'dirname(__FILE__)'):
            base = here
        elif part == 'dirname(__DIR__)':
            base = str(Path(here).parent) if here else ''
            base = '' if base == '.' else base
        elif expand(part, strings) in PREFIXES:
            base = PREFIXES[expand(part, strings)]
        else:
            return None
    if not literal:
        return None
    candidates = [base] if base is not None else ['', here]
    for start in candidates:
        parts = [p for p in (start + '/' + literal).split('/') if p]
        stack = []
        for p in parts:
            if p == '..':
                if stack:
                    stack.pop()
            elif p != '.':
                stack.append(p)
        path = '/'.join(stack)
        if (ROOT / path).is_file() or start is candidates[-1]:
            return path
    return None


def includes(stmt, strings, source):
    return [resolve(m[1], strings, source) for m in INCLUDE.finditer(stmt + ';')]


def nginx_denies():
    """Deny rules and the literal paths they name, from the reference vhost."""
    text = (ROOT / 'tests/e2e/nginx.conf').read_text()
    rules, named = [], set()
    for flag, pattern in re.findall(r'location\s+(~\*?)\s+(\S+)\s*\{\s*return\s+404;', text):
        rules.append(re.compile(pattern, re.I if flag == '~*' else 0))
        m = re.match(r'\^/((?:[\w-]+/)*)\(([^()]*)\)(.*)$', pattern)
        if not m:
            continue
        directory = m[3].startswith(('(/|$)', '/'))
        for alternative in m[2].split('|'):
            path = m[1] + alternative.replace('\\.', '.')
            if re.search(r'[\\\[\]()?*+^$]', path):
                continue
            if directory:
                named.add(path + '/')
            elif path.endswith('.php'):
                named.add(path)
    if not rules:
        raise SystemExit('ERROR: no deny locations parsed from tests/e2e/nginx.conf')
    return rules, named


def apache_denied(path, htaccess):
    """True when a tracked .htaccess in the path or an ancestor denies all."""
    parent = Path(path.rstrip('/')) if path.endswith('/') else Path(path).parent
    while str(parent) not in ('', '.'):
        if str(parent / '.htaccess') in htaccess:
            return True
        parent = parent.parent
    return False


def realm_map():
    text = (ROOT / 'include/global_arrays.php').read_text()
    block = re.search(r'\$user_auth_realm_filenames\s*=\s*array\((.*?)\);', text, re.S)
    if not block:
        raise SystemExit('ERROR: $user_auth_realm_filenames not found in include/global_arrays.php')
    pairs = re.findall(r"'([^']+)'\s*=>\s*(-?\d+)", block[1])
    # An entry the pattern cannot read would silently become realm:0.
    if len(pairs) != block[1].count('=>'):
        raise SystemExit('ERROR: unparsed entry in $user_auth_realm_filenames')
    realms = {name: int(value) for name, value in pairs}
    # api_plugin_load_realms() maps plugin_realms rows to id + 100.
    sql = (ROOT / 'cacti.sql').read_text()
    for realm_id, files in re.findall(r"INSERT INTO `plugin_realms` VALUES \((\d+), '[^']*', '([^']*)'", sql.replace('REPLACE INTO', 'INSERT INTO')):
        for name in files.split(','):
            realms[name] = int(realm_id) + 100
    return realms


def auth_early_returns():
    code, strings = lex((ROOT / 'include/auth.php').read_text())
    pages = []
    for stmt in statements(code):
        m = re.match(r"if\s*\(\s*get_current_page\(\)\s*==\s*\x00(\d+)\x00\s*\)\s*\{\s*return\s+true;\s*\}$", stmt)
        if m:
            pages.append(strings[int(m[1])])
    return pages


def symfony_realms():
    """Realm ids that the legacy session adapter checks for each ConsoleAccess method."""
    text = (ROOT / 'src/IdentityAccess/Infrastructure/Legacy/LegacyAuthenticatedSession.php').read_text()
    found = {}
    for method in ('consoleActor', 'canManageDevices'):
        body = re.search(r'function ' + method + r'\(.*?\n    \}', text, re.S)
        realm = re.search(r'hasRealm\(\$[\w>-]+,\s*(\d+)\)', body[0]) if body else None
        if not realm:
            raise SystemExit('ERROR: realm check for ' + method + ' not found in LegacyAuthenticatedSession')
        found[method] = realm[1]
    return found


def class_file(name):
    if not name.startswith('Kadupul\\'):
        return None
    path = ROOT / 'src' / (name[len('Kadupul\\'):].replace('\\', '/') + '.php')
    return path if path.is_file() else None


def reached_checks(controller):
    """IdentityAccess checks reachable from a controller through its Kadupul imports.

    Two levels cover controller -> use case -> adapter. Contract interfaces
    only declare the methods, so a call is required, not a mention.
    """
    seen, frontier, calls = set(), [controller], set()
    for _ in range(3):
        following = []
        for path in frontier:
            if path in seen:
                continue
            seen.add(path)
            text = path.read_text()
            for method in ('consoleActor', 'canManageDevices'):
                if re.search(r'->' + method + r'\(', text):
                    calls.add(method)
            namespace = re.search(r'^namespace\s+([\w\\]+);', text, re.M)
            names = re.findall(r'^use\s+(Kadupul\\[\w\\]+);', text, re.M)
            if namespace:
                names += [namespace[1] + '\\' + t for t in re.findall(r'\b([A-Z]\w+)\s+\$\w+', text)]
            following += [f for f in (class_file(n) for n in names) if f and f not in seen]
        frontier = following
    return calls


def symfony_routes():
    realms = symfony_realms()
    rows = []
    # Every file that declares a route, wherever it lives under src/.
    controllers = [f for f in git_files('src/*.php') if '#[Route' in (ROOT / f).read_text()]
    for controller in [ROOT / f for f in controllers]:
        text = controller.read_text()
        checks = reached_checks(controller)
        # Attributes may span lines; a count mismatch means the parser missed
        # one, and a missed route must fail the check rather than vanish.
        attributes = re.findall(r'#\[Route\((.*?)\)\]', text, re.S)
        if len(attributes) != text.count('#[Route'):
            rows.append(('app.php', 'unknown', 'unparsed #[Route] attribute in ' + controller.name))
        for attribute in attributes:
            path = re.match(r"\s*'([^']+)'", attribute)[1]
            name = re.search(r"name:\s*'([^']+)'", attribute)[1]
            methods = re.search(r'methods:\s*\[([^\]]*)\]', attribute)
            methods = '|'.join(re.findall(r"'(\w+)'", methods[1])) if methods else 'ANY'
            requirements = ','.join(k + '=' + v for k, v in re.findall(r"'(\w+)'\s*=>\s*'([^']+)'", (re.search(r'requirements:\s*\[([^\]]*)\]', attribute) or ['', ''])[1]))
            detail = 'methods=' + methods + (' requirements=' + requirements if requirements else '')
            if 'consoleActor' in checks:
                grant = 'realm ' + realms['consoleActor']
                if 'canManageDevices' in checks:
                    grant += ' + realm ' + realms['canManageDevices']
                gate, detail = 'symfony:' + name, detail + '; ConsoleAccess ' + grant
            elif name in ANONYMOUS_ROUTES:
                gate, detail = 'symfony:' + name, detail + '; anonymous-allowed: ' + ANONYMOUS_ROUTES[name]
            else:
                gate, detail = 'unknown', detail + '; no IdentityAccess check reached from ' + controller.name
            rows.append(('app.php' + path, gate, detail))
    return rows


def classify(path, realms, early, includers):
    text = (ROOT / path).read_text(errors='replace')
    code, strings = lex(text)
    stmts = statements(code)
    name = Path(path).name

    if path in UNGATED:
        return 'ungated', UNGATED[path]
    if path in SELF_GATED:
        gate, fingerprint, reason = SELF_GATED[path]
        if fingerprint not in text:
            return 'unknown', 'self-gated fingerprint missing: ' + fingerprint
        return gate, reason

    before = []
    for stmt in stmts:
        # Function and class bodies do not run when the file is requested.
        if DECLARATION.match(stmt):
            continue
        kinds = [BOOTSTRAP.get(p) for p in includes(stmt, strings, path) if p]
        kind = next((k for k in kinds if k), None)
        full = expand(stmt, strings)
        guard = re.match(r"if\s*\(\s*php_sapi_name\(\)\s*!==?\s*'cli'\s*\)\s*\{\s*(?:http_response_code\(\d+\);\s*)?(?:die|exit)\b", full)
        if kind == 'cli' or guard:
            unsafe = [s for s in before if re.search(r'\b(?:include|require|echo|print|header|db_\w+)\b', s)]
            if unsafe:
                return 'unknown', 'CLI guard follows output or includes'
            return 'cli-only', 'guard: ' + ('php_sapi_name() check' if guard else 'include/cli_check.php')
        if kind == 'auth' or (path == 'include/auth.php' and 'global.php' in full and 'require' in full):
            extras = []
            if any(re.search(r'\$guest_account\s*=', expand(s, strings)) for s in before + [stmt]):
                extras.append('guest_account')
            for flag in ('auth_json', 'auth_text'):
                if re.search(r'\$' + flag + r'\s*=\s*true', expand(' '.join(stmts), strings)):
                    extras.append(flag)
            suffix = ('; ' + ', '.join(extras)) if extras else ''
            if name in early:
                return 'anonymous-allowed', 'include/auth.php returns before the session check' + suffix
            realm = realms.get(name, 0)
            if realm == -1:
                return 'authenticated', 'include/auth.php realm -1' + suffix
            if realm == 0:
                return 'realm:0', 'include/auth.php; unmapped page, denied to every account' + suffix
            return 'realm:%d' % realm, 'include/auth.php' + suffix
        if kind == 'symfony':
            target = re.search(r'app\.php(/[\w/.-]+)', full + ' ' + expand(code, strings))
            if target:
                return 'symfony:forward', 'forwards to app.php' + target[1]
            return 'symfony:front-controller', 'Symfony kernel; routes listed as app.php/... rows'
        if kind == 'global':
            return 'unknown', 'bootstraps include/global.php without a known gate'
        before.append(stmt)

    active = [s for s in stmts if not inert_statement(s, strings, path)]
    if not active:
        return 'anonymous-allowed', 'inert: declarations and literal assignments only'
    if len(active) <= 2 and re.match(r"header\s*\(\s*\x00\d+\x00\s*\)\s*;", active[0]) \
            and re.match(r'location\s*:', strings[int(re.search(r'\x00(\d+)\x00', active[0])[1])], re.I) \
            and all(re.match(r'(?:exit|die)\b', s) for s in active[1:]):
        return 'anonymous-allowed', 'redirect-only'
    if path in includers:
        return 'anonymous-allowed', 'fragment without bootstrap; included by ' + ', '.join(sorted(includers[path])[:3])
    return 'unknown', 'no gate recognised'


def main():
    denies, named = nginx_denies()
    realms = realm_map()
    early = auth_early_returns()
    files = git_files('*.php')

    served, denied = [], []
    for path in files:
        if any(rule.search('/' + path) for rule in denies):
            denied.append(path)
        else:
            served.append(path)

    includers = {}
    for path in files:
        if path.startswith(('tests/', 'include/vendor/', 'docs/')):
            continue
        code, strings = lex((ROOT / path).read_text(errors='replace'))
        for m in INCLUDE.finditer(code):
            target = resolve(m[1], strings, path)
            if target and target != path:
                includers.setdefault(target, set()).add(path)

    rows = []
    for path in served:
        gate, detail = classify(path, realms, early, includers)
        rows.append((path, gate, detail))

    # Denied paths are listed as Nginx names them, whether or not the tree
    # tracks PHP there: include/vendor/ and cache/ fill up at install time.
    htaccess = {f for f in git_files('*.htaccess')
                if re.search(r'Require\s+all\s+denied', (ROOT / f).read_text())}
    entries = set(named)
    for path in denied:
        owner = [n for n in named if path == n or (n.endswith('/') and path.startswith(n))]
        entries.add(max(owner, key=len) if owner else path)
    for entry in entries:
        apache = 'apache .htaccess' if apache_denied(entry, htaccess) else 'no apache .htaccess deny'
        rows.append((entry, 'web-server-denied', 'nginx; ' + apache))

    rows += symfony_routes()

    print('entry\tgate\tdetail')
    for row in sorted(rows):
        print('\t'.join(row))
    return 0


if __name__ == '__main__':
    sys.exit(main())
