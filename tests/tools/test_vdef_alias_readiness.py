"""Behavioral checks for the owned Apache prefix setup barrier.

SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
SPDX-License-Identifier: GPL-3.0-or-later
"""

from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
import importlib.util
from pathlib import Path
import threading
import time
import unittest


source = Path(__file__).resolve().parents[1] / 'Symfony/vdef_scenarios.py'
spec = importlib.util.spec_from_file_location('vdef_alias_fixture', source)
fixture = importlib.util.module_from_spec(spec)
spec.loader.exec_module(fixture)


class AliasReadinessTest(unittest.TestCase):
    def setUp(self):
        self.marker = 'vdef-prefix-ready-' + 'a' * 32 + '.txt'
        self.expected = b'owned marker value'
        self.requests = []
        self.responses = [(404, b'not yet active', {})]
        owner = self

        class Handler(BaseHTTPRequestHandler):
            def do_GET(self):
                owner.requests.append((self.path, dict(self.headers)))
                status, body, headers = owner.responses[0]
                if len(owner.responses) > 1:
                    owner.responses.pop(0)
                self.send_response(status)
                for name, value in headers.items():
                    self.send_header(name, value)
                self.end_headers()
                self.wfile.write(body)

            def log_message(self, *args):
                pass

        self.server = ThreadingHTTPServer(('127.0.0.1', 0), Handler)
        self.thread = threading.Thread(target=self.server.serve_forever, daemon=True)
        self.thread.start()
        self.addCleanup(self.stop_server)
        self.base = 'http://127.0.0.1:' + str(self.server.server_port)

    def stop_server(self):
        self.server.shutdown()
        self.server.server_close()
        self.thread.join(timeout=2)
        self.assertFalse(self.thread.is_alive())

    def test_waits_for_exact_owned_alias_bytes_after_reload_responses(self):
        self.responses = [(404, b'old alias', {}), (503, b'reloading', {}), (200, self.expected, {})]
        fixture.wait_for_prefix_alias(self.base, self.marker, self.expected, 1)
        self.assertEqual(3, len(self.requests))
        for path, headers in self.requests:
            self.assertEqual('/cacti/' + self.marker, path)
            self.assertEqual('close', headers['Connection'])
            self.assertEqual('no-store', headers['Cache-Control'])
            self.assertNotIn('Cookie', headers)
            self.assertNotIn('Authorization', headers)

    def test_permanent_missing_alias_expires_instead_of_admitting_browser(self):
        started = time.monotonic()
        with self.assertRaisesRegex(RuntimeError, 'deadline expired: HTTP 404'):
            fixture.wait_for_prefix_alias(self.base, self.marker, self.expected, 0.05)
        self.assertLess(time.monotonic() - started, 1)
        self.assertGreater(len(self.requests), 0)

    def test_http_200_with_wrong_or_extra_bytes_is_not_readiness(self):
        for body in (b'generic application page', self.expected + b'extra'):
            with self.subTest(body=body):
                self.requests.clear()
                self.responses = [(200, body, {})]
                with self.assertRaisesRegex(RuntimeError, 'unexpected marker response: HTTP 200'):
                    fixture.wait_for_prefix_alias(self.base, self.marker, self.expected, 1)
                self.assertEqual(1, len(self.requests))

    def test_redirect_and_server_failure_are_not_retried_or_followed(self):
        for status in (302, 500):
            with self.subTest(status=status):
                self.requests.clear()
                self.responses = [(status, b'error', {'Location': self.base + '/unexpected'})]
                with self.assertRaisesRegex(RuntimeError, 'readiness failed: HTTP ' + str(status)):
                    fixture.wait_for_prefix_alias(self.base, self.marker, self.expected, 1)
                self.assertEqual(1, len(self.requests))

    def test_only_owned_loopback_marker_is_admitted(self):
        for base, marker, duration in (('http://example.invalid', self.marker, 1),
                                       (self.base + '/elsewhere', self.marker, 1),
                                       (self.base, '../outside', 1),
                                       (self.base, self.marker, 0),
                                       (self.base, self.marker, 11)):
            with self.subTest(base=base, marker=marker, duration=duration):
                with self.assertRaises(ValueError):
                    fixture.wait_for_prefix_alias(base, marker, self.expected, duration)
        self.assertEqual([], self.requests)


class CleanupTest(unittest.TestCase):
    class Harness:
        def __init__(self):
            self.calls = []

        def command(self, *arguments, **options):
            self.calls.append('command')
            raise RuntimeError('sensitive command details must not replace original failure')

        def compose(self, *arguments, **options):
            self.calls.append('disable alias')

    def test_all_cleanup_runs_without_replacing_original_failure(self):
        harness = self.Harness()
        original = AssertionError('original browser failure')
        fixture.cleanup_prefix_fixture(harness, 'owned-marker', original)
        self.assertEqual(['command', 'command', 'disable alias'], harness.calls)
        self.assertEqual('original browser failure', str(original))
        self.assertEqual(['VDEF prefix cleanup failed: RuntimeError, RuntimeError'], original.__notes__)

    def test_cleanup_failure_fails_an_otherwise_successful_fixture(self):
        harness = self.Harness()
        with self.assertRaisesRegex(RuntimeError, '^VDEF prefix cleanup failed: RuntimeError, RuntimeError$'):
            fixture.cleanup_prefix_fixture(harness, 'owned-marker', None)
        self.assertEqual(['command', 'command', 'disable alias'], harness.calls)

    def test_unconfirmed_marker_is_not_unlinked(self):
        harness = self.Harness()
        original = RuntimeError('exclusive marker creation failed')
        fixture.cleanup_prefix_fixture(harness, None, original)
        self.assertEqual(['command', 'disable alias'], harness.calls)


if __name__ == '__main__':
    unittest.main(verbosity=2)
