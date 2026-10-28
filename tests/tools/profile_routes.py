#!/usr/bin/env python3
# SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
# SPDX-License-Identifier: GPL-3.0-or-later
"""Compare legacy pages with their Symfony routes on time and query count.

Two passes, because the debug container that records query counts is slower
than production and would unfairly penalize the migrated route:

  time    app in APP_ENV=prod, no KADUPUL_PROFILE; wall time to last byte.
  counts  Symfony in APP_ENV=test, legacy with KADUPUL_PROFILE=1; every request
          carries a fresh X-Kadupul-Profile-Id and its record is read back
          from the profile log.

Legacy query counts are a lower bound: pages that call lib/database.php
directly at line 1501, lib/graph_data_removal.php or lib/api_aggregate.php
bypass the counter.

  profile_routes.py --base-url URL --pairs PAIRS --pass time|counts --out FILE
  profile_routes.py --compare TIME.json COUNTS.json [--budgets FILE]
"""

from __future__ import annotations

import argparse
import http.cookiejar
import json
import math
import os
import re
import secrets
import sys
import time
import urllib.error
import urllib.parse
import urllib.request
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
TIME_TOLERANCE = 1.10
# Sub-millisecond routes jitter by more than 10%, so a route also has to be
# this much slower in absolute terms before the time rule fails it.
TIME_FLOOR_MS = 2.0
# The Symfony writer runs in kernel.terminate, after fastcgi_finish_request has
# already released the response, so a record can land after the client returns.
RECORD_WAIT_SECONDS = 2.0
RECORD_POLL_SECONDS = 0.05
PROFILE_HEADER = "X-Kadupul-Profile-Id"
LEGACY_KEYS = {"legacy", "symfony"}


class ProfileError(Exception):
    pass


def median(samples: list[float]) -> float:
    if not samples:
        raise ProfileError("No samples to summarize")
    ordered = sorted(samples)
    middle = len(ordered) // 2
    if len(ordered) % 2:
        return ordered[middle]
    return (ordered[middle - 1] + ordered[middle]) / 2


def p95(samples: list[float]) -> float:
    if not samples:
        raise ProfileError("No samples to summarize")
    ordered = sorted(samples)
    return ordered[math.ceil(0.95 * len(ordered)) - 1]


def read_log(path: Path) -> dict[str, list[dict]]:
    """Group records by id. urllib replays request headers when it follows a
    redirect, so a page that redirects logs one record per hop under one id."""
    try:
        text = path.read_text()
    except OSError as error:
        raise ProfileError("Cannot read profile log %s: %s" % (path, error)) from error
    # A writer may be mid-append while the counts pass polls; its line is
    # read on the next poll once the newline lands.
    lines = text.split("\n")[:-1]
    records: dict[str, list[dict]] = {}
    for number, line in enumerate(lines, 1):
        if not line.strip():
            continue
        try:
            record = json.loads(line)
            records.setdefault(record["id"], []).append(record)
        except (ValueError, KeyError, TypeError) as error:
            raise ProfileError("Corrupt profile log line %d in %s" % (number, path)) from error
    return records


def wait_for_records(log: Path, expected: dict[str, int], timeout: float = RECORD_WAIT_SECONDS,
                     interval: float = RECORD_POLL_SECONDS) -> dict[str, list[dict]]:
    """Read the log until every id has one record per hop or the wait runs out."""
    deadline = time.monotonic() + timeout
    while True:
        records = read_log(log) if log.exists() or time.monotonic() >= deadline else {}
        if (all(len(records.get(identifier, [])) >= hops for identifier, hops in expected.items())
                or time.monotonic() >= deadline):
            return records
        time.sleep(interval)


def record_for(profile_id: str, records: dict[str, list[dict]], hops: int = 1) -> dict:
    """Combine one request's hops: queries and server time add up, memory peaks."""
    found = records.get(profile_id)
    if not found:
        raise ProfileError("No profile record for request %s" % profile_id)
    if len(found) != hops:
        raise ProfileError("Request %s made %d hop(s) but logged %d record(s)" % (profile_id, hops, len(found)))
    # null means the producer had no counter (for example APP_DEBUG=0); it
    # must not be read as zero queries.
    for record in found:
        for key in ("queries", "peak_bytes", "ms"):
            value = record.get(key)
            if isinstance(value, bool) or not isinstance(value, (int, float)):
                raise ProfileError("Profile record %s has no numeric %s" % (profile_id, key))
    return {"id": profile_id, "path": found[0].get("path"), "hops": hops,
            "queries": sum(record["queries"] for record in found),
            "ms": sum(record["ms"] for record in found),
            "peak_bytes": max(record["peak_bytes"] for record in found)}


def csrf_token(html: str) -> str:
    match = re.search(r"name=['\"]__csrf_magic['\"][^>]*value=['\"]([^'\"]*)['\"]", html)
    return match.group(1) if match else ""


def relative_url(value: object) -> bool:
    return (isinstance(value, str) and value != "" and not value.startswith("/")
            and "://" not in value and not value.startswith("\\"))


def load_pairs(path: Path) -> list[dict[str, str]]:
    try:
        pairs = json.loads(path.read_text())
    except (OSError, ValueError) as error:
        raise ProfileError("Cannot read pairs file %s: %s" % (path, error)) from error
    if not isinstance(pairs, list) or not pairs:
        raise ProfileError("Pairs file must be a non-empty list")
    for pair in pairs:
        if (not isinstance(pair, dict) or set(pair) != LEGACY_KEYS
                or not relative_url(pair["legacy"]) or not relative_url(pair["symfony"])):
            raise ProfileError("Each pair needs relative legacy and symfony URLs only: %r" % (pair,))
    return pairs


class CountingRedirects(urllib.request.HTTPRedirectHandler):
    def __init__(self) -> None:
        self.followed = 0

    def redirect_request(self, req, fp, code, msg, headers, newurl):  # type: ignore[no-untyped-def]
        self.followed += 1
        return super().redirect_request(req, fp, code, msg, headers, newurl)


class Client:
    def __init__(self, base_url: str) -> None:
        self.base = base_url if base_url.endswith("/") else base_url + "/"
        self.redirects = CountingRedirects()
        self.last_hops = 0
        self.opener = urllib.request.build_opener(
            urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()), self.redirects)

    def url(self, relative: str) -> str:
        return urllib.parse.urljoin(self.base, relative)

    def fetch(self, relative: str, profile_id: str | None = None) -> float:
        """Return milliseconds to the last byte; anything but 200 is a failure."""
        request = urllib.request.Request(self.url(relative))
        if profile_id is not None:
            request.add_header(PROFILE_HEADER, profile_id)
        self.redirects.followed = 0
        started = time.perf_counter()
        try:
            with self.opener.open(request, timeout=60) as response:
                response.read()
                status = response.status
        except urllib.error.HTTPError as error:
            raise ProfileError("%s returned HTTP %d" % (relative, error.code)) from error
        except OSError as error:
            raise ProfileError("%s failed: %s" % (relative, error)) from error
        elapsed = (time.perf_counter() - started) * 1000
        self.last_hops = 1 + self.redirects.followed
        if status != 200:
            raise ProfileError("%s returned HTTP %d" % (relative, status))
        return elapsed

    def login(self, username: str, password: str) -> None:
        try:
            with self.opener.open(self.url("login.php"), timeout=60) as response:
                token = csrf_token(response.read().decode("utf-8", "replace"))
            form = urllib.parse.urlencode({
                "__csrf_magic": token, "action": "login",
                "login_username": username, "login_password": password,
            }).encode()
            with self.opener.open(self.url("login.php"), data=form, timeout=60) as response:
                response.read()
        except OSError as error:
            raise ProfileError("Login request failed: %s" % error) from error
        # The login page answers 200 either way; only an authenticated page proves success.
        try:
            with self.opener.open(self.url("about.php"), timeout=60) as response:
                page = response.read().decode("utf-8", "replace")
        except OSError as error:
            raise ProfileError("Login failed for %s: %s" % (username, error)) from error
        if "login_username" in page:
            raise ProfileError("Login failed for %s" % username)


def summarize_time(samples: list[float]) -> dict:
    return {"median_ms": round(median(samples), 3), "p95_ms": round(p95(samples), 3),
            "samples_ms": [round(sample, 3) for sample in samples]}


def measure_pair(mode: str, client: Client, pair: dict[str, str], runs: int, warmup: int,
                 log: Path | None) -> dict[str, dict]:
    # Alternating the two sides spreads drift in the host's load evenly
    # across both, instead of loading it all onto whichever side runs second.
    sides = ("legacy", "symfony")
    for _ in range(warmup):
        for side in sides:
            client.fetch(pair[side])
    if mode == "time":
        samples: dict[str, list[float]] = {side: [] for side in sides}
        for _ in range(runs):
            for side in sides:
                samples[side].append(client.fetch(pair[side]))
        return {side: summarize_time(samples[side]) for side in sides}
    assert log is not None
    hops: dict[str, dict[str, int]] = {side: {} for side in sides}
    for _ in range(runs):
        for side in sides:
            identifier = "kp-" + secrets.token_hex(12)
            client.fetch(pair[side], identifier)
            hops[side][identifier] = client.last_hops
    records = wait_for_records(log, {**hops["legacy"], **hops["symfony"]})
    stats = {}
    for side in sides:
        found = [record_for(identifier, records, count) for identifier, count in hops[side].items()]
        stats[side] = {"queries": median([record["queries"] for record in found]),
                       "peak_bytes": max(record["peak_bytes"] for record in found),
                       "server_median_ms": round(median([record["ms"] for record in found]), 3)}
    return stats


def run_pass(mode: str, client: Client, pairs: list[dict[str, str]], runs: int,
             warmup: int, log: Path | None) -> dict:
    if mode == "counts" and log is None:
        raise ProfileError("The counts pass needs --log")
    if runs < 1 or warmup < 0:
        raise ProfileError("--runs must be positive and --warmup non-negative")
    report = {"pass": mode, "runs": runs, "warmup": warmup, "pairs": []}
    for pair in pairs:
        stats = measure_pair(mode, client, pair, runs, warmup, log)
        report["pairs"].append({**pair, "legacy_stats": stats["legacy"], "symfony_stats": stats["symfony"]})
    return report


def validate_budgets(document: object) -> dict[str, str]:
    """Return accepted exceptions as route -> reason."""
    if not isinstance(document, dict) or not isinstance(document.get("routes"), list):
        raise ProfileError("Budgets must be an object with a routes list")
    accepted = {}
    for entry in document["routes"]:
        if (not isinstance(entry, dict) or set(entry) != {"symfony", "reason"}
                or not isinstance(entry["symfony"], str) or not entry["symfony"]
                or not isinstance(entry["reason"], str) or not entry["reason"].strip()):
            raise ProfileError("A budget exception needs exactly a symfony route and a reason: %r" % (entry,))
        accepted[entry["symfony"]] = entry["reason"]
    return accepted


def compare(time_report: dict, counts_report: dict, budgets: dict) -> list[str]:
    accepted = validate_budgets(budgets)
    if ([(p["legacy"], p["symfony"]) for p in time_report["pairs"]]
            != [(p["legacy"], p["symfony"]) for p in counts_report["pairs"]]):
        raise ProfileError("The time and counts reports measured different pairs")
    failures = []
    for timed, counted in zip(time_report["pairs"], counts_report["pairs"]):
        route = timed["symfony"]
        if route in accepted:
            continue
        legacy_ms = timed["legacy_stats"]["median_ms"]
        symfony_ms = timed["symfony_stats"]["median_ms"]
        if symfony_ms > legacy_ms * TIME_TOLERANCE and symfony_ms - legacy_ms > TIME_FLOOR_MS:
            failures.append("%s: median %.1f ms exceeds legacy %s at %.1f ms by more than 10%% and %.0f ms"
                            % (route, symfony_ms, timed["legacy"], legacy_ms, TIME_FLOOR_MS))
        legacy_q = counted["legacy_stats"]["queries"]
        symfony_q = counted["symfony_stats"]["queries"]
        if symfony_q > legacy_q:
            failures.append("%s: %s queries exceed legacy %s at %s"
                            % (route, symfony_q, counted["legacy"], legacy_q))
        elif legacy_q > 0 and symfony_q == 0:
            failures.append("%s: 0 queries against legacy %s at %s; the Symfony counter is probably not recording"
                            % (route, counted["legacy"], legacy_q))
    return failures


def render(time_report: dict, counts_report: dict) -> str:
    lines = ["%-48s %10s %10s %8s %12s" % ("url", "median ms", "p95 ms", "queries", "peak bytes")]
    for timed, counted in zip(time_report["pairs"], counts_report["pairs"]):
        for side in ("legacy", "symfony"):
            t, c = timed[side + "_stats"], counted[side + "_stats"]
            lines.append("%-48s %10.1f %10.1f %8s %12s"
                         % (timed[side][:48], t["median_ms"], t["p95_ms"], c["queries"], c["peak_bytes"]))
    return "\n".join(lines)


def write_report(path: Path, report: dict) -> None:
    path.parent.mkdir(parents=True, exist_ok=True)
    path.write_text(json.dumps(report, indent=2) + "\n")


def load_json(path: Path) -> dict:
    try:
        return json.loads(path.read_text())
    except (OSError, ValueError) as error:
        raise ProfileError("Cannot read %s: %s" % (path, error)) from error


def main(argv: list[str]) -> int:
    parser = argparse.ArgumentParser(description=__doc__.split("\n")[0])
    parser.add_argument("--base-url")
    parser.add_argument("--pairs", type=Path)
    parser.add_argument("--pass", dest="mode", choices=("time", "counts"))
    parser.add_argument("--log", type=Path)
    parser.add_argument("--runs", type=int, default=20)
    parser.add_argument("--warmup", type=int, default=3)
    parser.add_argument("--out", type=Path)
    parser.add_argument("--compare", nargs=2, metavar=("TIME", "COUNTS"), type=Path)
    parser.add_argument("--budgets", type=Path, default=ROOT / "tests/performance/route-budgets.json")
    args = parser.parse_args(argv)
    try:
        if args.compare:
            time_report, counts_report = (load_json(path) for path in args.compare)
            print(render(time_report, counts_report))
            failures = compare(time_report, counts_report, load_json(args.budgets))
            for failure in failures:
                print("FAIL " + failure, file=sys.stderr)
            return 1 if failures else 0
        if not (args.base_url and args.pairs and args.mode and args.out):
            parser.error("--base-url, --pairs, --pass and --out are required")
        username = os.environ.get("KADUPUL_PROFILE_USER", "")
        password = os.environ.get("KADUPUL_PROFILE_PASSWORD", "")
        if not username or not password:
            raise ProfileError("Set KADUPUL_PROFILE_USER and KADUPUL_PROFILE_PASSWORD")
        pairs = load_pairs(args.pairs)
        client = Client(args.base_url)
        client.login(username, password)
        report = run_pass(args.mode, client, pairs, args.runs, args.warmup, args.log)
        report["base_url"] = args.base_url
        write_report(args.out, report)
        return 0
    except ProfileError as error:
        print("profile_routes: %s" % error, file=sys.stderr)
        return 1


if __name__ == "__main__":
    sys.exit(main(sys.argv[1:]))
