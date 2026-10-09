#!/usr/bin/env python3
# SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
# SPDX-License-Identifier: GPL-3.0-or-later
"""Run the existing Pest suite or its reviewed, isolated parallel subset."""

import argparse
import json
import os
from pathlib import Path
import subprocess
import sys
import xml.etree.ElementTree as ET

ROOT = Path(__file__).resolve().parents[2]


def processes(value: str) -> int:
    count = int(value)
    if not 1 <= count <= 8:
        raise argparse.ArgumentTypeError("Use between 1 and 8 worker processes.")
    return count


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("mode", choices=("unit", "profile", "parallel"))
    parser.add_argument("--processes", type=processes, default=2)
    parser.add_argument("--php-version", default="8.4.25", help="Installed mise PHP runtime.")
    parser.add_argument("--filter", help="Pest test-name filter; an empty selection fails.")
    parser.add_argument("--with-coverage", action="store_true")
    parser.add_argument("--parallel-suite", action="store_true", help="Profile the reviewed subset serially.")
    parser.add_argument("--junit", type=Path)
    parser.add_argument("--dry-run", action="store_true")
    args = parser.parse_args()
    if (args.mode == "parallel" or args.parallel_suite) and args.with_coverage:
        parser.error("The parallel lane is for feedback only; use the full serial coverage suite.")
    if args.filter is not None and not args.filter.strip():
        parser.error("A filter must contain a test name or pattern.")
    if args.junit is not None and (args.junit.exists() or args.junit.is_symlink()):
        parser.error("Use a new JUnit report path to preserve earlier evidence.")
    # Python's mise environment can expose the system PHP on PATH. Resolve the
    # requested interpreter through mise rather than letting child tests drift.
    try:
        php = subprocess.check_output(
            ["mise", "exec", f"php@{args.php_version}", "--", "php", "-r", "echo PHP_BINARY;"],
            cwd=ROOT, text=True,
        ).strip()
    except (OSError, subprocess.CalledProcessError):
        parser.error("Select an installed PHP runtime through mise before running Pest.")
    if not (ROOT / "tests/vendor/bin/pest").is_file():
        parser.error("Install the locked tests/composer.lock dependencies first.")
    configuration = "phpunit-parallel.xml" if args.mode == "parallel" or args.parallel_suite else "phpunit-coverage.xml"
    command = [php, "-d", "memory_limit=2G", "-d", "opcache.jit=off", "-d", "opcache.jit_buffer_size=0"]
    if args.with_coverage:
        command += ["-d", "pcov.enabled=1", "-d", f"pcov.directory={ROOT}"]
    command += ["vendor/bin/pest", "--test-directory=.", "--fail-on-empty-test-suite",
                f"--configuration={configuration}", "--colors=never"]
    if not args.with_coverage:
        command.append("--no-coverage")
    if args.mode == "parallel":
        command += ["--parallel", f"--processes={args.processes}"]
    if args.mode == "profile":
        command.append("--profile")
    if args.filter is not None:
        command.append(f"--filter={args.filter}")
    if args.junit is not None:
        command.append(f"--log-junit={args.junit.resolve()}")
    if configuration == "phpunit-parallel.xml":
        # Pest's serial loader does not discover DSL tests from PHPUnit <file>
        # entries alone. Explicit selectors keep serial/parallel cases equal.
        files = ET.parse(ROOT / "tests" / configuration).findall("./testsuites/testsuite/file")
        if not files:
            parser.error("The parallel test inventory must not be empty.")
        for entry in files:
            selector = (entry.text or "").removeprefix("./")
            source = (ROOT / "tests" / selector).resolve()
            if not source.is_relative_to(ROOT / "tests/Unit") or not source.is_file():
                parser.error(f"Invalid parallel test selector: {selector}")
            if args.mode != "parallel":
                command.append(selector)
    if args.dry_run:
        print(json.dumps({"cwd": str(ROOT / "tests"), "argv": command}, indent=2))
        return 0
    environment = os.environ.copy()
    environment["PATH"] = str(Path(php).parent) + os.pathsep + environment.get("PATH", "")
    status = subprocess.run(command, cwd=ROOT / "tests", env=environment, check=False).returncode
    if status == 0 and args.junit is not None:
        try:
            if not ET.parse(args.junit).findall(".//testcase"):
                raise ValueError("No test cases in the JUnit report.")
        except (OSError, ET.ParseError, ValueError):
            print("Pest exited successfully without a complete nonempty JUnit report.", file=sys.stderr)
            return 1
    return status if status >= 0 else 128 - status


if __name__ == "__main__":
    raise SystemExit(main())
