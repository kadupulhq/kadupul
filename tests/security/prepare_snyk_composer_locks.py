# SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
# SPDX-License-Identifier: GPL-3.0-or-later
"""Prepare disposable CI lockfiles for Snyk's Composer --dev parser.

Composer ignores require-dev on dependencies. The pinned Snyk parser traverses
those fields anyway and invents packages absent from the lockfile. Remove only
that unused metadata, retaining every locked package and the root manifests'
development requirements. Do not run this against a developer's working files.

https://getcomposer.org/doc/04-schema.md#require-dev
https://github.com/snyk/composer-lockfile-parser/blob/master/lib/parsers/composer-parser.ts
"""
import argparse
import json
from pathlib import Path


def prepare(content):
    lock = json.loads(content)
    if not isinstance(lock, dict):
        raise ValueError("Invalid Composer lockfile")
    count = 0
    for section in ("packages", "packages-dev"):
        packages = lock.get(section)
        if not isinstance(packages, list):
            raise ValueError("Missing Composer package inventory")
        for package in packages:
            if (not isinstance(package, dict)
                    or not isinstance(package.get("name"), str)
                    or not package["name"]
                    or not isinstance(package.get("version"), str)
                    or not package["version"]):
                raise ValueError("Invalid locked package identity")
            package.pop("require-dev", None)
            count += 1
    if count == 0:
        raise ValueError("Empty Composer package inventory")
    return json.dumps(lock, indent=4) + "\n"


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("locks", type=Path, nargs="+")
    args = parser.parse_args()
    try:
        # Validate every inventory before changing the disposable checkout.
        prepared = [(path, prepare(path.read_text(encoding="utf-8")))
                    for path in args.locks]
        for path, content in prepared:
            path.write_text(content, encoding="utf-8")
    except (OSError, ValueError, TypeError):
        parser.exit(1, "Cannot prepare Composer lockfiles for scanning\n")


if __name__ == "__main__":
    main()
