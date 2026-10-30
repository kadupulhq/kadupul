# SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
# SPDX-License-Identifier: GPL-3.0-or-later

"""Run the plugin console menu checks in an isolated stack."""
from pathlib import Path
from types import SimpleNamespace
import sys
sys.path.insert(0, str(Path(__file__).resolve().parents[1] / 'Support/Behavior'))
from harness import Harness
from plugin_menu_scenarios import verify_plugin_menu


def main():
    harness = Harness(SimpleNamespace(project='kadupul-plugin-menu', target='plugin-menu'))
    checks = []

    def check(condition, message):
        if not condition:
            raise AssertionError(message)
        checks.append(message)
        print('PASS ' + message, flush=True)

    try:
        harness.setup()
        verify_plugin_menu(harness, check)
        print(f'Plugin menu HTTP integration passed: {len(checks)} checks.', flush=True)
    finally:
        if harness.setup_started:
            harness.compose('down', '--volumes', '--remove-orphans', timeout=120)
        harness.lock.close()


if __name__ == '__main__':
    main()
