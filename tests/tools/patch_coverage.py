#!/usr/bin/env python3
# SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
# SPDX-License-Identifier: GPL-3.0-or-later
"""Fail when lines a change adds or modifies are not covered by the Clover reports."""

from __future__ import annotations

import argparse
import posixpath
import re
import subprocess
import sys
from pathlib import Path

import defusedxml.ElementTree as ET
from defusedxml.common import DefusedXmlException

from sonar_coverage_artifacts import mapped_path

DEFAULT_CONFIGS = ("tests/phpunit-coverage.xml", "phpunit-symfony.xml")
IGNORE_MARKER = "@codeCoverageIgnore"
HUNK = re.compile(r"^@@ -\d+(?:,\d+)? \+(\d+)(?:,(\d+))? @@")


def changed_lines(root: Path, base: str, paths: list[str], excludes: list[str]) -> dict[str, dict[int, str]]:
    specs = [f":(glob){glob}" for glob in paths] + [f":(glob,exclude){glob}" for glob in excludes]
    diff = subprocess.run(
        ["git", "-C", str(root), "-c", "core.quotePath=off", "diff", "--unified=0", "--no-color", "--no-ext-diff", "--diff-filter=d", f"{base}...HEAD", "--", *specs],
        check=True, capture_output=True, text=True,
    ).stdout
    changes: dict[str, dict[int, str]] = {}
    current: dict[int, str] | None = None
    line = 0
    for text in diff.splitlines():
        if text.startswith("+++ "):
            current = changes.setdefault(text[6:], {}) if text.startswith("+++ b/") else None
        elif (hunk := HUNK.match(text)) is not None:
            line = int(hunk.group(1))
        elif current is not None and text.startswith("+"):
            current[line] = text[1:]
            line += 1
    return {name: lines for name, lines in changes.items() if lines}


def load_clover(path: Path, root: Path) -> dict[str, dict[int, int]]:
    if path.is_symlink() or not path.is_file() or path.stat().st_size == 0:
        raise ValueError(f"{path}: missing or empty Clover report")
    document = ET.parse(path, forbid_entities=True, forbid_external=True).getroot()
    if document.tag != "coverage":
        raise ValueError(f"{path}: not a Clover report")
    files: dict[str, dict[int, int]] = {}
    for item in document.iter("file"):
        name = str(mapped_path(item.get("name", ""), str(root), root).relative_to(root))
        counts = files.setdefault(name, {})
        for entry in item.iter("line"):
            if entry.get("type") == "stmt":
                number = int(entry.get("num", "0"))
                counts[number] = max(counts.get(number, 0), int(entry.get("count", "0")))
    if not any(files.values()):
        raise ValueError(f"{path}: Clover report has no statements")
    return files


def coverage_source(root: Path, config: Path) -> tuple[list[tuple[str, str]], set[str], list[str], set[str]]:
    """Read a PHPUnit <source> block as repo-relative include and exclude rules."""
    document = ET.parse(config, forbid_entities=True, forbid_external=True).getroot()
    base = config.parent.resolve()

    def relative(value: str | None) -> str:
        return posixpath.normpath(str((base / (value or "").strip()).resolve().relative_to(root)))

    def rules(kind: str) -> tuple[list[tuple[str, str]], set[str]]:
        directories = [(relative(item.text), item.get("suffix", ".php")) for item in document.findall(f"./source/{kind}/directory")]
        return directories, {relative(item.text) for item in document.findall(f"./source/{kind}/file")}

    include_dirs, include_files = rules("include")
    exclude_dirs, exclude_files = rules("exclude")
    return include_dirs, include_files, [path for path, _ in exclude_dirs], exclude_files


def under(name: str, directory: str) -> bool:
    return directory == "." or name.startswith(directory + "/")


def measured(name: str, sources: list[tuple[list[tuple[str, str]], set[str], list[str], set[str]]]) -> bool:
    # A file PHPUnit never instruments cannot be held to line coverage, but a
    # file inside a <source> include must appear in a report or the gate fails.
    for include_dirs, include_files, exclude_dirs, exclude_files in sources:
        included = name in include_files or any(under(name, path) and name.endswith(suffix) for path, suffix in include_dirs)
        if included and name not in exclude_files and not any(under(name, path) for path in exclude_dirs):
            return True
    return False


def ranges(numbers: list[int]) -> str:
    spans: list[str] = []
    for number in sorted(numbers):
        if spans and int(spans[-1].split("-")[-1]) == number - 1:
            spans[-1] = f"{spans[-1].split('-')[0]}-{number}"
        else:
            spans.append(str(number))
    return ", ".join(spans)


def main(argv: list[str] | None = None) -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--root", type=Path, default=Path.cwd())
    parser.add_argument("--base", required=True, help="revision the change is compared against (merge base of REF...HEAD)")
    parser.add_argument("--clover", type=Path, action="append", required=True)
    parser.add_argument("--require", type=float, default=100.0, help="minimum percentage of changed executable lines covered")
    parser.add_argument("--paths", nargs="+", required=True, help="git glob pathspecs selecting files to gate")
    parser.add_argument("--exclude", action="append", default=[], help="git glob pathspec removed from --paths")
    parser.add_argument("--phpunit-config", type=Path, action="append", help="PHPUnit config whose <source> decides which files must be measured")
    args = parser.parse_args(argv)
    root = args.root.resolve()
    try:
        reports = [load_clover(path if path.is_absolute() else root / path, root) for path in args.clover]
        configs = args.phpunit_config or [Path(name) for name in DEFAULT_CONFIGS]
        sources = [coverage_source(root, path if path.is_absolute() else root / path) for path in configs]
        changes = changed_lines(root, args.base, args.paths, args.exclude)
    except (ValueError, OSError, ET.ParseError, DefusedXmlException, subprocess.CalledProcessError) as error:
        parser.exit(1, f"Patch coverage failed: {error}\n")

    failures: list[tuple[str, str]] = []
    covered = total = 0
    for name, lines in sorted(changes.items()):
        ignored = [number for number, text in lines.items() if IGNORE_MARKER in text]
        if ignored:
            failures.append((name, f"{IGNORE_MARKER} on changed line {ranges(ignored)}"))
        counts = [report[name] for report in reports if name in report]
        if not counts:
            if name.endswith(".php") and measured(name, sources):
                failures.append((name, "not measured by any Clover report"))
            continue
        executable = {number for report in counts for number in report} & lines.keys()
        missed = [number for number in executable if all(report.get(number, 0) == 0 for report in counts)]
        total += len(executable)
        covered += len(executable) - len(missed)
        if missed:
            failures.append((name, f"uncovered changed line {ranges(missed)}"))

    percent = 100.0 if total == 0 else covered * 100 / total
    print(f"Changed executable lines covered: {covered} of {total} ({percent:.2f}%, required {args.require:g}%)")
    if failures:
        width = max(len(name) for name, _ in failures)
        for name, reason in failures:
            print(f"{name:<{width}}  {reason}")
    if covered * 100 < args.require * total or any(not reason.startswith("uncovered") for _, reason in failures):
        return 1
    return 0


if __name__ == "__main__":
    sys.exit(main())
