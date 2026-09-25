"""Reject stale, incomplete or malformed measurements before publishing Clover."""
import argparse
import copy
import json
from pathlib import Path
import subprocess
import tempfile

ROOT = Path(__file__).resolve().parents[2]


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--php', default='php')
    parser.add_argument('--unit', type=Path, required=True)
    parser.add_argument('--files', type=Path, required=True)
    parser.add_argument('--database', type=Path, required=True)
    parser.add_argument('--offline', type=Path, required=True)
    args = parser.parse_args()
    manifest = json.loads((args.files / 'observations.json').read_text())
    measured = {'php': '8.2', 'files': {}}
    prefix = '/var/www/html/'
    required = [prefix + path for path in (
        'bin/legacy-device-edit.php', 'src/IdentityAccess/Infrastructure/Legacy/SharedSession.php',
        'src/Inventory/Infrastructure/Symfony/Controller/DeviceEditController.php',
        'src/Inventory/Infrastructure/Symfony/Controller/SiteListController.php',
        'src/Inventory/Infrastructure/Symfony/Controller/SiteEditController.php',
        'src/Inventory/Infrastructure/Legacy/LegacySiteEditor.php',
        'src/IdentityAccess/Infrastructure/Legacy/LegacyLocalePreference.php',
        'src/Inventory/Infrastructure/Symfony/Controller/SiteCreateController.php',
        'src/Inventory/Domain/DevicePolling.php',
        'src/Inventory/Domain/DeviceTemplateAssignment.php',
        'src/Inventory/Domain/DeviceCollectorAssignment.php',
        'src/Inventory/Infrastructure/Symfony/DeviceFormFailure.php',
        'src/Inventory/Infrastructure/Symfony/DeviceFormPage.php',
        'src/Inventory/Infrastructure/Symfony/DeviceAssignmentForm.php',
        'src/Inventory/Infrastructure/Legacy/DeviceAssignmentLock.php',
        'src/Inventory/Infrastructure/Legacy/DeviceAssignmentProcess.php',
        'src/Inventory/Application/Command/AssignDeviceTemplate.php',
        'src/Inventory/Application/Command/AssignDeviceCollector.php',
        'src/Inventory/Application/Query/PrepareDeviceTemplateAssignment.php',
        'src/Inventory/Application/Query/PrepareDeviceCollectorAssignment.php',
        'src/Inventory/Infrastructure/Legacy/LegacyDeviceTemplateAssignments.php',
        'src/Inventory/Infrastructure/Legacy/LegacyDeviceCollectorAssignments.php',
        'src/Inventory/Infrastructure/Legacy/DeviceWriteAuthorization.php',
        'src/Inventory/Infrastructure/Legacy/DeviceMutationSelection.php',
        'src/Inventory/Infrastructure/Legacy/DeviceCollectorReplication.php',
        'src/Inventory/Infrastructure/Symfony/Form/DeviceTemplateType.php',
        'src/Inventory/Infrastructure/Symfony/Form/DeviceCollectorType.php',
        'src/Inventory/Infrastructure/Symfony/Controller/DeviceTemplateController.php',
        'src/Inventory/Infrastructure/Symfony/Controller/DeviceCollectorController.php',
        'bin/legacy-device-template.php',
        'bin/legacy-device-collector.php', 'bin/legacy-assignment-bootstrap.php',
        'bin/legacy-device-state.php',
        'bin/legacy-device-remove.php',
        'src/Inventory/Domain/DeviceRemoval.php',
        'src/Inventory/Application/Command/RemoveDevices.php',
        'src/Inventory/Application/Query/PrepareDeviceRemoval.php',
        'src/Inventory/Infrastructure/Legacy/LegacyDeviceRemovals.php',
        'src/Inventory/Infrastructure/Legacy/DeviceRemovalSnapshot.php',
        'src/Inventory/Infrastructure/Legacy/DeviceRemovalDependencies.php',
        'src/Inventory/Infrastructure/Symfony/DeviceSelectionForm.php',
        'src/Inventory/Infrastructure/Legacy/DeviceRemovalDependencyReceipt.php',
        'src/Inventory/Infrastructure/Symfony/Form/DeviceRemovalType.php',
        'src/Inventory/Infrastructure/Symfony/Controller/DeviceRemovalController.php',
        'src/Inventory/Domain/DeviceState.php',
        'src/Inventory/Domain/DeviceSelection.php',
        'src/Inventory/Application/Command/SetDevicesEnabled.php',
        'src/Inventory/Application/Query/PrepareDeviceStateChange.php',
        'src/Inventory/Infrastructure/Legacy/LegacyDeviceStates.php',
        'src/Inventory/Infrastructure/Symfony/Form/DeviceStateType.php',
        'src/Inventory/Infrastructure/Symfony/Controller/DeviceStateController.php',
        'src/Inventory/Domain/DeviceSnmpConfiguration.php',
        'src/Inventory/Domain/DeviceSnmpChange.php',
        'src/Inventory/Infrastructure/Symfony/Form/DeviceSnmpType.php',
        'src/Inventory/Infrastructure/Symfony/Form/DevicePollingType.php',
        'src/Inventory/Application/Query/ListAssignableSites.php',
        'src/Platform/Infrastructure/Doctrine/InstallationConnectionMiddleware.php',
        'src/Platform/Infrastructure/Doctrine/InstallationConnectionDriver.php',
        'src/Inventory/Infrastructure/Persistence/DoctrineSiteAssignmentCatalog.php',
        'src/Inventory/Application/Command/CreateSite.php',
        'src/Inventory/Domain/NewSite.php',
        'src/Inventory/Infrastructure/Legacy/LegacySiteCreator.php',
        'src/Platform/Infrastructure/Legacy/CollectorSiteDatabase.php',
        'sites.php',
        'lib/database.php',
        'src/Inventory/Infrastructure/Symfony/Controller/LegacySitesController.php',
        'src/Inventory/Infrastructure/Symfony/Controller/SiteActionController.php',
        'src/Inventory/Infrastructure/Legacy/LegacySiteLifecycle.php',
        'src/Inventory/Application/Command/DeleteSites.php',
        'src/Inventory/Application/Command/DuplicateSites.php',
        'src/Inventory/Application/Query/PrepareSiteAction.php',
        'src/Inventory/Domain/SiteSelection.php',
        'bin/legacy-device-create.php',
        'src/Inventory/Infrastructure/Symfony/Controller/DeviceCreateController.php',
        'src/Inventory/Application/Command/CreateDevice.php',
        'src/Inventory/Application/Query/PrepareDeviceCreation.php',
        'src/Inventory/Domain/NewDevice.php',
        'src/Inventory/Infrastructure/Persistence/DoctrineDeviceCreationCatalog.php',
        'src/Inventory/Infrastructure/Legacy/LegacyDeviceCreator.php',
        'src/Platform/Infrastructure/Symfony/InventoryLocaleSubscriber.php',
        'script_server.php',
        'include/themes/midwinter/update_hash.php',
        'cli/analyze_database.php',
        'src/Platform/Infrastructure/Symfony/Console/LegacyCli.php',
        'src/Platform/Infrastructure/Symfony/Console/LegacyArguments.php',
        'src/Platform/Infrastructure/Symfony/Console/AnalyzeDatabaseLegacyArguments.php',
        'src/Platform/Infrastructure/Symfony/Console/CliPresentation.php',
        'src/Platform/Infrastructure/Symfony/Console/AnalyzeDatabaseCommand.php',
        'src/Platform/Infrastructure/Symfony/Console/ResultRenderer.php',
        'src/Platform/Application/Command/AnalyzeDatabase.php',
        'src/IdentityAccess/Infrastructure/Cli/CliConsoleAccess.php',
        'src/Platform/Infrastructure/Persistence/DbalDatabaseMaintenance.php',
        'src/Platform/Infrastructure/Legacy/InstallationVersion.php',
        'src/Platform/Infrastructure/Legacy/LegacyOperatorLog.php',
        'cli/convert_tables.php',
        'src/Platform/Application/Command/ConvertTables.php',
        'src/Platform/Application/Command/MaintenanceRealm.php',
        'src/Platform/Application/Command/MaintenanceScope.php',
        'src/Platform/Application/Command/MaintenanceTarget.php',
        'src/Platform/Application/Command/SchemaChangeAudit.php',
        'src/Platform/Application/Command/TableConversionStep.php',
        'src/Platform/Application/Port/TableCatalog.php',
        'src/Platform/Application/Port/TableConversion.php',
        'src/Platform/Application/ReadModel/ConversionOutcome.php',
        'src/Platform/Application/ReadModel/ConversionReport.php',
        'src/Platform/Application/ReadModel/TableOutcome.php',
        'src/Platform/Application/ReadModel/TableResult.php',
        'src/Platform/Domain/Schema/ConversionFlag.php',
        'src/Platform/Domain/Schema/ConversionOptions.php',
        'src/Platform/Domain/Schema/ConversionProblem.php',
        'src/Platform/Domain/Schema/InvalidConversionOptions.php',
        'src/Platform/Domain/Schema/TableChange.php',
        'src/Platform/Domain/Schema/TableCharset.php',
        'src/Platform/Domain/Schema/TableSkip.php',
        'src/Platform/Domain/Schema/TableStatus.php',
        'src/Platform/Infrastructure/Legacy/InstallerTableConversion.php',
        'src/Platform/Infrastructure/Legacy/InstallerTableResult.php',
        'src/Platform/Infrastructure/Persistence/DbalTableConversion.php',
        'src/Platform/Infrastructure/Persistence/MaintenanceConnections.php',
        'src/Platform/Infrastructure/Symfony/Console/ConvertTablesCommand.php',
        'src/Platform/Infrastructure/Symfony/Console/ConvertTablesInput.php',
        'src/Platform/Infrastructure/Symfony/Console/ConvertTablesLegacyArguments.php',
        'cli/fix_mediumint.php',
        'src/Platform/Application/Command/WidenIdColumns.php',
        'src/Platform/Application/Port/ColumnCatalog.php',
        'src/Platform/Application/Port/ColumnWidening.php',
        'src/Platform/Application/ReadModel/WideningEvent.php',
        'src/Platform/Application/ReadModel/WideningReport.php',
        'src/Platform/Domain/Schema/ColumnChange.php',
        'src/Platform/Domain/Schema/ColumnDefinition.php',
        'src/Platform/Domain/Schema/IdColumns.php',
        'src/Platform/Domain/Schema/IdColumnPlan.php',
        'src/Platform/Infrastructure/Persistence/DbalColumnWidening.php',
        'src/Platform/Infrastructure/Symfony/Console/WidenIdColumnsCommand.php',
        'src/Platform/Infrastructure/Symfony/Console/WidenIdColumnsInput.php',
        'src/Platform/Infrastructure/Symfony/Console/WidenIdColumnsLegacyArguments.php')]
    for path in (args.files / 'raw').glob('coverage-*.json'):
        report = json.loads(path.read_text())
        for source in required:
            if 1 in (report['files'] or {}).get(source, {}).get('lines', {}).values():
                measured['files'][source] = report['files'][source]
    if set(measured['files']) != set(required):
        raise RuntimeError('Self-test requires real HTTP and worker measurements')
    failures = {
        'source-hash': 'Covered source differs',
        'test-hash': 'Integration test source differs',
        'details-test-hash': 'Integration test source differs',
        'sites-test-hash': 'Integration test source differs',
        'site-edit-test-hash': 'Integration test source differs',
        'missing-check': 'Incomplete Symfony integration',
        'missing-removal-callback-check': 'Incomplete Symfony integration',
        'missing-removal-shared-check': 'Incomplete Symfony integration',
        'missing-removal-rollback-check': 'Incomplete Symfony integration',
        'wrong-handler': 'Wrong integration suite',
        'missing-reports': 'Missing integration coverage',
        'invalid-hit': 'Invalid PCOV',
        'invalid-line': 'Invalid PCOV',
        'unmeasured-worker': 'Missing measured execution',
        'unmeasured-site-editor': 'Missing measured execution: src/Inventory/Infrastructure/Symfony/Controller/SiteEditController.php',
        'unmeasured-locale': 'Missing measured execution: src/Platform/Infrastructure/Symfony/InventoryLocaleSubscriber.php',
        'missing-site-creation-check': 'Incomplete Symfony integration',
        'site-create-test-hash': 'Integration test source differs',
        'site-lifecycle-test-hash': 'Integration test source differs',
        'missing-site-lifecycle-check': 'Incomplete Symfony integration checks',
        'unmeasured-SiteCreateController.php': 'Missing measured execution: src/Inventory/Infrastructure/Symfony/Controller/SiteCreateController.php',
        'unmeasured-CreateSite.php': 'Missing measured execution: src/Inventory/Application/Command/CreateSite.php',
        'unmeasured-NewSite.php': 'Missing measured execution: src/Inventory/Domain/NewSite.php',
        'unmeasured-LegacySiteCreator.php': 'Missing measured execution: src/Inventory/Infrastructure/Legacy/LegacySiteCreator.php',
        'missing-device-creation-check': 'Incomplete Symfony integration',
        'device-create-test-hash': 'Integration test source differs',
        'creation-plugin-test-hash': 'Integration test source differs',
        'device-compatibility-test-hash': 'Integration test source differs',
        'unmeasured-legacy-device-create.php': 'Missing measured execution: bin/legacy-device-create.php',
        'unmeasured-DeviceCreateController.php': 'Missing measured execution: src/Inventory/Infrastructure/Symfony/Controller/DeviceCreateController.php',
        'unmeasured-CreateDevice.php': 'Missing measured execution: src/Inventory/Application/Command/CreateDevice.php',
        'unmeasured-PrepareDeviceCreation.php': 'Missing measured execution: src/Inventory/Application/Query/PrepareDeviceCreation.php',
        'unmeasured-NewDevice.php': 'Missing measured execution: src/Inventory/Domain/NewDevice.php',
        'unmeasured-DoctrineDeviceCreationCatalog.php': 'Missing measured execution: src/Inventory/Infrastructure/Persistence/DoctrineDeviceCreationCatalog.php',
        'unmeasured-LegacyDeviceCreator.php': 'Missing measured execution: src/Inventory/Infrastructure/Legacy/LegacyDeviceCreator.php',
        'path-traversal': 'Invalid integration source path',
        'script-server-test-hash': 'Integration test source differs',
        'missing-script-server-check': 'Incomplete Symfony integration checks',
        'cli-parity-test-hash': 'Integration test source differs',
        'cli-original-test-hash': 'Integration test source differs',
        'missing-cli-parity-check': 'Incomplete Symfony integration checks',
        'cli-schema-test-hash': 'Integration test source differs',
        'cli-convert-original-test-hash': 'Integration test source differs',
        'missing-cli-schema-check': 'Incomplete Symfony integration checks',
        'missing-installer-conversion-check': 'Incomplete Symfony integration checks',
        'cli-widen-original-test-hash': 'Integration test source differs',
        'missing-widen-check': 'Incomplete Symfony integration checks',
    }
    for source in required:
        failures.setdefault('unmeasured-' + source.rsplit('/', 1)[-1], 'Missing measured execution')
    with tempfile.TemporaryDirectory(prefix='symfony-coverage-negative-') as directory:
        scratch = Path(directory)
        (scratch / 'raw').mkdir()
        raw = scratch / 'raw/coverage-probe.json'
        output = scratch / 'result.xml'
        for case, expected in failures.items():
            data = copy.deepcopy(measured)
            evidence = copy.deepcopy(manifest)
            worker = data['files'][required[0]]
            if case == 'source-hash':
                worker['sha256'] = '0' * 64
            elif case == 'test-hash':
                evidence['source_sha256']['tests/Symfony/session_bridge.py'] = '0' * 64
            elif case == 'details-test-hash':
                evidence['source_sha256']['tests/Symfony/details_scenarios.py'] = '0' * 64
            elif case == 'sites-test-hash':
                evidence['source_sha256']['tests/Symfony/site_catalog_scenarios.py'] = '0' * 64
            elif case == 'site-edit-test-hash':
                evidence['source_sha256']['tests/Symfony/site_edit_scenarios.py'] = '0' * 64
            elif case == 'site-create-test-hash':
                evidence['source_sha256']['tests/Symfony/site_create_scenarios.py'] = '0' * 64
            elif case == 'site-lifecycle-test-hash':
                evidence['source_sha256']['tests/Symfony/site_lifecycle_scenarios.py'] = '0' * 64
            elif case == 'missing-site-lifecycle-check':
                evidence['checks'].remove('bulk site deletion succeeds atomically')
            elif case == 'missing-site-creation-check':
                evidence['checks'].remove('site creation persists name')
            elif case.startswith('unmeasured-') and case.removeprefix('unmeasured-').endswith('.php'):
                source = next(path for path in required if path.endswith('/' + case.removeprefix('unmeasured-')))
                data['files'][source]['lines'] = {line: -1 for line in data['files'][source]['lines']}
            elif case == 'device-create-test-hash':
                evidence['source_sha256']['tests/Symfony/device_create_scenarios.py'] = '0' * 64
            elif case == 'device-compatibility-test-hash':
                evidence['source_sha256']['tests/Symfony/device_creation_review_scenarios.py'] = '0' * 64
            elif case == 'creation-plugin-test-hash':
                evidence['source_sha256']['tests/Fixtures/plugins/compatibility_test/setup.php'] = '0' * 64
            elif case == 'script-server-test-hash':
                evidence['source_sha256']['tests/Symfony/script_server_scenarios.py'] = '0' * 64
            elif case == 'missing-script-server-check':
                evidence['checks'].remove('script server refuses includes outside the base path')
            elif case == 'cli-parity-test-hash':
                evidence['source_sha256']['tests/Symfony/cli_parity_scenarios.py'] = '0' * 64
            elif case == 'cli-original-test-hash':
                evidence['source_sha256']['tests/Fixtures/legacy-cli/analyze_database.php'] = '0' * 64
            elif case == 'missing-cli-parity-check':
                evidence['checks'].remove('analyze: shim analyzes every table through the kernel container')
            elif case == 'cli-schema-test-hash':
                evidence['source_sha256']['tests/Symfony/cli_schema_scenarios.py'] = '0' * 64
            elif case == 'cli-convert-original-test-hash':
                evidence['source_sha256']['tests/Fixtures/legacy-cli/convert_tables.php'] = '0' * 64
            elif case == 'missing-cli-schema-check':
                evidence['checks'].remove('convert tables refuses an operator without the Installation/Upgrades realm')
            elif case == 'missing-installer-conversion-check':
                evidence['checks'].remove('installer converts a queued MyISAM table to InnoDB and utf8mb4 in-process')
            elif case == 'cli-widen-original-test-hash':
                evidence['source_sha256']['tests/Fixtures/legacy-cli/fix_mediumint.php'] = '0' * 64
            elif case == 'missing-widen-check':
                evidence['checks'].remove('widen refuses an operator without the Installation/Upgrades realm')
            elif case == 'missing-device-creation-check':
                evidence['checks'].remove('legacy template graph associations are preserved')
            elif case == 'missing-removal-callback-check':
                evidence['checks'] = [check for check in evidence['checks'] if check != 'rejected removal emits no bulk action callback']
            elif case in ['missing-removal-shared-check', 'missing-removal-rollback-check']:
                omitted = 'remote removal rejects outside graph references before cleanup' if case == 'missing-removal-shared-check' else 'remote removal failure rolls back dependent cleanup'
                evidence['checks'] = [check for check in evidence['checks'] if check != omitted]
            elif case == 'missing-check':
                evidence['checks'] = []
            elif case == 'wrong-handler':
                evidence['session_handler'] = 'database'
            elif case == 'invalid-hit':
                worker['lines'][next(iter(worker['lines']))] = 2
            elif case == 'invalid-line':
                worker['lines']['0'] = 1
            elif case == 'unmeasured-worker':
                worker['lines'] = {line: -1 for line in worker['lines']}
            elif case == 'unmeasured-site-editor':
                editor = data['files'][prefix + 'src/Inventory/Infrastructure/Symfony/Controller/SiteEditController.php']
                editor['lines'] = {line: -1 for line in editor['lines']}
            elif case == 'unmeasured-locale':
                locale = data['files'][prefix + 'src/Platform/Infrastructure/Symfony/InventoryLocaleSubscriber.php']
                locale['lines'] = {line: -1 for line in locale['lines']}
            elif case == 'path-traversal':
                data['files'][prefix + 'src/../bin/legacy-device-edit.php'] = data['files'].pop(required[0])
            raw.write_text(json.dumps(data))
            if case == 'missing-reports':
                raw.unlink()
            (scratch / 'observations.json').write_text(json.dumps(evidence))
            output.write_text('previous report')
            result = subprocess.run([args.php, str(ROOT / 'tests/Symfony/merge_coverage.php'),
                                     str(args.unit.resolve()), str(scratch),
                                     str(args.database.resolve()), str(args.offline.resolve()), str(output)],
                                    capture_output=True, text=True, timeout=60)
            if result.returncode == 0 or expected not in result.stdout + result.stderr:
                raise RuntimeError(f'{case}: unexpected merge result: {result.stdout} {result.stderr}')
            if output.read_text() != 'previous report':
                raise RuntimeError(f'{case}: invalid measurements replaced the previous report')
            print('PASS ' + case, flush=True)


if __name__ == '__main__':
    main()
