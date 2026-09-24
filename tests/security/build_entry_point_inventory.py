# SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
# SPDX-License-Identifier: GPL-3.0-or-later
"""Print every HTTP entry point with the gate that protects it, as TSV.

This script reads what the web server decides: the Nginx deny locations in
the reference vhost and the Apache .htaccess and .htaccess.dist denies, and
stops when Nginx denies a path Apache would serve. Every file Nginx serves
goes to classify_entry_points.php, which reads the gate from the PHP AST (the
include/auth.php realm map, the CLI guards, the self-gated pages and the
Symfony routes) and reports unknown for anything it cannot prove.

Set PHP to choose the interpreter; the classifier needs composer install.
"""
import fnmatch
import json
import os
import re
import subprocess
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
CLASSIFIER = Path(__file__).resolve().parent / 'classify_entry_points.php'


def git_files(pattern, root=None):
    out = subprocess.run(['git', '-C', str(root or ROOT), 'ls-files', pattern],
                         capture_output=True, text=True, check=True).stdout
    return sorted(out.splitlines())


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


SECTION = re.compile(r'<(Files|FilesMatch|If)\s+("?)([^">]*)\2\s*>(.*?)</\1>', re.S | re.I)
REQUIRE = re.compile(r'^\s*Require\s+all\s+(denied|granted)\b', re.M | re.I)
OPT_IN = 'apache: opt-in via .htaccess.dist'


def htaccess_rules(text):
    """Directory-wide verdict and per-file or per-URI sections of one .htaccess.

    Only the Apache 2.4 Require form is read; the 2.2 blocks beside it mirror it.
    """
    text = re.sub(r'^\s*#.*$', '', text, flags=re.M)
    sections = []
    for kind, _, argument, body in SECTION.findall(text):
        verdict = REQUIRE.findall(body)
        if not verdict:
            continue
        if kind.lower() == 'files':
            pattern, subject = re.compile(fnmatch.translate(argument)), 'name'
        elif kind.lower() == 'filesmatch':
            pattern, subject = re.compile(argument), 'name'
        else:
            # Only a disjunction of REQUEST_URI matches is understood; any other
            # expression stops the build rather than being read as served.
            for clause in re.split(r'\s*\|\|\s*', argument.strip()):
                m = re.fullmatch(r'%\{REQUEST_URI\}\s*=~\s*m#([^#]*)#(i?)', clause)
                if not m:
                    raise SystemExit('ERROR: unsupported <If> expression in .htaccess: ' + argument)
                sections.append((re.compile(m[1], re.I if m[2] else 0), 'uri', verdict[-1].lower()))
            continue
        sections.append((pattern, subject, verdict[-1].lower()))
    rest = SECTION.sub('', text)
    # Any other section around a Require would be read as directory-wide.
    other = re.search(r'<(?!/?IfModule\b)/?([A-Za-z]+)', rest)
    if other and REQUIRE.search(rest):
        raise SystemExit('ERROR: unsupported <%s> section in .htaccess' % other[1])
    outside = REQUIRE.findall(rest)
    return (outside[-1].lower() if outside else None), sections


def apache_denied(path, htaccess):
    """True when the tracked .htaccess files on the way to path deny it.

    A directory entry stands for the PHP inside it, which is what the Nginx
    rules deny, so it is judged by a representative index.php. Directory
    verdicts apply first and file or URI sections after them, as Apache
    merges them.
    """
    probe = path + 'index.php' if path.endswith('/') else path
    parts = probe.split('/')
    directories = ['/'.join(parts[:i]) for i in range(len(parts))]
    denied = False
    sections = []
    for directory in directories:
        rules = htaccess.get((directory + '/' if directory else '') + '.htaccess')
        if rules is None:
            continue
        verdict, found = rules
        if verdict:
            denied = verdict == 'denied'
        sections += found
    for pattern, subject, verdict in sections:
        if pattern.search(parts[-1] if subject == 'name' else '/' + probe):
            denied = verdict == 'denied'
    return denied


def denied_rows(entries, htaccess, opt_in):
    """Rows for the Nginx-denied entries; stops on one Apache would serve."""
    rows, gaps = [], []
    for entry in sorted(entries):
        if apache_denied(entry, htaccess):
            apache = 'apache .htaccess'
        elif apache_denied(entry, opt_in):
            apache = OPT_IN
        else:
            gaps.append(entry)
            continue
        rows.append((entry, 'web-server-denied', 'nginx; ' + apache))
    # Nginx and Apache installs must refuse the same paths.
    if gaps:
        raise SystemExit('ERROR: Nginx denies these paths but no Apache .htaccess or .htaccess.dist rule does: ' + ', '.join(gaps))
    return rows


def plugin_realms(root):
    """api_plugin_load_realms() maps plugin_realms rows to id + 100."""
    sql = (root / 'cacti.sql').read_text() if (root / 'cacti.sql').is_file() else ''
    realms = {}
    for realm_id, files in re.findall(r"INSERT INTO `plugin_realms` VALUES \((\d+), '[^']*', '([^']*)'", sql.replace('REPLACE INTO', 'INSERT INTO')):
        for name in files.split(','):
            realms[name] = int(realm_id) + 100
    return realms


def classify(root, files, served):
    """Rows for the served files and the Symfony routes, from the PHP classifier."""
    request = {'root': str(root), 'files': files, 'served': served, 'plugin_realms': plugin_realms(root)}
    proc = subprocess.run([os.environ.get('PHP', 'php'), str(CLASSIFIER)], input=json.dumps(request),
                          capture_output=True, text=True)
    if proc.returncode != 0:
        raise SystemExit(proc.stderr.strip() or 'ERROR: classify_entry_points.php exited %d' % proc.returncode)
    return [tuple(row) for row in json.loads(proc.stdout)['rows']]


def main():
    denies, named = nginx_denies()
    files = git_files('*.php')

    served, denied = [], []
    for path in files:
        if any(rule.search('/' + path) for rule in denies):
            denied.append(path)
        else:
            served.append(path)

    rows = classify(ROOT, files, served)

    # Denied paths are listed as Nginx names them, whether or not the tree
    # tracks PHP there: include/vendor/ and cache/ fill up at install time.
    htaccess = {f: htaccess_rules((ROOT / f).read_text()) for f in git_files('*.htaccess')}
    # The root rules ship in .htaccess.dist, which operators rename to enable,
    # so they are reported apart from the denies every install gets.
    opt_in = {**htaccess, '.htaccess': htaccess_rules((ROOT / '.htaccess.dist').read_text())}
    entries = set(named)
    for path in denied:
        owner = [n for n in named if path == n or (n.endswith('/') and path.startswith(n))]
        entries.add(max(owner, key=len) if owner else path)
    rows += denied_rows(entries, htaccess, opt_in)

    print('entry\tgate\tdetail')
    for row in sorted(rows):
        print('\t'.join(row))
    return 0


if __name__ == '__main__':
    sys.exit(main())
