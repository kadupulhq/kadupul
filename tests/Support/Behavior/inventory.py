"""Regenerate the lexical inventory of Cacti's behavioral surface.

This is a discovery aid, not coverage. It reports where behavior is declared so
scenarios can be aimed; dynamic hook names and multi-line expressions are not
resolved here and still need manual review.

Writes docs/testing/behavioral-inventory.json and refreshes the revision line in
docs/testing/behavioral-surface.md so the two never drift apart.
"""
import json
import re
import subprocess
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[3]
DOCS = ROOT / 'docs/testing'

# Directories that are not the application's own behavioral surface.
EXCLUDED = ('include/vendor/', 'tests/', 'docs/', 'locales/', 'scripts/pkg/')

PATTERNS = {
    'functions': re.compile(r'^\s*(?:public |private |protected |static )*function\s+\w+\s*\('),
    'hooks':     re.compile(r'api_plugin_hook(?:_function)?\s*\(\s*[\'"]([\w.]+)[\'"]'),
    # Every dispatched action, not only ajax_-prefixed ones: Cacti serves JSON
    # from actions that carry no such prefix, and a rewrite must route them all.
    'actions':   re.compile(r'case\s+[\'"]([\w.-]+)[\'"]\s*:'),
    'ajax':      re.compile(r'(?:case\s+[\'"]|action=|[\'"]action[\'"]\s*=>\s*[\'"])(ajax[\w.-]*)'),
    'database':  re.compile(r'\bdb_(?:fetch_\w+|execute\w*|insert\w*|update\w*|affected_rows|qstr\w*)\s*\('),
    'external':  re.compile(r'\b(?:cacti_snmp_\w+|snmp\w*_get\w*|rrdtool_function_\w+|rrd_\w+)\s*\('),
}


def tracked_php():
    out = subprocess.run(['git', '-C', str(ROOT), 'ls-files', '*.php'],
                         capture_output=True, text=True, check=True).stdout
    return [p for p in out.splitlines() if not p.startswith(EXCLUDED)]


def main():
    revision = subprocess.run(['git', '-C', str(ROOT), 'rev-parse', 'HEAD'],
                              capture_output=True, text=True, check=True).stdout.strip()
    files = tracked_php()

    inventory = {
        'revision': revision,
        'entrypoints': sorted(f for f in files if '/' not in f),
        'cli': sorted(f for f in files if f.startswith('cli/')),
        'functions': [], 'hooks': [], 'actions': [], 'ajax': [], 'database': [], 'external': [],
    }

    for relative in sorted(files):
        try:
            lines = (ROOT / relative).read_text(errors='replace').splitlines()
        except OSError:
            continue
        for number, line in enumerate(lines, 1):
            location = f'{relative}:{number}'
            if PATTERNS['functions'].match(line):
                inventory['functions'].append({'location': location, 'signature': line.strip()})
            for match in PATTERNS['hooks'].finditer(line):
                inventory['hooks'].append({'location': location, 'hook': match[1]})
            for match in PATTERNS['actions'].finditer(line):
                inventory['actions'].append({'location': location, 'action': match[1]})
            for match in PATTERNS['ajax'].finditer(line):
                inventory['ajax'].append({'location': location, 'action': match[1],
                                          'source': line.strip()})
            if PATTERNS['database'].search(line):
                inventory['database'].append({'location': location, 'source': line.strip()})
            if PATTERNS['external'].search(line):
                inventory['external'].append({'location': location, 'source': line.strip()})

    DOCS.mkdir(parents=True, exist_ok=True)
    (DOCS / 'behavioral-inventory.json').write_text(json.dumps(inventory, indent=1) + '\n')

    surface = DOCS / 'behavioral-surface.md'
    if surface.exists():
        text = surface.read_text()
        updated = re.sub(r'Baseline revision: `[0-9a-f]{7,40}`',
                         f'Baseline revision: `{revision}`', text, count=1)
        if updated != text:
            surface.write_text(updated)

    for key, value in inventory.items():
        if isinstance(value, list):
            print(f'{key}: {len(value)}')
    print(f'revision: {revision}')
    return 0


if __name__ == '__main__':
    sys.exit(main())
