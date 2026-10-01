# SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
# SPDX-License-Identifier: GPL-3.0-or-later

"""Verify the production source selector under the real installation CSP."""

import json
import subprocess
import urllib.request
from pathlib import Path


def verify_source_selector(harness, scenario, source_id, check):
    cookies = [{'name': cookie.name, 'value': cookie.value, 'url': harness.base + '/'}
               for handler in scenario.client.handlers if isinstance(handler, urllib.request.HTTPCookieProcessor)
               for cookie in handler.cookiejar]

    def verify(front):
        result = subprocess.run(
            ['mise', 'exec', 'node@22.22.2', '--', 'node', str(Path(__file__).with_name('aggregate_template_browser_probe.cjs'))],
            input=json.dumps({'base': harness.base, 'sourceId': source_id, 'front': front, 'cookies': cookies}),
            capture_output=True, text=True, timeout=90,
        )
        if result.returncode != 0:
            raise RuntimeError(f'Aggregate browser fixture failed for {front}: {result.stderr}')
        check(json.loads(result.stdout) == {
            'csp_selector_executed': True, 'source_items_loaded': True, 'stack_default_selected': True,
            'script_measured': True, 'no_control_branch_measured': True,
        }, 'aggregate browser CSP selector and source items verified: ' + front)

    verify('/app.php')
    verify('/public/index.php')
    harness.command('php', '-r', 'if (!copy("include/config.php", "/tmp/aggregate-prefix-config.php")) { throw new RuntimeException("Cannot back up prefix configuration."); }', check=True)
    try:
        harness.command('php', '-r', 'if (file_put_contents("include/config.php", PHP_EOL . chr(36) . "url_path = " . var_export("/cacti/", true) . ";" . PHP_EOL, FILE_APPEND) === false) { throw new RuntimeException("Cannot write prefix configuration."); }', check=True)
        harness.compose('exec', '-T', 'web', 'sh', '-ec', "printf 'Alias /cacti/ /var/www/html/\\n' > /etc/apache2/conf-available/aggregate-prefix.conf; a2enconf aggregate-prefix; apachectl -k graceful", check=True)
        verify('/cacti/app.php')
        verify('/cacti/public/index.php')
    finally:
        harness.command('php', '-r', 'if (!copy("/tmp/aggregate-prefix-config.php", "include/config.php") || !unlink("/tmp/aggregate-prefix-config.php")) { throw new RuntimeException("Cannot restore prefix configuration."); }', check=True)
        harness.compose('exec', '-T', 'web', 'sh', '-ec', 'a2disconf aggregate-prefix; apachectl -k graceful', check=True)
