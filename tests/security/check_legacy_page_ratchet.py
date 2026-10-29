#!/usr/bin/env python3
# SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
# SPDX-License-Identifier: GPL-3.0-or-later

"""Hold the number of legacy root web pages to what the base revision has.

The entry-point inventory already records the gate of every root page. A page
counts as legacy when it is a root-level PHP file served as a web page by the
legacy bootstrap, that is, its gate is not a Symfony forward, CLI-only or
denied at the web server. Migration turns these into forwarding shims, so the
count may fall and may not rise.

Usage:
  check_legacy_page_ratchet.py [--root DIR] [--baseline PATH] [--base REF]
"""

import argparse
import csv
import io
import subprocess
import sys
from pathlib import Path

BASELINE = "tests/security/baselines/entry_points.baseline.tsv"
# Exact gates only: a prefix match would let any new "symfony:*" gate on a
# root file leave the count.
NOT_LEGACY_WEB = ("symfony:forward", "cli-only", "web-server-denied")
FRONT_CONTROLLER = ("app.php", "symfony:front-controller")


def legacy_pages(text):
    """Root-level PHP entries whose gate is a legacy web gate, sorted."""
    reader = csv.reader(io.StringIO(text), delimiter="\t")
    header = next(reader, None)
    if header is None or header[:2] != ["entry", "gate"]:
        raise ValueError("baseline does not start with an entry/gate header")
    pages = set()
    for number, row in enumerate(reader, start=2):
        if len(row) < 2 or not row[0] or not row[1]:
            raise ValueError("baseline line %d is malformed" % number)
        entry, gate = row[0], row[1]
        if "/" in entry or not entry.endswith(".php"):
            continue
        if gate not in NOT_LEGACY_WEB and (entry, gate) != FRONT_CONTROLLER:
            pages.add(entry)
    return sorted(pages)


def base_text(root, ref, path):
    result = subprocess.run(
        ["git", "-C", str(root), "show", "%s:%s" % (ref, path)],
        capture_output=True, text=True,
    )
    if result.returncode != 0:
        # A missing base baseline must not read as zero pages, or every page
        # would pass as already allowed.
        raise ValueError("cannot read %s at %s: %s" % (path, ref, result.stderr.strip()))
    return result.stdout


def check(current, base):
    """Error lines when the current pages outnumber the base pages."""
    if len(current) <= len(base):
        return []
    added = sorted(set(current) - set(base))
    return [
        "legacy root web pages rose from %d to %d" % (len(base), len(current)),
        "new legacy entries: %s" % (", ".join(added) if added else "none by name; a page was reclassified"),
        "Build the page as a Symfony controller behind a forwarding shim instead.",
    ]


def main(argv=None):
    parser = argparse.ArgumentParser(description=__doc__.splitlines()[0])
    parser.add_argument("--root", type=Path, default=Path(__file__).resolve().parents[2], help="repository root")
    parser.add_argument("--baseline", default=BASELINE, help="baseline path relative to the root")
    parser.add_argument("--base", metavar="REF", help="revision whose baseline sets the allowed count")
    args = parser.parse_args(argv)

    root = args.root
    try:
        current = legacy_pages((root / args.baseline).read_text())
        base = None
        if args.base:
            base = legacy_pages(base_text(root, args.base, args.baseline))
    except (OSError, ValueError) as error:
        print("ERROR: %s" % error, file=sys.stderr)
        return 2

    print("legacy root web pages at HEAD: %d" % len(current))
    if base is None:
        return 0
    print("legacy root web pages at %s: %d" % (args.base, len(base)))
    errors = check(current, base)
    if errors:
        print("ERROR: " + "\n  ".join(errors))
        return 1
    print("OK: legacy root web pages did not rise")
    return 0


if __name__ == "__main__":
    sys.exit(main())
