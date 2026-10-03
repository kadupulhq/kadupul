"""Reject stale, incomplete or malformed measurements before publishing Clover."""
import argparse
import copy
import json
from pathlib import Path
import shutil
import subprocess
import tempfile

ROOT = Path(__file__).resolve().parents[2]


def prepare_database_failure_reports(directory, scratch, source, mutation):
    """Retain separate real reports and corrupt only the selected observation."""
    if mutation not in ('unmeasured', 'stale'):
        raise ValueError('Unknown database measurement mutation')
    reports = [(path, json.loads(path.read_text()))
               for path in sorted((directory / 'raw').glob('coverage-*.json'))]
    names = {path.name for path, _ in reports}
    if any(path.name not in names or path.is_symlink() or not path.is_file()
           for path in (scratch / 'raw').iterdir()):
        raise RuntimeError('Unexpected scratch coverage reports')
    if not any(1 in (report['files'] or {}).get(source, {}).get('lines', {}).values()
               for _, report in reports):
        raise RuntimeError('Self-test requires real database-session authentication measurements')
    for path, report in reports:
        destination = scratch / 'raw' / path.name
        shutil.copyfile(path, destination)
        observation = (report['files'] or {}).get(source)
        if observation is None:
            continue
        if mutation == 'unmeasured':
            observation['lines'] = {line: -1 for line in observation['lines']}
        else:
            observation['sha256'] = '0' * 64
        destination.write_text(json.dumps(report))


def main():
    from cdef_legacy_page_scenarios import REQUIRED_CHECKS

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
        'links.php',
        'src/Navigation/Infrastructure/Legacy/LegacyLinkStore.php',
        'src/Navigation/Infrastructure/Symfony/Controller/LinkEditController.php',
        'src/DataInput/Infrastructure/Legacy/DataInputHandoff.php',
        'bin/legacy-data-input.php', 'bin/legacy-data-input-handoff.php', 'lib/data_input_worker.php', 'data_input.php', 'src/DataInput/Infrastructure/Symfony/Controller/DataInputController.php', 'bin/legacy-device-edit.php', 'src/IdentityAccess/Infrastructure/Legacy/SharedSession.php',
        'src/IdentityAccess/Infrastructure/Legacy/LegacyAboutAccess.php',
        'src/IdentityAccess/Infrastructure/Legacy/LegacyBrowserAuthentication.php',
        'src/IdentityAccess/Infrastructure/Legacy/BrowserAuthenticationSql.php',
        'src/IdentityAccess/Infrastructure/Legacy/NativeAuthenticationSession.php',
        'src/IdentityAccess/Infrastructure/Legacy/AuthenticationFileSessionHandler.php',
        'about.php', 'src/Platform/Infrastructure/Symfony/Controller/AboutController.php',
        'src/Platform/Infrastructure/Symfony/Controller/LegacyAboutController.php',
        'src/Platform/Infrastructure/Legacy/InstallationProductVersion.php',
        'vdef.php',
        'src/GraphDefinition/Infrastructure/Legacy/LegacyVdefEditor.php',
        'src/GraphDefinition/Infrastructure/Symfony/Controller/VdefItemController.php',
        'src/GraphDefinition/Infrastructure/Symfony/Controller/VdefActionController.php',
        'links.php', 'src/Navigation/Infrastructure/Legacy/LegacyLinkStore.php', 'src/Navigation/Infrastructure/Symfony/Controller/LinkEditController.php',
        'color.php',
        'src/Graphing/Domain/PaletteCsv.php',
        'src/Graphing/Infrastructure/Legacy/LegacyPaletteColorStore.php',
        'src/Graphing/Infrastructure/Legacy/PaletteSql.php',
        'src/Graphing/Infrastructure/Legacy/LegacyPaletteColorAccess.php',
        'src/Graphing/Infrastructure/Legacy/LegacyPaletteColorPreferences.php',
        'src/Graphing/Infrastructure/Symfony/Controller/PaletteColorCsvController.php',
        'src/Graphing/Infrastructure/Symfony/Controller/PaletteColorEditController.php',
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
        'lib/api_device.php',
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
        'src/Inventory/Application/Command/ClearDeviceStatistics.php',
        'src/Inventory/Application/Command/SynchronizeDeviceTemplates.php',
        'src/Inventory/Infrastructure/Legacy/DeviceStatisticsReset.php',
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
    legacy_pages = ('graphs.php', 'cdef.php', 'aggregate_templates.php', 'color_templates.php', 'aggregate_graphs.php')
    required += [prefix + path for path in legacy_pages]
    for path in (args.files / 'raw').glob('coverage-*.json'):
        report = json.loads(path.read_text())
        for source in required:
            if 1 in (report['files'] or {}).get(source, {}).get('lines', {}).values():
                measured['files'][source] = report['files'][source]
    if set(measured['files']) != set(required):
        raise RuntimeError('Self-test requires real HTTP and worker measurements')
    data_input_checks = ['system page size fixture restores original absence and value', 'profile deletion confirmation page renders', 'unused profile is normally removable', 'collector retry builds real poller item from the saved command', 'offline collector yields explicit partial handoff without undoing local definition', 'whitelist update publishes the exact saved command and verifies it', 'worker independently rechecks feature grants before executing the handoff', 'French session authenticates through legacy login', 'French editor translates presentation without changing raw command definition', 'English field deletion confirmation uses a readable action label', 'French field deletion confirmation honors the authenticated preference']
    about_authentication_checks = ['About unprotected Basic headers cannot establish a web-server principal', 'About Basic identity is verified by Apache before PHP', 'About first Basic request restores native identity through the legacy forwarder', 'About Basic restoration resumes About without granting console realm 8', 'About restored Basic session refuses a revoked account', 'About first remembered request restores the native cookie identity', 'About remembered restoration resumes About without granting console realm 8', 'About remembered restoration consumes and rotates the exact native token', 'About consumed remembered token cannot be replayed', 'About replacement remembered token establishes a fresh native session', 'About restored remembered session refuses a disabled account']
    about_authentication_checks += ['About Basic transition publishes a native credential cookie', 'About remembered transition publishes protected session and replacement cookies']
    statistics_checks = ['statistics confirmation resets selected devices', 'statistics SQL rejection rolls back entire primary selection', 'remote statistics match the legacy reset', 'statistics reset invokes action 5 once with the complete selection', 'rejected statistics resets do not invoke action 5 callbacks', 'repeated statistics reset invokes action 5 once']
    state_probe_checks = ['bulk state observer records original production source identity',
                          'bulk state observer uses a separate fixture source outside production coverage',
                          'bulk state observer restores exact production bytes and removes owned fixtures']
    synchronization_checks = ['all-unassigned template synchronization invokes no mutation callbacks', 'all-unassigned template synchronization preserves the device-change marker', 'collector identity mismatch invokes no action 7 callback', 'collector identity mismatch rolls back the primary batch', 'deferred discovery failure does not publish a success audit record', 'deferred discovery failure reports uncertain completion', 'deferred discovery failure retains committed associations without a success callback', 'deferred synchronization discovery fixture registered', 'denied synchronization performs no writes or callbacks', 'each synchronized device receives only its own template associations', 'failed synchronization does not invoke the bulk action callback', 'failed synchronization does not publish a success audit record', 'missing template invokes no callbacks and preserves the marker', 'missing template preserves all primary associations', 'multiple templates do not leak associations across devices', 'multiple templates preserve per-device and complete-selection callback contracts', 'remote template synchronization preserves assigned template identity', 'slow synchronization discovery does not block a concurrent poller write', 'successful synchronization publishes exactly one success audit record', 'template synchronization GET does not add associations', 'template synchronization GET does not add data-query associations', 'template synchronization accepts an all-unassigned selection as a no-op', 'template synchronization adds required data-query associations', 'template synchronization adds required graph associations', 'template synchronization association failure cannot report success', 'template synchronization conceals inaccessible devices on GET and POST', 'template synchronization denied-realm fixture starts authorized', 'template synchronization discovery runs without an active primary transaction', 'template synchronization failure rolls back primary associations', 'template synchronization hook registered', 'template synchronization invokes action 7 once with complete selection', 'template synchronization invokes the template-change hook once per assigned device', 'template synchronization preserves the configured data-query reindex method', 'template synchronization refuses an actor without the device-management realm', 'template synchronization refuses unauthenticated POST requests', 'template synchronization refuses unauthenticated requests', 'template synchronization rejects a missing assigned template', 'template synchronization rejects altered collector template identity', 'template synchronization rejects offline collectors before writes', 'template synchronization rejects stale device revisions', 'template synchronization rejects unexpected fields', 'template synchronization removes unused graph associations', 'template synchronization repairs associations despite existing query cache', 'template synchronization requires CSRF token', 'template synchronization requires same-origin CSRF', 'template synchronization resolves the effective default reindex method', 'template synchronization retains associations used by existing graphs', 'template synchronization retains existing graphs', 'template synchronization retries safely after deferred discovery failure', 'template synchronization saves through Symfony', 'template synchronization skips unassigned devices', 'template synchronization supports a selection spanning different templates', 'template synchronization supports repeated synchronization', 'template synchronization worker independently refuses a revoked realm']
    failures = {
        'data-source-profile-test-hash': 'Integration test source differs',
        'about-authentication-test-hash': 'Integration test source differs',
        'missing-data-source-profile-test-hash': 'Integration test source differs',
        'source-hash': 'Covered source differs',
        'test-hash': 'Integration test source differs',
        'data-input-test-hash': 'Integration test source differs',
        'data-input-review-test-hash': 'Integration test source differs',
        'palette-test-hash': 'Integration test source differs',
        'missing-palette-handoff-check': 'Incomplete Symfony integration',
        'missing-palette-concurrent-auth': 'Incomplete Symfony integration',
        'missing-palette-write-guards': 'Incomplete Symfony integration',
        'missing-palette-persistent-storage': 'Incomplete Symfony integration',
        'missing-palette-preference-guards': 'Incomplete Symfony integration',
        'missing-links-locale-restoration': 'Incomplete Symfony integration',
        'missing-palette-unicode-labels': 'Incomplete Symfony integration',
        'missing-palette-review-check-0': 'Incomplete Symfony integration',
        'missing-palette-review-check-1': 'Incomplete Symfony integration',
        'missing-palette-review-check-2': 'Incomplete Symfony integration',
        'missing-palette-review-check-3': 'Incomplete Symfony integration',
        'missing-palette-review-check-4': 'Incomplete Symfony integration',
        'missing-palette-review-check-5': 'Incomplete Symfony integration',
        'missing-palette-review-check-6': 'Incomplete Symfony integration',
        'missing-palette-review-check-7': 'Incomplete Symfony integration',
        'missing-palette-review-check-8': 'Incomplete Symfony integration',
        'palette-sql-probe-hash': 'Integration test source differs',

        'vdef-test-hash': 'Integration test source differs',
        'missing-vdef-persistent-storage': 'Incomplete Symfony integration',
        'vdef-probe-hash': 'Integration test source differs',
        'vdef-browser-probe-hash': 'Integration test source differs',
        'vdef-browser-handler-hash': 'Integration test source differs',
        'missing-vdef-selection-check-0': 'Incomplete Symfony integration',
        'missing-vdef-selection-check-1': 'Incomplete Symfony integration',
        'missing-vdef-selection-check-2': 'Incomplete Symfony integration',
        'missing-vdef-selection-check-3': 'Incomplete Symfony integration',
        'missing-vdef-selection-check-4': 'Incomplete Symfony integration',
        'missing-vdef-selection-check-5': 'Incomplete Symfony integration',
        'missing-vdef-selection-check-6': 'Incomplete Symfony integration',
        'missing-vdef-selection-check-7': 'Incomplete Symfony integration',
        'missing-vdef-selection-check-8': 'Incomplete Symfony integration',
        'missing-vdef-selection-check-9': 'Incomplete Symfony integration',
        'missing-vdef-selection-check-10': 'Incomplete Symfony integration',
        'missing-vdef-selection-check-11': 'Incomplete Symfony integration',
        'missing-vdef-selection-check-12': 'Incomplete Symfony integration',
        'missing-vdef-array-type-check': 'Incomplete Symfony integration',
        'missing-vdef-french-item-check': 'Incomplete Symfony integration',
        'missing-vdef-reference-check': 'Incomplete Symfony integration',
        'missing-vdef-browser-check': 'Incomplete Symfony integration',
        'missing-vdef-legacy-bound-check': 'Incomplete Symfony integration',
        'missing-vdef-engine-check': 'Incomplete Symfony integration',
        'missing-vdef-handoff': 'Incomplete Symfony integration',
        'details-test-hash': 'Integration test source differs',
        'sites-test-hash': 'Integration test source differs',
        'site-edit-test-hash': 'Integration test source differs',
        'missing-check': 'Incomplete Symfony integration',
        'missing-statistics-check-0': 'Incomplete Symfony integration',
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
    failures['missing-legacy-page-test-hash'] = 'Integration test source differs'
    failures['stale-legacy-page-test-hash'] = 'Integration test source differs'
    for index in range(len(REQUIRED_CHECKS)):
        failures['missing-legacy-page-check-' + str(index)] = 'Incomplete Symfony integration'
    for source in legacy_pages:
        failures['stale-legacy-page-source-' + source] = 'Covered source differs'
        failures['missing-legacy-page-source-' + source] = 'Missing measured execution'
    for index in range(len(synchronization_checks)):
        failures['missing-synchronization-check-' + str(index)] = 'Incomplete Symfony integration'
    for index in range(len(state_probe_checks)):
        failures['missing-state-probe-check-' + str(index)] = 'Incomplete Symfony integration'
    for index in range(3):
        failures['missing-palette-selection-check-' + str(index)] = 'Incomplete Symfony integration'
    for source in required:
        failures.setdefault('unmeasured-' + source.rsplit('/', 1)[-1], 'Missing measured execution')
    with tempfile.TemporaryDirectory(prefix='symfony-coverage-negative-') as directory:
        scratch = Path(directory)
        (scratch / 'raw').mkdir()
        raw = scratch / 'raw/coverage-probe.json'
        output = scratch / 'result.xml'
        for index in range(len(statistics_checks)):
            failures['missing-statistics-check-' + str(index)] = 'Incomplete Symfony integration'
        for index in range(len(data_input_checks)):
            failures['missing-data-input-check-' + str(index)] = 'Incomplete Symfony integration'
        for index in range(len(about_authentication_checks)):
            failures['missing-about-authentication-check-' + str(index)] = 'Incomplete Symfony integration'
        for case, expected in failures.items():
            data = copy.deepcopy(measured)
            evidence = copy.deepcopy(manifest)
            worker = data['files'][required[0]]
            if case == 'source-hash':
                worker['sha256'] = '0' * 64
            elif case == 'data-source-profile-test-hash':
                evidence['source_sha256']['tests/Symfony/data_source_profile_scenarios.py'] = '0' * 64
            elif case == 'about-authentication-test-hash':
                evidence['source_sha256']['tests/Symfony/about_authentication_scenarios.py'] = '0' * 64
            elif case.startswith('missing-about-authentication-check-'):
                missing = about_authentication_checks[int(case.rsplit('-', 1)[1])]
                evidence['checks'] = [check for check in evidence['checks'] if check != missing]
            elif case == 'missing-palette-handoff-check':
                evidence['checks'].remove('CSV exact name data handoff')
            elif case.startswith('missing-palette-review-check-'):
                required_palette_checks = ['silent palette SQL failures preserve rows and refuse false saves imports and dependency deletes', 'duplicate hex creation is a known validation failure after rollback', 'duplicate hex edit is a known validation failure after rollback', 'duplicate hex edit preserves the original name and hex', 'unnamed palette color has a visible edit link and accessible hex label', 'palette exports neutralize formulas and preserve exact versioned roundtrip names', 'unsupported or malformed palette literal marker rejects the whole import', 'ordinary legacy CSV import preserves its leading apostrophe literally', 'console-only palette account cannot parse or mutate any route']
                missing = required_palette_checks[int(case.removeprefix('missing-palette-review-check-'))]
                evidence['checks'] = [check for check in evidence['checks'] if check != missing]
            elif case.startswith('missing-palette-selection-check-'):
                checks = ['palette large pages keep all rows readable but enable at most 100 deletable choices', 'palette 100-color confirmation preserves every selected identity and revision', 'palette forged 101-color selection is refused before mutation']
                evidence['checks'].remove(checks[int(case.removeprefix('missing-palette-selection-check-'))])
            elif case == 'missing-palette-persistent-storage':
                evidence['checks'] = [check for check in evidence['checks'] if check != 'palette writes and preferences reject all InnoDB temporary shadows without changing persistent observer rows']
            elif case == 'missing-palette-write-guards':
                evidence['checks'].remove('palette writes refuse actual nontransactional tables, invalid collectors and caller transactions without losing prior work')
            elif case == 'missing-palette-unicode-labels':
                evidence['checks'] = [check for check in evidence['checks'] if check != 'palette Unicode invisible names use accessible hex labels while visible names and CSV bytes remain exact']
            elif case == 'missing-links-locale-restoration':
                evidence['checks'].remove('links French fixture restores exact original global and actor language settings')
            elif case == 'missing-palette-preference-guards':
                evidence['checks'].remove('palette preferences refuse actual nontransactional tables, invalid collectors and caller transactions while primary saves commit')
            elif case == 'missing-palette-concurrent-auth':
                evidence['checks'].remove('two palette actors authorize concurrently while policy, account and realm revokers wait and later denials take effect')
            elif case == 'missing-legacy-page-test-hash':
                evidence['source_sha256'].pop('tests/Symfony/cdef_legacy_page_scenarios.py')
            elif case == 'stale-legacy-page-test-hash':
                evidence['source_sha256']['tests/Symfony/cdef_legacy_page_scenarios.py'] = '0' * 64
            elif case.startswith('missing-legacy-page-check-'):
                omitted = REQUIRED_CHECKS[int(case.rsplit('-', 1)[1])]
                evidence['checks'] = [check for check in evidence['checks'] if check != omitted]
            elif case.startswith('stale-legacy-page-source-'):
                source = prefix + case.removeprefix('stale-legacy-page-source-')
                data['files'][source]['sha256'] = '0' * 64
            elif case.startswith('missing-legacy-page-source-'):
                data['files'].pop(prefix + case.removeprefix('missing-legacy-page-source-'))
            elif case == 'palette-sql-probe-hash':
                evidence['source_sha256']['tests/Symfony/palette_sql_failure_probe.php'] = '0' * 64
            elif case == 'palette-test-hash':
                evidence['source_sha256']['tests/Symfony/palette_color_scenarios.py'] = '0' * 64
            elif case == 'missing-data-source-profile-test-hash':
                evidence['source_sha256'].pop('tests/Symfony/data_source_profile_scenarios.py')
            elif case == 'vdef-probe-hash':
                evidence['source_sha256']['tests/Symfony/vdef_transaction_probe.php'] = '0' * 64
            elif case == 'vdef-browser-probe-hash':
                evidence['source_sha256']['tests/Symfony/vdef_browser_probe.cjs'] = '0' * 64
            elif case == 'vdef-browser-handler-hash':
                evidence['source_sha256']['public/js/vdef-item.js'] = '0' * 64
            elif case.startswith('missing-vdef-selection-check-'):
                checks = ['VDEF malformed list arrays return controlled 400 before catalog reads: filter', 'VDEF malformed list arrays return controlled 400 before catalog reads: sort', 'VDEF malformed list arrays return controlled 400 before catalog reads: direction', 'VDEF malformed list arrays return controlled 400 before catalog reads: has_graphs', 'VDEF own legacy reference deletes through CSRF form: 0', 'VDEF own legacy reference deletes through CSRF form: 1', 'VDEF own legacy reference deletes through CSRF form: 2', 'VDEF own legacy reference deletes through CSRF form: 3', 'VDEF own legacy reference deletes through CSRF form: 4', 'VDEF own legacy reference deletes through CSRF form: 5', 'VDEF own legacy reference deletes through CSRF form: 6', 'VDEF own legacy reference deletes through CSRF form: 7', 'VDEF whole selected reference set deletes through CSRF form']
                evidence['checks'].remove(checks[int(case.rsplit('-', 1)[1])])
            elif case.startswith('missing-vdef-') and case in ['missing-vdef-array-type-check', 'missing-vdef-french-item-check', 'missing-vdef-reference-check', 'missing-vdef-browser-check', 'missing-vdef-legacy-bound-check']:
                omitted = {'missing-vdef-array-type-check': 'VDEF array type query returns controlled 400 without mutation',
                           'missing-vdef-french-item-check': 'VDEF unknown item deletion uses French catalog label',
                           'missing-vdef-reference-check': 'VDEF nested reference refuses function overwrite',
                           'missing-vdef-browser-check': 'VDEF browser type change and save pass under CSP',
                           'missing-vdef-legacy-bound-check': 'VDEF oversized legacy parent ID falls back'}[case]
                evidence['checks'].remove(omitted)
            elif case == 'missing-vdef-engine-check':
                evidence['checks'].remove('VDEF nontransactional table refused: vdef')
            elif case == 'missing-vdef-persistent-storage':
                evidence['checks'] = [check for check in evidence['checks'] if check != 'VDEF writes reject every InnoDB temporary participant and preserve persistent observer rows']
            elif case == 'vdef-test-hash':
                evidence['source_sha256']['tests/Symfony/vdef_scenarios.py'] = '0' * 64
            elif case == 'missing-vdef-handoff':
                evidence['checks'].remove('VDEF duplicate preserves all item rows')
            elif case == 'test-hash':
                evidence['source_sha256']['tests/Symfony/session_bridge.py'] = '0' * 64
            elif case == 'data-input-test-hash':
                evidence['source_sha256']['tests/Symfony/data_input_scenarios.py'] = '0' * 64
            elif case == 'data-input-review-test-hash':
                evidence['source_sha256']['tests/Symfony/data_input_review_http.py'] = '0' * 64
            elif case == 'data-source-profile-test-hash':
                evidence['source_sha256']['tests/Symfony/data_source_profile_scenarios.py'] = '0' * 64
            elif case.startswith('missing-data-input-check-'):
                omitted = data_input_checks[int(case.rsplit('-', 1)[1])]
                evidence['checks'] = [check for check in evidence['checks'] if check != omitted]
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
            elif case.startswith('missing-statistics-check-'):
                missing = statistics_checks[int(case.rsplit('-', 1)[1])]
                evidence['checks'] = [check for check in evidence['checks'] if check != missing]
            elif case.startswith('missing-synchronization-check-'):
                missing = synchronization_checks[int(case.rsplit('-', 1)[1])]
                evidence['checks'] = [check for check in evidence['checks'] if check != missing]
            elif case.startswith('missing-state-probe-check-'):
                missing = state_probe_checks[int(case.rsplit('-', 1)[1])]
                evidence['checks'] = [check for check in evidence['checks'] if check != missing]
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

    database_manifest = json.loads((args.database / 'observations.json').read_text())
    database_paths = [prefix + 'src/IdentityAccess/Infrastructure/Legacy/' + name + '.php'
                      for name in ('AuthenticationDatabaseSessionHandler', 'ReadOnlyDatabaseSessionHandler')]
    with tempfile.TemporaryDirectory(prefix='symfony-database-authentication-negative-') as directory:
        scratch = Path(directory)
        (scratch / 'raw').mkdir()
        output = scratch / 'result.xml'
        (scratch / 'observations.json').write_text(json.dumps(database_manifest))
        for source in database_paths:
            for mutation in ('unmeasured', 'stale'):
                prepare_database_failure_reports(args.database, scratch, source, mutation)
                if mutation == 'unmeasured':
                    expected = 'Missing measured execution: ' + source.removeprefix(prefix)
                else:
                    expected = 'Covered source differs'
                output.write_text('previous report')
                result = subprocess.run([args.php, str(ROOT / 'tests/Symfony/merge_coverage.php'),
                                         str(args.unit.resolve()), str(args.files.resolve()),
                                         str(scratch), str(args.offline.resolve()), str(output)],
                                        capture_output=True, text=True, timeout=60)
                if result.returncode == 0 or expected not in result.stdout + result.stderr:
                    raise RuntimeError(f'{mutation} {source}: unexpected merge result: {result.stdout} {result.stderr}')
                if output.read_text() != 'previous report':
                    raise RuntimeError('Invalid database measurements replaced the previous report')
                print('PASS database-' + mutation + '-' + source.rsplit('/', 1)[-1], flush=True)


if __name__ == '__main__':
    main()
