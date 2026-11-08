#!/usr/bin/env python3
"""Advisory mutation testing of the src/ lines a change adds or modifies.

SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
SPDX-License-Identifier: GPL-3.0-or-later

Runs Infection with --git-diff-lines against the merge base of --base and
HEAD, then writes a Markdown summary of the outcome. The exit status separates
the two results a reviewer needs to tell apart:

  0  Infection produced a valid report; or tests ran but none executed a
     changed line; or no PHP line under src/ was added or modified. Escaped
     and uncovered mutants are reported, never fatal.
  1  The tool is broken: no merge base, the initial test run failed, ran no
     tests or wrote no coverage, Infection exited nonzero, or its JSON
     report is missing or malformed.

Coverage comes from the PHPUnit suite in phpunit-symfony.xml. The legacy Pest
suite evaluates extracted source (tests/Helpers/PhpSource.php), so its coverage
cannot be attributed to files and legacy code is not mutated.
"""
import argparse
import json
import os
from pathlib import Path
import re
import shutil
import subprocess
import sys

import defusedxml.ElementTree as ElementTree
from defusedxml.common import DefusedXmlException

CONFIG = "infection.json5"
PHPUNIT_CONFIG = "phpunit-symfony.xml"
SOURCE_DIRECTORY = "src"
MAX_LISTED = 50
# Infection's success message when coverage contains none of the changed lines.
NOT_EXECUTED = "No source code from the diff was executed by the test framework."
STAT_KEYS = (
    "totalMutantsCount", "killedCount", "killedByStaticAnalysisCount",
    "notCoveredCount", "escapedCount", "errorCount", "syntaxErrorCount",
    "skippedCount", "ignoredCount", "timeOutCount",
)
RESULT_KEYS = ("escaped", "uncovered", "timeouted", "errored", "syntaxErrors")
HUNK = re.compile(r"^@@ -\d+(?:,\d+)? \+\d+(?:,(\d+))? @@")


class ToolFailure(Exception):
    """Infection, git or the report cannot be trusted; never a mutation result."""


def git(root, *arguments):
    result = subprocess.run(["git", "-C", str(root), *arguments],
                            capture_output=True, text=True)
    if result.returncode != 0:
        raise ToolFailure(f"git {' '.join(arguments)} failed: {result.stderr.strip()}")
    return result.stdout


def read_config(root):
    """Parse infection.json5, which keeps comments to whole lines only."""
    try:
        text = (root / CONFIG).read_text(encoding="utf-8")
        config = json.loads("\n".join(line for line in text.splitlines()
                                      if not line.lstrip().startswith("//")))
        paths = (config["phpUnit"]["configDir"], config["logs"]["json"], config["tmpDir"])
    except (OSError, ValueError, KeyError, TypeError) as error:
        raise ToolFailure(f"cannot read {CONFIG}: {error}") from error
    if not all(isinstance(path, str) and path for path in paths):
        raise ToolFailure(f"{CONFIG} must name phpUnit.configDir, logs.json and tmpDir")
    directory, report, temporary = (root / path for path in paths)
    return directory, report, temporary / "initial-coverage"


def changed_lines(root, base):
    """Count lines Infection will consider: added or modified PHP under src/.

    Mirrors Infection's own diff (working tree against the base, filter AM).
    """
    diff = git(root, "--no-pager", "diff", base, "--no-ext-diff", "--no-color",
               "--unified=0", "--diff-filter=AM",
               # Fixed prefixes: diff.noprefix or diff.mnemonicPrefix would
               # otherwise hide every file from the "+++ b/" match below.
               "--src-prefix=a/", "--dst-prefix=b/", "--",
               # Git pathspec wildcards cross directory separators.
               f"{SOURCE_DIRECTORY}/*.php")
    counts = {}
    current = None
    for line in diff.splitlines():
        if line.startswith("+++ "):
            current = line[6:] if line.startswith("+++ b/") else None
        elif current is not None:
            match = HUNK.match(line)
            if match:
                added = 1 if match.group(1) is None else int(match.group(1))
                if added:
                    counts[current] = counts.get(current, 0) + added
    return counts


def write_phpunit_config(root, directory):
    """Copy phpunit-symfony.xml under the only file name Infection discovers.

    Infection reads <configDir>/phpunit.xml and resolves the relative paths it
    rewrites (bootstrap, //directory, //file, testsuite excludes) against
    configDir. The copy makes those paths, and cacheDirectory, absolute from
    the repository root, so the suite is the one composer test runs.
    """
    source = root / PHPUNIT_CONFIG
    try:
        tree = ElementTree.parse(source)
    except (OSError, ElementTree.ParseError, DefusedXmlException) as error:
        raise ToolFailure(f"cannot read {PHPUNIT_CONFIG}: {error}") from error
    configuration = tree.getroot()
    if configuration.tag != "phpunit":
        raise ToolFailure(f"{PHPUNIT_CONFIG} is not a PHPUnit configuration")

    def absolute(value):
        value = value.strip()
        return value if os.path.isabs(value) else str(root / value)

    # Infection otherwise randomizes the order; keep the file order composer
    # test uses, so a passing composer test means a passing initial run.
    configuration.attrib.setdefault("executionOrder", "default")
    for attribute in ("bootstrap", "cacheDirectory"):
        if configuration.get(attribute):
            configuration.set(attribute, absolute(configuration.get(attribute)))
    for element in configuration.iter():
        if element.tag in ("directory", "file", "exclude") and (element.text or "").strip():
            element.text = absolute(element.text)
    directory.mkdir(parents=True, exist_ok=True)
    target = directory / "phpunit.xml"
    # Replace, never write through, whatever is there: a link would redirect
    # the write to its target.
    if target.is_symlink() or target.exists():
        target.unlink()
    # ElementTree serializes the schema-instance namespace with its standard
    # xsi prefix, which Infection reads to detect the PHPUnit schema version.
    with open(target, "xb") as handle:
        tree.write(handle, encoding="UTF-8", xml_declaration=True)


def executed_tests(path):
    """Count the tests the initial run executed, from its JUnit log."""
    try:
        suites = ElementTree.parse(path).getroot()
    except (OSError, ElementTree.ParseError, DefusedXmlException) as error:
        raise ToolFailure(f"the initial test run left no readable JUnit log at {path}: {error}") from error
    try:
        return sum(int(suite.get("tests", "0")) for suite in suites.findall("testsuite"))
    except ValueError as error:
        raise ToolFailure(f"the JUnit log at {path} has an invalid test count") from error


def run_initial_tests(arguments, root, coverage):
    """Run the composer test suite once, with coverage, for Infection to reuse.

    Infection's own initial run stops at the first byte on stderr, which this
    suite writes on purpose (logged kernel errors, probed missing commands).
    Running it here keeps composer test's exact command and judges it by its
    exit status, test count and coverage report instead.
    """
    if coverage.exists():
        shutil.rmtree(coverage)
    coverage.mkdir(parents=True)
    command = [arguments.php, "-d", f"pcov.directory={root / SOURCE_DIRECTORY}",
               str(root / "include/vendor/bin/phpunit"), f"--configuration={root / PHPUNIT_CONFIG}",
               f"--coverage-xml={coverage / 'coverage-xml'}", f"--log-junit={coverage / 'junit.xml'}"]
    print("+ " + " ".join(command), flush=True)
    status = subprocess.run(command, cwd=root).returncode
    if status != 0:
        raise ToolFailure(f"the initial test run exited with status {status}; composer test must pass first")
    tests = executed_tests(coverage / "junit.xml")
    if tests == 0:
        raise ToolFailure("the initial test run executed no tests")
    if not (coverage / "coverage-xml" / "index.xml").is_file():
        raise ToolFailure("the initial test run wrote no coverage; load pcov or Xdebug")
    return tests


def run_infection(command, root):
    """Stream Infection's output while keeping it for outcome classification."""
    print("+ " + " ".join(command), flush=True)
    output = []
    with subprocess.Popen(command, cwd=root, stdout=subprocess.PIPE,
                          stderr=subprocess.STDOUT, text=True, errors="replace") as process:
        for line in process.stdout:
            sys.stdout.write(line)
            output.append(line)
    sys.stdout.flush()
    return process.returncode, "".join(output)


def load_report(path):
    try:
        report = json.loads(path.read_text(encoding="utf-8"))
    except (OSError, ValueError) as error:
        raise ToolFailure(f"Infection did not write a readable report at {path}: {error}") from error
    stats = report.get("stats") if isinstance(report, dict) else None
    if not isinstance(stats, dict):
        raise ToolFailure("the Infection report has no stats")
    for key in STAT_KEYS:
        if not isinstance(stats.get(key), int) or stats[key] < 0:
            raise ToolFailure(f"the Infection report has no valid {key}")
    for key in RESULT_KEYS:
        if not isinstance(report.get(key), list):
            raise ToolFailure(f"the Infection report has no {key} list")
    for key, count in (("escaped", "escapedCount"), ("timeouted", "timeOutCount")):
        if len(report[key]) != stats[count]:
            raise ToolFailure(f"the Infection report lists {len(report[key])} {key} mutants but counts {stats[count]}")
    return report


def fence(text):
    longest = max((len(run) for run in re.findall(r"`+", text)), default=0)
    return "`" * max(3, longest + 1)


def location(root, entry):
    mutator = entry.get("mutator") if isinstance(entry, dict) else None
    if not isinstance(mutator, dict):
        raise ToolFailure("the Infection report has a mutant without mutator details")
    path = str(mutator.get("originalFilePath", ""))
    try:
        path = str(Path(path).resolve().relative_to(root))
    except ValueError:
        pass
    return f"{path}:{mutator.get('originalStartLine', '?')}", str(mutator.get("mutatorName", "?"))


def render(root, base, lines, report):
    stats = report["stats"]
    out = ["## Mutation testing on changed lines (advisory)", "",
           f"Base `{base}`: {sum(lines.values())} added or modified lines in "
           f"{len(lines)} file(s) under `src/`.", ""]
    rows = (("Mutants generated", "totalMutantsCount"), ("Killed by tests", "killedCount"),
            ("Escaped", "escapedCount"), ("Not covered by any test", "notCoveredCount"),
            ("Timed out", "timeOutCount"), ("Errored", "errorCount"),
            ("Syntax errors", "syntaxErrorCount"), ("Skipped", "skippedCount"),
            ("Ignored", "ignoredCount"))
    out += ["| Result | Count |", "| --- | ---: |"]
    out += [f"| {label} | {stats[key]} |" for label, key in rows]
    out += [f"| MSI | {stats.get('msi', 0)}% |",
            f"| Covered code MSI | {stats.get('coveredCodeMsi', 0)}% |", ""]
    if stats["totalMutantsCount"] == 0:
        out += ["The changed lines contain no mutable code.", ""]
    elif stats["escapedCount"] == 0 and stats["notCoveredCount"] == 0:
        out += ["Every generated mutant was detected.", ""]
    else:
        out += ["Escaped and uncovered mutants are advisory. Each one is a change to the "
                "code that no test noticed; add an assertion that fails for it, or say "
                "in the review why the behavior does not matter.", ""]
    for key, title in (("escaped", "Escaped mutants"), ("uncovered", "Mutants on lines no test covers")):
        entries = report[key]
        if not entries:
            continue
        out += [f"### {title}", ""]
        for entry in entries[:MAX_LISTED]:
            where, mutator = location(root, entry)
            if key == "escaped":
                diff = str(entry.get("diff", "")).rstrip()
                marker = fence(diff)
                out += [f"`{where}` {mutator}", "", f"{marker}diff", diff, marker, ""]
            else:
                out += [f"- `{where}` {mutator}"]
        if len(entries) > MAX_LISTED:
            out += ["", f"{len(entries) - MAX_LISTED} more in the uploaded report."]
        out += [""]
    return "\n".join(out)


def write_summary(text, paths):
    for path in paths:
        with open(path, "a", encoding="utf-8") as handle:
            handle.write(text.rstrip() + "\n")


def fail(error, summaries):
    message = f"Mutation testing tool failure, not a mutation result: {error}"
    write_summary(f"## Mutation testing on changed lines (advisory)\n\n**{message}**", summaries)
    print(f"::error::{message}" if os.environ.get("GITHUB_ACTIONS") == "true" else message,
          file=sys.stderr)
    return 1


def main(argv=None):
    parser = argparse.ArgumentParser(description=__doc__,
                                     formatter_class=argparse.RawDescriptionHelpFormatter)
    parser.add_argument("--base", required=True,
                        help="branch, tag or commit the change is compared with")
    parser.add_argument("--php", default="php", help="PHP executable that runs Infection")
    parser.add_argument("--threads", default="1",
                        help="Infection worker count; tests share var/cache, so 1 by default")
    parser.add_argument("--summary", action="append", default=[], type=Path,
                        help="Markdown file to append the summary to, e.g. $GITHUB_STEP_SUMMARY")
    parser.add_argument("infection_arguments", nargs="*",
                        help="extra Infection options, after --")
    arguments = parser.parse_args(argv)

    try:
        root = Path(git(Path.cwd(), "rev-parse", "--show-toplevel").strip()).resolve()
        config_directory, report_path, coverage = read_config(root)
    except ToolFailure as error:
        # No report directory is known yet; only the requested summaries get it.
        return fail(error, arguments.summary)
    report_directory = report_path.parent
    report_directory.mkdir(parents=True, exist_ok=True)
    summaries = [report_directory / "summary.md", *arguments.summary]
    (report_directory / "summary.md").write_text("", encoding="utf-8")
    try:
        merge_base = git(root, "merge-base", arguments.base, "HEAD").strip()
        lines = changed_lines(root, merge_base)
        if not lines:
            write_summary("## Mutation testing on changed lines (advisory)\n\n"
                          f"No PHP line under `src/` was added or modified against `{merge_base}`; "
                          "nothing to mutate.", summaries)
            print("No added or modified PHP lines under src/; nothing to mutate.")
            return 0
        write_phpunit_config(root, config_directory)
        # A report left by an earlier run must not pass for this run's result.
        if report_path.exists():
            report_path.unlink()
        tests = run_initial_tests(arguments, root, coverage)
        command = [arguments.php, str(root / "include/vendor/bin/infection"),
                   f"--configuration={root / CONFIG}", "--git-diff-lines",
                   f"--git-diff-base={merge_base}", f"--coverage={coverage}",
                   "--skip-initial-tests", f"--threads={arguments.threads}",
                   "--show-mutations=max", "--no-interaction", "--no-ansi",
                   *arguments.infection_arguments]
        status, output = run_infection(command, root)
        if status != 0:
            raise ToolFailure(f"Infection exited with status {status}")
        if not report_path.exists():
            if NOT_EXECUTED not in output:
                raise ToolFailure(f"Infection wrote no report at {report_path}")
            files = "\n".join(f"- `{path}`: {count} line(s)" for path, count in sorted(lines.items()))
            write_summary("## Mutation testing on changed lines (advisory)\n\n"
                          f"None of the {tests} tests executed any changed line, so no mutant "
                          f"was generated. Changed lines against `{merge_base}`:\n\n{files}",
                          summaries)
            print(f"{tests} tests ran; none executed a changed line.")
            return 0
        report = load_report(report_path)
        write_summary(render(root, merge_base, lines, report), summaries)
    except ToolFailure as error:
        return fail(error, summaries)
    stats = report["stats"]
    print(f"Mutants {stats['totalMutantsCount']}, killed {stats['killedCount']}, "
          f"escaped {stats['escapedCount']}, not covered {stats['notCoveredCount']}, "
          f"MSI {stats.get('msi', 0)}%.")
    return 0


if __name__ == "__main__":
    sys.exit(main())
