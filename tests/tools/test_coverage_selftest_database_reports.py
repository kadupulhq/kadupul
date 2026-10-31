# SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
# SPDX-License-Identifier: GPL-3.0-or-later

"""Negative coverage probes retain separate real database measurements."""
import ast
import copy
import hashlib
import importlib.util
import json
import os
from pathlib import Path
import subprocess
import sys
import tempfile
import unittest

ROOT = Path(__file__).resolve().parents[2]
SPEC = importlib.util.spec_from_file_location('coverage_selftest', ROOT / 'tests/Symfony/coverage_selftest.py')
SELFTEST = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(SELFTEST)
SOURCE = '/var/www/html/src/IdentityAccess/Infrastructure/Legacy/AuthenticationDatabaseSessionHandler.php'
OBSERVER = '/var/www/html/lib/api_device.php'
OTHER_IMPORTED = '/var/www/html/src/IdentityAccess/Infrastructure/Legacy/SharedSession.php'


class HttpProducerSourceRegistrationTest(unittest.TestCase):
    def setUp(self):
        spec = importlib.util.spec_from_file_location('coverage_support', ROOT / 'tests/Symfony/coverage_support.py')
        self.support = importlib.util.module_from_spec(spec)
        spec.loader.exec_module(self.support)

    def guard(self, operation, manifest=None):
        return subprocess.run(
            [os.environ.get('PHP', 'php'), '-d', 'display_errors=stderr',
             str(ROOT / 'tests/Fixtures/http-coverage-source-guard.php'), operation],
            input=json.dumps(manifest) if manifest is not None else '',
            capture_output=True, text=True, timeout=30,
        )

    def test_required_workers_are_importable_without_a_second_manual_allowlist(self):
        required = ['bin/legacy-tree-save.php', 'cli/add_graphs.php',
                    'bin/legacy-graph-add.php', 'bin/future-required-worker.php']
        excluded = ['bin/unregistered-worker.php', 'include/config.php',
                    'include/vendor/autoload.php', 'var/cache/generated.php']
        result = self.guard('required-import-paths', {'required': required, 'paths': required + excluded})
        self.assertEqual(0, result.returncode, result.stderr)
        self.assertEqual(required, json.loads(result.stdout))

    def test_measured_graph_cli_sources_survive_the_production_import_guard(self):
        admitted = [
            'cli/poller_graphs_reapply_names.php', 'bin/legacy-graph-titles.php',
            'cli/poller_data_sources_reapply_names.php', 'bin/legacy-data-source-titles.php',
            'cli/reorder_data_query.php', 'bin/legacy-data-query-reorder.php',
            'cli/repair_graphs.php', 'cli/remove_broken_graphs.php',
            'bin/legacy-graph-remove.php', 'cli/remove_graphs.php',
            'cli/add_graph_template.php', 'bin/legacy-graph-template-attach.php',
            'cli/add_data_query.php', 'bin/legacy-data-query-associate.php',
            'cli/add_datasource.php', 'bin/legacy-data-source-add.php',
            'cli/poller_output_empty.php', 'bin/legacy-poller-output-empty.php',
            'cli/analyze_database.php', 'src/Graphing/Domain/GraphRemovalFilter.php',
            'graphs.php',
        ]
        excluded = [
            'include/vendor/autoload.php', 'include/config.php',
            'var/cache/prod/App_Kernel.php', 'plugins/example/setup.php',
            'tests/Fixtures/legacy-cli/remove_graphs.php',
        ]
        result = self.guard('import-paths', admitted + excluded)
        self.assertEqual(0, result.returncode, result.stderr)
        self.assertEqual(admitted, json.loads(result.stdout))

    def test_every_required_native_cli_source_survives_the_actual_import_guard(self):
        # Independent maintained CLI input inventory from the coverage prerequisite.
        # Current main keeps its self-test baseline inline, so this regression must
        # not depend on the older branch's additional_required_native_sources API.
        required = [path for path in (
            'cli/poller_output_empty.php',
            'bin/legacy-poller-output-empty.php',
            'bin/legacy-data-query-associate.php',
            'bin/legacy-data-query-reorder.php',
            'bin/legacy-data-source-add.php',
            'bin/legacy-data-source-titles.php',
            'bin/legacy-graph-add.php',
            'bin/legacy-graph-remove.php',
            'bin/legacy-graph-template-attach.php',
            'bin/legacy-graph-titles.php',
            'bin/legacy-item-permission.php',
            'bin/legacy-tree-save.php',
            'bin/legacy-user-copy.php',
            'cli/add_data_query.php',
            'cli/add_datasource.php',
            'cli/add_graph_template.php',
            'cli/add_graphs.php',
            'cli/add_perms.php',
            'cli/add_tree.php',
            'cli/copy_user.php',
            'cli/poller_data_sources_reapply_names.php',
            'cli/poller_graphs_reapply_names.php',
            'cli/remove_broken_graphs.php',
            'cli/remove_graphs.php',
            'cli/reorder_data_query.php',
            'cli/repair_graphs.php',
            'src/Graphing/Application/Command/AddDataSource.php',
            'src/Graphing/Application/Command/AddGraphs.php',
            'src/Graphing/Application/Command/AddTree.php',
            'src/Graphing/Application/Command/AddTreeNode.php',
            'src/Graphing/Application/Command/AssociateDataQuery.php',
            'src/Graphing/Application/Command/AttachGraphTemplate.php',
            'src/Graphing/Application/Command/GraphCommandAccess.php',
            'src/Graphing/Application/Command/ListAttachmentChoices.php',
            'src/Graphing/Application/Command/ListDataQueryChoices.php',
            'src/Graphing/Application/Command/ListRemovalChoices.php',
            'src/Graphing/Application/Command/ListTreeChoices.php',
            'src/Graphing/Application/Command/ReapplyDataSourceTitles.php',
            'src/Graphing/Application/Command/ReapplyGraphTitles.php',
            'src/Graphing/Application/Command/RemoveBrokenGraphs.php',
            'src/Graphing/Application/Command/RemoveGraphs.php',
            'src/Graphing/Application/Command/ReorderDataQueries.php',
            'src/Graphing/Application/Command/RepairGraphItems.php',
            'src/Graphing/Domain/DataQuerySelection.php',
            'src/Graphing/Domain/GraphRemovalFilter.php',
            'src/Graphing/Domain/GraphSelection.php',
            'src/Graphing/Domain/ReindexMethod.php',
            'src/Graphing/Infrastructure/Legacy/LegacyDataQueryAssociator.php',
            'src/Graphing/Infrastructure/Legacy/LegacyDataQueryIndexReorderer.php',
            'src/Graphing/Infrastructure/Legacy/LegacyDataSourceCreator.php',
            'src/Graphing/Infrastructure/Legacy/LegacyDataSourceTitleReapplier.php',
            'src/Graphing/Infrastructure/Legacy/LegacyGraphRemover.php',
            'src/Graphing/Infrastructure/Legacy/LegacyGraphTemplateAttacher.php',
            'src/Graphing/Infrastructure/Legacy/LegacyGraphTitleReapplier.php',
            'src/Graphing/Infrastructure/Legacy/LegacyGraphWriter.php',
            'src/Graphing/Infrastructure/Legacy/LegacyTreeWriter.php',
            'src/Graphing/Infrastructure/Persistence/DbalBrokenGraphCatalog.php',
            'src/Graphing/Infrastructure/Persistence/DbalDataQueryAssociationCatalog.php',
            'src/Graphing/Infrastructure/Persistence/DbalDataQueryIndexCatalog.php',
            'src/Graphing/Infrastructure/Persistence/DbalDataSourceCreationCatalog.php',
            'src/Graphing/Infrastructure/Persistence/DbalDataSourceTitleCatalog.php',
            'src/Graphing/Infrastructure/Persistence/DbalGraphCreationCatalog.php',
            'src/Graphing/Infrastructure/Persistence/DbalGraphItemRepairs.php',
            'src/Graphing/Infrastructure/Persistence/DbalGraphRemovalCatalog.php',
            'src/Graphing/Infrastructure/Persistence/DbalGraphTemplateAssignmentCatalog.php',
            'src/Graphing/Infrastructure/Persistence/DbalGraphTitleCatalog.php',
            'src/Graphing/Infrastructure/Persistence/DbalTreeCatalog.php',
            'src/Graphing/Infrastructure/Symfony/AddDataSourceCommand.php',
            'src/Graphing/Infrastructure/Symfony/AddDataSourceLegacyArguments.php',
            'src/Graphing/Infrastructure/Symfony/AddGraphsCommand.php',
            'src/Graphing/Infrastructure/Symfony/AddGraphsLegacyArguments.php',
            'src/Graphing/Infrastructure/Symfony/AddTreeCommand.php',
            'src/Graphing/Infrastructure/Symfony/AddTreeLegacyArguments.php',
            'src/Graphing/Infrastructure/Symfony/AssociateDataQueryCommand.php',
            'src/Graphing/Infrastructure/Symfony/AssociateDataQueryLegacyArguments.php',
            'src/Graphing/Infrastructure/Symfony/AttachGraphTemplateCommand.php',
            'src/Graphing/Infrastructure/Symfony/AttachGraphTemplateLegacyArguments.php',
            'src/Graphing/Infrastructure/Symfony/ReapplyDataSourceTitlesCommand.php',
            'src/Graphing/Infrastructure/Symfony/ReapplyDataSourceTitlesLegacyArguments.php',
            'src/Graphing/Infrastructure/Symfony/ReapplyGraphTitlesCommand.php',
            'src/Graphing/Infrastructure/Symfony/ReapplyGraphTitlesLegacyArguments.php',
            'src/Graphing/Infrastructure/Symfony/RemoveBrokenGraphsCommand.php',
            'src/Graphing/Infrastructure/Symfony/RemoveBrokenGraphsLegacyArguments.php',
            'src/Graphing/Infrastructure/Symfony/RemoveGraphsCommand.php',
            'src/Graphing/Infrastructure/Symfony/RemoveGraphsLegacyArguments.php',
            'src/Graphing/Infrastructure/Symfony/ReorderDataQueriesCommand.php',
            'src/Graphing/Infrastructure/Symfony/ReorderDataQueriesLegacyArguments.php',
            'src/Graphing/Infrastructure/Symfony/RepairGraphItemsCommand.php',
            'src/Graphing/Infrastructure/Symfony/RepairGraphItemsLegacyArguments.php',
            'src/IdentityAccess/Application/Command/CopyUser.php',
            'src/IdentityAccess/Application/Command/GrantItemPermission.php',
            'src/IdentityAccess/Application/Command/ListPermissionTargets.php',
            'src/IdentityAccess/Application/Command/UserCommandAccess.php',
            'src/IdentityAccess/Domain/ItemPermissionRequest.php',
            'src/IdentityAccess/Infrastructure/Legacy/LegacyItemPermissions.php',
            'src/IdentityAccess/Infrastructure/Legacy/LegacyUserCopier.php',
            'src/IdentityAccess/Infrastructure/Persistence/DbalPermissionCatalog.php',
            'src/IdentityAccess/Infrastructure/Symfony/AddPermissionCommand.php',
            'src/IdentityAccess/Infrastructure/Symfony/AddPermsLegacyArguments.php',
            'src/IdentityAccess/Infrastructure/Symfony/CopyUserCommand.php',
            'src/IdentityAccess/Infrastructure/Symfony/CopyUserLegacyArguments.php',
            'src/IdentityAccess/Infrastructure/Symfony/ListPermissionsCommand.php',
        ) if (ROOT / path).is_file()]
        self.assertEqual(102, len(required))
        result = self.guard('import-paths', required)
        self.assertEqual(0, result.returncode, result.stderr)
        self.assertEqual(required, json.loads(result.stdout))

    def test_actual_producer_and_merger_require_the_same_complete_source_inventory(self):
        result = self.guard('paths')
        self.assertEqual(0, result.returncode, result.stderr)
        expected = json.loads(result.stdout)
        self.assertGreater(len(expected), 50)
        self.assertEqual(self.support.http_coverage_sources(ROOT), expected)
        self.assertIn('tests/Symfony/http_coverage_sources.json', expected)
        for database_sessions in (False, True):
            with self.subTest(database_sessions=database_sessions), tempfile.TemporaryDirectory() as temporary:
                output = Path(temporary)
                (output / 'raw').mkdir()
                (output / 'raw/coverage-probe.json').write_text('{}')
                self.support.publish_coverage(output, database_sessions, ['retained check'])
                manifest = json.loads((output / 'observations.json').read_text())
                self.assertEqual('database' if database_sessions else 'files', manifest['session_handler'])
                self.assertEqual(['retained check'], manifest['checks'])
                self.assertEqual(set(expected), set(manifest['source_sha256']))
                for source in expected:
                    self.assertEqual(hashlib.sha256((ROOT / source).read_bytes()).hexdigest(), manifest['source_sha256'][source])
                accepted = self.guard('verify', manifest)
                self.assertEqual(0, accepted.returncode, accepted.stderr)
                self.assertEqual('Verified HTTP source identities', accepted.stdout)
                for source in expected:
                    for mutation in ('missing', 'stale'):
                        with self.subTest(source=source, mutation=mutation):
                            changed = copy.deepcopy(manifest)
                            if mutation == 'missing':
                                del changed['source_sha256'][source]
                            else:
                                changed['source_sha256'][source] = '0' * 64
                            refused = self.guard('verify', changed)
                            self.assertNotEqual(0, refused.returncode)
                            self.assertIn('Integration test source differs from checkout: ' + source, refused.stderr)

    def test_imported_local_scenarios_and_their_frozen_cli_oracles_are_registered(self):
        paths = set(self.support.http_coverage_sources(ROOT))
        for path in sorted(paths):
            if not path.startswith('tests/Symfony/') or not path.endswith('.py'):
                continue
            for node in ast.walk(ast.parse((ROOT / path).read_text())):
                names = [node.module] if isinstance(node, ast.ImportFrom) and node.module else [item.name for item in node.names] if isinstance(node, ast.Import) else []
                for name in names:
                    dependency = 'tests/Symfony/' + name.split('.')[0] + '.py'
                    if (ROOT / dependency).is_file():
                        self.assertIn(dependency, paths, (path, dependency))
                if isinstance(node, ast.Constant) and isinstance(node.value, str) and node.value.endswith('.php'):
                    oracle = 'tests/Fixtures/legacy-cli/' + Path(node.value).name
                    if (ROOT / oracle).is_file():
                        self.assertIn(oracle, paths, (path, oracle))


class DatabaseReportPreservationTest(unittest.TestCase):
    def setUp(self):
        self.temporary = tempfile.TemporaryDirectory()
        self.addCleanup(self.temporary.cleanup)
        self.directory = Path(self.temporary.name) / 'database'
        self.scratch = Path(self.temporary.name) / 'scratch'
        (self.directory / 'raw').mkdir(parents=True)
        (self.scratch / 'raw').mkdir(parents=True)
        self.reports = {
            'coverage-first.json': {'php': '8.4.25', 'files': {
                SOURCE: {'sha256': 'a' * 64, 'lines': {'10': 0, '11': -1}},
                OBSERVER: {'sha256': 'b' * 64, 'lines': {'20': 1}},
                OTHER_IMPORTED: {'sha256': 'c' * 64, 'lines': {'30': 1}},
            }},
            'coverage-second.json': {'php': '8.4.25', 'files': {
                SOURCE: {'sha256': 'a' * 64, 'lines': {'10': 1, '11': 0}},
                OBSERVER: {'sha256': 'd' * 64, 'lines': {'20': 1}},
                OTHER_IMPORTED: {'sha256': 'e' * 64, 'lines': {'30': 1}},
            }},
            'coverage-without-target.json': {'php': '8.4.25', 'files': None},
        }
        self.write_reports()

    def write_reports(self):
        for name, report in self.reports.items():
            (self.directory / 'raw' / name).write_text(json.dumps(report, indent=2) + '\n')

    def test_dynamic_helper_import_has_no_cli_scenario_dependency(self):
        code = '''import importlib.util, sys
original_path = list(sys.path)
spec = importlib.util.spec_from_file_location('coverage_selftest', sys.argv[1])
module = importlib.util.module_from_spec(spec)
spec.loader.exec_module(module)
assert sys.path == original_path
assert 'cdef_legacy_page_scenarios' not in sys.modules
assert callable(module.prepare_database_failure_reports)
'''
        result = subprocess.run(
            [sys.executable, '-c', code, str(ROOT / 'tests/Symfony/coverage_selftest.py')],
            cwd=self.temporary.name, capture_output=True, text=True, timeout=30,
        )
        self.assertEqual(0, result.returncode, result.stderr)

    def test_each_mutation_preserves_all_reports_and_unrelated_hash_variants(self):
        original = {path.name: path.read_bytes() for path in (self.directory / 'raw').iterdir()}
        for mutation in ('unmeasured', 'stale'):
            with self.subTest(mutation=mutation):
                SELFTEST.prepare_database_failure_reports(self.directory, self.scratch, SOURCE, mutation)
                self.assertEqual(set(self.reports), {path.name for path in (self.scratch / 'raw').iterdir()})
                for name, report in self.reports.items():
                    expected = copy.deepcopy(report)
                    observation = (expected['files'] or {}).get(SOURCE)
                    if observation is not None:
                        if mutation == 'unmeasured':
                            observation['lines'] = {line: -1 for line in observation['lines']}
                        else:
                            observation['sha256'] = '0' * 64
                    destination = self.scratch / 'raw' / name
                    self.assertEqual(expected, json.loads(destination.read_text()))
                    if observation is None:
                        self.assertEqual(original[name], destination.read_bytes())
                    self.assertEqual(original[name], (self.directory / 'raw' / name).read_bytes())

    def test_missing_target_refuses_before_copying_reports(self):
        with self.assertRaisesRegex(RuntimeError, 'requires real required-source measurements'):
            SELFTEST.prepare_database_failure_reports(self.directory, self.scratch, SOURCE + '.missing', 'stale')
        self.assertEqual([], list((self.scratch / 'raw').iterdir()))

    def test_target_without_positive_execution_refuses_before_copying_reports(self):
        self.reports['coverage-second.json']['files'][SOURCE]['lines'] = {'10': 0, '11': -1}
        self.write_reports()
        with self.assertRaisesRegex(RuntimeError, 'requires real required-source measurements'):
            SELFTEST.prepare_database_failure_reports(self.directory, self.scratch, SOURCE, 'unmeasured')
        self.assertEqual([], list((self.scratch / 'raw').iterdir()))

    def test_empty_measurements_refuse(self):
        for path in (self.directory / 'raw').iterdir():
            path.unlink()
        with self.assertRaisesRegex(RuntimeError, 'requires real required-source measurements'):
            SELFTEST.prepare_database_failure_reports(self.directory, self.scratch, SOURCE, 'stale')

    def test_unknown_mutation_refuses_before_copying_reports(self):
        with self.assertRaisesRegex(ValueError, 'Unknown database measurement mutation'):
            SELFTEST.prepare_database_failure_reports(self.directory, self.scratch, SOURCE, 'unknown')
        self.assertEqual([], list((self.scratch / 'raw').iterdir()))

    def test_changed_input_set_rejects_stale_scratch_reports_before_writes(self):
        SELFTEST.prepare_database_failure_reports(self.directory, self.scratch, SOURCE, 'unmeasured')
        previous = {path.name: path.read_bytes() for path in (self.scratch / 'raw').iterdir()}
        (self.directory / 'raw' / 'coverage-without-target.json').unlink()
        with self.assertRaisesRegex(RuntimeError, 'Unexpected scratch coverage reports'):
            SELFTEST.prepare_database_failure_reports(self.directory, self.scratch, SOURCE, 'stale')
        self.assertEqual(previous, {path.name: path.read_bytes() for path in (self.scratch / 'raw').iterdir()})

    def test_scratch_symlink_cannot_redirect_a_target_report_write(self):
        destination = self.scratch / 'raw' / 'coverage-first.json'
        original = self.directory / 'raw' / 'coverage-first.json'
        original_bytes = original.read_bytes()
        destination.symlink_to(original)
        with self.assertRaisesRegex(RuntimeError, 'Unexpected scratch coverage reports'):
            SELFTEST.prepare_database_failure_reports(self.directory, self.scratch, SOURCE, 'stale')
        self.assertEqual(original_bytes, original.read_bytes())


if __name__ == '__main__':
    unittest.main()
