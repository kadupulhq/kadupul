# SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
# SPDX-License-Identifier: GPL-3.0-or-later
"""Keep the shared authentication shards a complete, ordered partition."""
import sys
import unittest
from pathlib import Path
from types import SimpleNamespace

sys.path.insert(0, str(Path(__file__).resolve().parents[1] / 'Symfony'))
import session_bridge
from session_bridge import LASTING_STATE, SHARDS, STEPS, selected_steps, shard_errors


class ShardPartitionTest(unittest.TestCase):
    def test_shards_cover_every_step_once(self):
        self.assertEqual([], shard_errors())
        names = [step.__name__ for step in STEPS]
        selected = [step.__name__ for number in range(1, len(SHARDS) + 1)
                    for step in selected_steps(f'{number}/{len(SHARDS)}')]
        self.assertCountEqual(names, selected)
        self.assertEqual(len(names), len(set(names)))

    def test_default_runs_every_step_in_order(self):
        self.assertEqual(STEPS, selected_steps(None))

    def test_account_deletion_stays_last_in_its_shard(self):
        for shard in SHARDS:
            if 'account_lifecycle' in shard:
                self.assertEqual('account_lifecycle', shard[-1])

    def test_unassigned_step_is_reported(self):
        shards = (SHARDS[0][1:],) + SHARDS[1:]
        self.assertIn(f'step {SHARDS[0][0]} is assigned to 0 shards', shard_errors(shards=shards))

    def test_duplicated_step_is_reported(self):
        shards = SHARDS[:-1] + (SHARDS[-1] + (SHARDS[0][0],),)
        errors = shard_errors(shards=shards)
        self.assertIn(f'step {SHARDS[0][0]} is assigned to 2 shards', errors)

    def test_unknown_and_reordered_steps_are_reported(self):
        shards = (tuple(reversed(SHARDS[0])) + ('missing_step',),) + SHARDS[1:]
        errors = shard_errors(shards=shards)
        self.assertIn('unknown step in a shard: missing_step', errors)
        self.assertIn('shard 1 changes the step order', errors)

    def test_lasting_state_names_real_steps(self):
        names = {step.__name__ for step in STEPS}
        self.assertLessEqual(set(LASTING_STATE), names)
        self.assertIn("('guest_user','0')", LASTING_STATE['login_policy'])
        for statements in LASTING_STATE.values():
            self.assertNotIn('{', statements.format(user_id=1))
        original = dict(LASTING_STATE)
        LASTING_STATE['missing_step'] = 'SELECT 1'
        try:
            self.assertIn('lasting state names an unknown step: missing_step', shard_errors())
        finally:
            LASTING_STATE.clear()
            LASTING_STATE.update(original)

    def test_skipped_steps_are_mirrored_in_order(self):
        statements = []
        fixture = session_bridge.Fixture(SimpleNamespace(sql=statements.append), False)
        fixture._user_id = 1
        for step in STEPS:
            if step.__name__ not in SHARDS[1]:
                fixture.mirror(step)
        joined = '\n'.join(statements)
        self.assertLess(joined.index("('guest_user','0')"), joined.index("('i18n_force_language','fr')"))
        self.assertNotIn('create-test-community', joined)

    def test_broken_partition_refuses_to_run(self):
        original = session_bridge.SHARDS
        session_bridge.SHARDS = original[:-1]
        try:
            with self.assertRaises(SystemExit):
                session_bridge.selected_steps(None)
        finally:
            session_bridge.SHARDS = original

    def test_offline_bundle_runs_every_shard(self):
        workflow = (Path(__file__).resolve().parents[2] / '.github/workflows/offline-bundle.yml').read_text()
        count = len(SHARDS)
        self.assertIn('        shard: [' + ', '.join(str(number) for number in range(1, count + 1)) + ']\n', workflow)
        self.assertIn(f'AUTH_SHARD: ${{{{ matrix.shard }}}}/{count}\n', workflow)
        self.assertEqual(2, workflow.count('--shard "$AUTH_SHARD"'))

    def test_malformed_selectors_are_refused(self):
        count = len(SHARDS)
        for selector in ('', '0/' + str(count), f'{count + 1}/{count}', f'1/{count + 1}', '1', 'a/b', f' 1/{count}'):
            with self.subTest(selector=selector), self.assertRaises(SystemExit):
                selected_steps(selector)


if __name__ == '__main__':
    unittest.main()
