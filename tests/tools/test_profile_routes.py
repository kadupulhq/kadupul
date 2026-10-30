#!/usr/bin/env python3
# SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
# SPDX-License-Identifier: GPL-3.0-or-later
"""Exercise the route profiler without a Kadupul server."""

import json
import tempfile
import threading
import unittest
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from pathlib import Path

import profile_routes as pr


def timing(legacy_ms, symfony_ms, legacy_q=5, symfony_q=5, route="app.php/inventory/devices"):
    pair = {"legacy": "host.php", "symfony": route}
    time_report = {"pass": "time", "pairs": [
        {**pair, "legacy_stats": {"median_ms": legacy_ms, "p95_ms": legacy_ms},
         "symfony_stats": {"median_ms": symfony_ms, "p95_ms": symfony_ms}}]}
    counts_report = {"pass": "counts", "pairs": [
        {**pair, "legacy_stats": {"queries": legacy_q, "peak_bytes": 1},
         "symfony_stats": {"queries": symfony_q, "peak_bytes": 1}}]}
    return time_report, counts_report


class StatisticsTest(unittest.TestCase):
    def test_median_of_odd_and_even_samples(self):
        self.assertEqual(pr.median([3, 1, 2]), 2)
        self.assertEqual(pr.median([4, 1, 3, 2]), 2.5)

    def test_p95_is_nearest_rank(self):
        self.assertEqual(pr.p95(list(range(1, 21))), 19)
        self.assertEqual(pr.p95([7]), 7)

    def test_empty_samples_are_refused(self):
        with self.assertRaises(pr.ProfileError):
            pr.median([])
        with self.assertRaises(pr.ProfileError):
            pr.p95([])


class ParsingTest(unittest.TestCase):
    def test_log_records_are_joined_by_id_and_skip_blank_lines(self):
        with tempfile.TemporaryDirectory() as owned:
            log = Path(owned) / "requests.jsonl"
            log.write_text('{"id":"a-1","path":"/x","ms":1.5,"queries":4,"peak_bytes":100}\n\n'
                           '{"id":"b-2","path":"/y","ms":2,"queries":0,"peak_bytes":50}\n')
            records = pr.read_log(log)
            self.assertEqual(pr.record_for("a-1", records)["queries"], 4)
            self.assertEqual(pr.record_for("b-2", records)["queries"], 0)

    def test_a_redirect_logs_one_record_per_hop_and_the_hops_add_up(self):
        with tempfile.TemporaryDirectory() as owned:
            log = Path(owned) / "requests.jsonl"
            log.write_text('{"id":"a","path":"/about/legacy","ms":1,"queries":1,"peak_bytes":5}\n'
                           '{"id":"a","path":"/about","ms":2,"queries":2,"peak_bytes":3}\n')
            record = pr.record_for("a", pr.read_log(log), hops=2)
            self.assertEqual((record["queries"], record["ms"], record["peak_bytes"], record["path"]),
                             (3, 3, 5, "/about/legacy"))

    def test_more_records_than_hops_is_a_double_write(self):
        records = {"a": [{"queries": 1, "peak_bytes": 1, "ms": 1}, {"queries": 1, "peak_bytes": 1, "ms": 1}]}
        with self.assertRaises(pr.ProfileError):
            pr.record_for("a", records, hops=1)

    def test_an_unterminated_last_line_is_left_for_the_next_read(self):
        with tempfile.TemporaryDirectory() as owned:
            log = Path(owned) / "requests.jsonl"
            log.write_text('{"id":"a","path":"/x","ms":1,"queries":1,"peak_bytes":1}\n{"id":"b","pa')
            self.assertEqual(set(pr.read_log(log)), {"a"})

    def test_missing_log_and_corrupt_line_fail(self):
        with tempfile.TemporaryDirectory() as owned:
            with self.assertRaises(pr.ProfileError):
                pr.read_log(Path(owned) / "absent.jsonl")
            log = Path(owned) / "bad.jsonl"
            log.write_text("not json\n")
            with self.assertRaises(pr.ProfileError):
                pr.read_log(log)

    def test_a_request_id_without_a_record_is_a_failure(self):
        with self.assertRaises(pr.ProfileError):
            pr.record_for("absent-id", {"other": [{"queries": 1, "peak_bytes": 1, "ms": 1}]})

    def test_record_without_numeric_counters_is_a_failure(self):
        with self.assertRaises(pr.ProfileError):
            pr.record_for("a", {"a": [{"id": "a", "queries": "x", "peak_bytes": 1, "ms": 1}]})

    def test_null_queries_are_a_failure_not_zero(self):
        for record in ({"id": "a", "queries": None, "peak_bytes": 1, "ms": 1}, {"id": "a", "peak_bytes": 1, "ms": 1}):
            with self.subTest(record=record), self.assertRaises(pr.ProfileError):
                pr.record_for("a", {"a": [record]})
        hops = [{"queries": 2, "peak_bytes": 1, "ms": 1}, {"queries": None, "peak_bytes": 1, "ms": 1}]
        with self.assertRaises(pr.ProfileError):
            pr.record_for("a", {"a": hops}, hops=2)

    def test_csrf_token_extraction(self):
        html = "<form><input type='hidden' name='__csrf_magic' value='sid:abc,123' /></form>"
        self.assertEqual(pr.csrf_token(html), "sid:abc,123")
        self.assertEqual(pr.csrf_token("<form></form>"), "")


class PairsTest(unittest.TestCase):
    def load(self, text):
        with tempfile.TemporaryDirectory() as owned:
            path = Path(owned) / "pairs.json"
            path.write_text(text)
            return pr.load_pairs(path)

    def test_valid_pairs(self):
        pairs = self.load('[{"legacy":"host.php","symfony":"app.php/inventory/devices"}]')
        self.assertEqual(pairs, [{"legacy": "host.php", "symfony": "app.php/inventory/devices"}])

    def test_malformed_pairs_are_refused(self):
        for text in ("{", "{}", "[]", '[1]', '[{"legacy":"a.php"}]',
                     '[{"legacy":"","symfony":"b"}]', '[{"legacy":"a","symfony":"b","x":1}]',
                     '[{"legacy":"http://evil/","symfony":"b"}]',
                     '[{"legacy":"a","symfony":"//evil/b"}]'):
            with self.subTest(text=text), self.assertRaises(pr.ProfileError):
                self.load(text)

    def test_shipped_pairs_cover_every_forwarding_shim(self):
        root = Path(__file__).resolve().parents[2]
        pairs = pr.load_pairs(root / "tests/tools/route-pairs.json")
        self.assertEqual(len(pairs), 16)
        baseline = (root / "tests/security/baselines/entry_points.baseline.tsv").read_text().splitlines()
        shims = {line.split("\t")[0]: line.split("\t")[2] for line in baseline
                 if line.split("\t")[1] == "symfony:forward"}
        # color_templates_items.php needs a template id to render; the query is not part of the route.
        for pair in pairs:
            legacy, symfony = pair["legacy"].split("?")[0], pair["symfony"].split("?")[0]
            self.assertEqual(shims[legacy], "forwards to " + symfony)
        self.assertEqual(set(shims), {pair["legacy"].split("?")[0] for pair in pairs})


class ComparisonTest(unittest.TestCase):
    def compare(self, time_report, counts_report, budgets=None):
        return pr.compare(time_report, counts_report, budgets or {"routes": []})

    def test_within_ten_percent_and_equal_queries_pass(self):
        self.assertEqual(self.compare(*timing(100, 110)), [])
        self.assertEqual(self.compare(*timing(100, 90, 5, 3)), [])

    def test_more_than_ten_percent_slower_fails(self):
        failures = self.compare(*timing(100, 110.1))
        self.assertEqual(len(failures), 1)
        self.assertIn("median", failures[0])

    def test_a_slower_route_within_the_absolute_floor_passes(self):
        self.assertEqual(self.compare(*timing(5, 6.9)), [])
        self.assertEqual(self.compare(*timing(10, 12)), [])
        self.assertEqual(len(self.compare(*timing(10, 12.1))), 1)

    def test_more_than_the_floor_but_within_ten_percent_passes(self):
        self.assertEqual(self.compare(*timing(100, 105)), [])

    def test_any_extra_query_fails(self):
        failures = self.compare(*timing(100, 100, 5, 6))
        self.assertEqual(len(failures), 1)
        self.assertIn("quer", failures[0])

    def test_zero_symfony_queries_against_a_counting_legacy_page_fails(self):
        failures = self.compare(*timing(100, 100, 5, 0))
        self.assertEqual(len(failures), 1)
        self.assertIn("0 queries", failures[0])

    def test_zero_on_both_sides_is_accepted(self):
        self.assertEqual(self.compare(*timing(100, 100, 0, 0)), [])

    def test_zero_query_exception_with_reason_is_accepted(self):
        budgets = {"routes": [{"symfony": "app.php/inventory/devices", "reason": "static page"}]}
        self.assertEqual(self.compare(*timing(100, 100, 5, 0), budgets), [])

    def test_budget_exception_with_reason_accepts_the_route(self):
        budgets = {"routes": [{"symfony": "app.php/inventory/devices", "reason": "renders the new filter bar"}]}
        self.assertEqual(self.compare(*timing(100, 300, 5, 9), budgets), [])

    def test_budget_exception_for_another_route_does_not_apply(self):
        budgets = {"routes": [{"symfony": "app.php/other", "reason": "x"}]}
        self.assertEqual(len(self.compare(*timing(100, 300), budgets)), 1)

    def test_budget_exception_without_a_reason_is_refused(self):
        for entry in ({"symfony": "app.php/a"}, {"symfony": "app.php/a", "reason": "  "},
                      {"reason": "x"}, {"symfony": "app.php/a", "reason": "x", "extra": 1}):
            with self.subTest(entry=entry), self.assertRaises(pr.ProfileError):
                pr.validate_budgets({"routes": [entry]})

    def test_malformed_budget_documents_are_refused(self):
        for document in ([], {}, {"routes": {}}, {"routes": [1]}):
            with self.subTest(document=document), self.assertRaises(pr.ProfileError):
                pr.validate_budgets(document)

    def test_reports_for_different_pairs_are_refused(self):
        time_report, counts_report = timing(100, 100)
        counts_report["pairs"][0]["symfony"] = "app.php/elsewhere"
        with self.assertRaises(pr.ProfileError):
            self.compare(time_report, counts_report)


class Handler(BaseHTTPRequestHandler):
    log_path = None
    sessions = set()
    paths = []

    def log_message(self, *args):
        pass

    def reply(self, status, body, headers=()):
        data = body.encode()
        self.send_response(status)
        self.send_header("Content-Length", str(len(data)))
        for name, value in headers:
            self.send_header(name, value)
        self.end_headers()
        self.wfile.write(data)

    def do_GET(self):
        if self.path == "/login.php":
            return self.reply(200, "<input name='__csrf_magic' value='tok' />")
        if "sid=ok" not in self.headers.get("Cookie", ""):
            return self.reply(403, "denied")
        Handler.paths.append(self.path)
        if self.path == "/broken.php":
            return self.reply(500, "boom")
        redirect = self.path == "/redirect.php"
        profile_id = self.headers.get("X-Kadupul-Profile-Id")
        if profile_id and self.path != "/norecord.php" and Handler.log_path:
            line = json.dumps({"id": profile_id, "path": self.path, "ms": 1.0,
                               "queries": 3, "peak_bytes": 2048}) + "\n"
            if self.path == "/late.php":
                # Mirrors kernel.terminate writing after the response is sent.
                threading.Timer(0.3, Handler.append, (line,)).start()
            else:
                Handler.append(line)
        if redirect:
            return self.reply(302, "", [("Location", "/b.php")])
        self.reply(200, "page")

    @staticmethod
    def append(line):
        with open(Handler.log_path, "a") as log:
            log.write(line)

    def do_POST(self):
        length = int(self.headers.get("Content-Length", 0))
        form = self.rfile.read(length).decode()
        if "login_password=good" in form and "__csrf_magic=tok" in form:
            return self.reply(200, "welcome", [("Set-Cookie", "sid=ok; Path=/")])
        self.reply(200, "login failed")


class ClientTest(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.owned = tempfile.TemporaryDirectory()
        Handler.log_path = str(Path(cls.owned.name) / "requests.jsonl")
        cls.server = ThreadingHTTPServer(("127.0.0.1", 0), Handler)
        cls.base = "http://127.0.0.1:%d/" % cls.server.server_port
        threading.Thread(target=cls.server.serve_forever, daemon=True).start()

    @classmethod
    def tearDownClass(cls):
        cls.server.shutdown()
        cls.server.server_close()
        cls.owned.cleanup()

    def setUp(self):
        Path(Handler.log_path).write_text("")

    def test_login_failure_fails_closed(self):
        with self.assertRaises(pr.ProfileError):
            pr.Client(self.base).login("admin", "wrong")

    def test_time_pass_discards_warmup_and_reports_stats(self):
        client = pr.Client(self.base)
        client.login("admin", "good")
        report = pr.run_pass("time", client, [{"legacy": "a.php", "symfony": "b.php"}],
                             runs=4, warmup=2, log=None)
        stats = report["pairs"][0]["legacy_stats"]
        self.assertEqual(len(stats["samples_ms"]), 4)
        self.assertGreater(stats["median_ms"], 0)
        self.assertGreaterEqual(stats["p95_ms"], stats["median_ms"])

    def test_both_passes_alternate_legacy_and_symfony_requests(self):
        client = pr.Client(self.base)
        client.login("admin", "good")
        for mode, log in (("time", None), ("counts", Path(Handler.log_path))):
            with self.subTest(mode=mode):
                Handler.paths.clear()
                pr.run_pass(mode, client, [{"legacy": "a.php", "symfony": "b.php"}], runs=3, warmup=1, log=log)
                self.assertEqual(Handler.paths, ["/a.php", "/b.php"] * 4)

    def test_counts_pass_joins_records_by_fresh_ids(self):
        client = pr.Client(self.base)
        client.login("admin", "good")
        report = pr.run_pass("counts", client, [{"legacy": "a.php", "symfony": "b.php"}],
                             runs=3, warmup=1, log=Path(Handler.log_path))
        stats = report["pairs"][0]["symfony_stats"]
        self.assertEqual((stats["queries"], stats["peak_bytes"]), (3, 2048))
        ids = [json.loads(line)["id"] for line in Path(Handler.log_path).read_text().splitlines()]
        self.assertEqual(len(ids), len(set(ids)))
        self.assertEqual(len(ids), 2 * 3)

    def test_counts_pass_adds_up_the_hops_of_a_redirect(self):
        client = pr.Client(self.base)
        client.login("admin", "good")
        report = pr.run_pass("counts", client, [{"legacy": "redirect.php", "symfony": "b.php"}],
                             runs=2, warmup=0, log=Path(Handler.log_path))
        self.assertEqual(report["pairs"][0]["legacy_stats"]["queries"], 6)
        self.assertEqual(report["pairs"][0]["symfony_stats"]["queries"], 3)

    def test_counts_pass_without_a_record_fails(self):
        client = pr.Client(self.base)
        client.login("admin", "good")
        with self.assertRaises(pr.ProfileError):
            pr.run_pass("counts", client, [{"legacy": "norecord.php", "symfony": "b.php"}],
                        runs=2, warmup=0, log=Path(Handler.log_path))

    def test_counts_pass_waits_for_a_record_written_after_the_response(self):
        client = pr.Client(self.base)
        client.login("admin", "good")
        report = pr.run_pass("counts", client, [{"legacy": "late.php", "symfony": "b.php"}],
                             runs=1, warmup=0, log=Path(Handler.log_path))
        self.assertEqual(report["pairs"][0]["legacy_stats"]["queries"], 3)

    def test_record_wait_is_bounded(self):
        started = pr.time.monotonic()
        records = pr.wait_for_records(Path(Handler.log_path), {"never-written": 1}, timeout=0.2, interval=0.02)
        self.assertNotIn("never-written", records)
        self.assertLess(pr.time.monotonic() - started, 1.0)

    def test_record_wait_tolerates_a_log_created_late(self):
        with tempfile.TemporaryDirectory() as owned:
            log = Path(owned) / "requests.jsonl"
            threading.Timer(0.1, log.write_text, ('{"id":"x","path":"/","ms":1,"queries":1,"peak_bytes":1}\n',)).start()
            self.assertIn("x", pr.wait_for_records(log, {"x": 1}, timeout=2.0, interval=0.02))
        with tempfile.TemporaryDirectory() as owned, self.assertRaises(pr.ProfileError):
            pr.wait_for_records(Path(owned) / "absent.jsonl", {"x": 1}, timeout=0.05, interval=0.01)

    def test_non_200_response_fails(self):
        client = pr.Client(self.base)
        client.login("admin", "good")
        with self.assertRaises(pr.ProfileError):
            pr.run_pass("time", client, [{"legacy": "broken.php", "symfony": "b.php"}],
                        runs=2, warmup=1, log=None)

    def test_unauthenticated_fetch_fails(self):
        with self.assertRaises(pr.ProfileError):
            pr.Client(self.base).fetch("a.php")


if __name__ == "__main__":
    unittest.main()
