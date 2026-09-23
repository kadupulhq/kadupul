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
        'src/Inventory/Infrastructure/Symfony/Form/DeviceRemovalType.php',
        'src/Inventory/Infrastructure/Symfony/Controller/DeviceRemovalController.php',
        'src/Inventory/Domain/DeviceState.php',
        'src/Inventory/Domain/DeviceSelection.php',
        'src/Inventory/Application/Command/SetDevicesEnabled.php',
        'src/Inventory/Application/Query/PrepareDeviceStateChange.php',
        'src/Inventory/Infrastructure/Legacy/LegacyDeviceStates.php',
        'src/Inventory/Domain/DeviceBulkAssignment.php',
        'src/Inventory/Application/Command/AssignDevices.php',
        'src/Inventory/Application/Query/ListDeviceAssignmentTargets.php',
        'src/Inventory/Infrastructure/Legacy/DeviceBulkAssignmentWriter.php',
        'src/Inventory/Infrastructure/Legacy/DeviceCollectorTransfer.php',
        'src/Inventory/Infrastructure/Symfony/Form/DeviceBulkAssignmentType.php',
        'src/Inventory/Infrastructure/Symfony/Controller/DeviceBulkAssignmentController.php',
        'src/Inventory/Application/Command/ChangeDevicesSnmp.php',
        'src/Inventory/Infrastructure/Legacy/DeviceSnmpWriter.php',
        'src/Graphing/Infrastructure/Legacy/LegacyDeviceTreePlacement.php',
        'src/Reporting/Infrastructure/Legacy/LegacyDeviceReportPlacement.php',
        'bin/legacy-device-placement.php',
        'src/IdentityAccess/Infrastructure/Legacy/LegacyResourceAccess.php',
        'src/Inventory/Domain/DevicePlacement.php',
        'src/Inventory/Application/Command/PlaceDevices.php',
        'src/Inventory/Application/Query/ListDevicePlacementDestinations.php',
        'src/Inventory/Infrastructure/Legacy/LegacyDevicePlacements.php',
        'src/Inventory/Infrastructure/Symfony/Controller/DevicePlacementController.php',
        'src/Inventory/Infrastructure/Symfony/Form/DevicePlacementType.php',
        'bin/legacy-device-maintenance.php',
        'src/Inventory/Domain/DeviceMaintenanceRequest.php',
        'src/Inventory/Domain/DeviceMaintenanceState.php',
        'src/Inventory/Application/Command/MaintainDevice.php',
        'src/Inventory/Application/Query/PrepareDeviceMaintenance.php',
        'src/Inventory/Application/ReadModel/DeviceMaintenanceResult.php',
        'src/Inventory/Infrastructure/Legacy/LegacyDeviceMaintenance.php',
        'src/Inventory/Infrastructure/Legacy/DeviceMaintenanceRecords.php',
        'src/Inventory/Infrastructure/Legacy/DeviceMaintenanceExecutor.php',
        'src/Inventory/Infrastructure/Legacy/DeviceDiagnosticText.php',
        'src/Inventory/Infrastructure/Legacy/DeviceDiagnosticScope.php',
        'src/Inventory/Infrastructure/Symfony/Controller/DeviceMaintenanceController.php',
        'src/Inventory/Infrastructure/Symfony/Form/DeviceMaintenanceType.php',
        'bin/legacy-device-associations.php',
        'src/Inventory/Domain/DeviceAssociations.php',
        'src/Inventory/Domain/DeviceAssociationChange.php',
        'src/Inventory/Application/Command/ChangeDeviceAssociation.php',
        'src/Inventory/Application/Query/PrepareDeviceAssociations.php',
        'src/Inventory/Infrastructure/Legacy/LegacyDeviceAssociations.php',
        'src/Inventory/Infrastructure/Legacy/DeviceAssociationRecords.php',
        'src/Inventory/Infrastructure/Legacy/DeviceAssociationWriter.php',
        'src/Inventory/Infrastructure/Symfony/Controller/DeviceAssociationController.php',
        'src/Inventory/Infrastructure/Symfony/Form/DeviceAssociationType.php',
        'src/Inventory/Domain/DeviceOptionsChange.php',
        'src/Inventory/Application/Command/ChangeDeviceOptions.php',
        'src/Inventory/Infrastructure/Legacy/DeviceOptionsWriter.php',
        'src/Inventory/Infrastructure/Symfony/Form/DeviceOptionsType.php',
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
        'src/Inventory/Infrastructure/Legacy/LegacySiteAssignmentCatalog.php',
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
        'src/Inventory/Infrastructure/Legacy/LegacyDeviceCreationCatalog.php',
        'src/Inventory/Infrastructure/Legacy/LegacyDeviceCreator.php',
        'src/Platform/Infrastructure/Symfony/InventoryLocaleSubscriber.php')]
    for path in (args.files / 'raw').glob('coverage-*.json'):
        report = json.loads(path.read_text())
        for source in required:
            if 1 in (report['files'] or {}).get(source, {}).get('lines', {}).values():
                measured['files'][source] = report['files'][source]
    if set(measured['files']) != set(required):
        raise RuntimeError('Self-test requires real HTTP and worker measurements')
    statistics_checks = ['statistics confirmation resets selected devices', 'statistics SQL rejection rolls back entire primary selection', 'remote statistics match the legacy reset', 'statistics reset invokes action 5 once with the complete selection', 'rejected statistics resets do not invoke action 5 callbacks', 'repeated statistics reset invokes action 5 once']
    synchronization_checks = ['template synchronization saves through Symfony', 'template synchronization failure rolls back primary associations', 'remote template synchronization preserves assigned template identity', 'template synchronization invokes action 7 once with complete selection', 'template synchronization invokes the template-change hook once per assigned device', 'template synchronization retains existing graphs']
    assignment_checks = ['bulk site assigns through Symfony', 'bulk template assigns through Symfony', 'bulk site failure rolls back whole primary selection', 'bulk template failure rolls back whole primary selection', 'bulk collector moves full selection to remote', 'bulk collector returns full selection to primary', 'bulk collector purges old remote copies']
    snmp_checks = ['bulk SNMP never displays stored credentials', 'bulk SNMP keeps each device credentials through Symfony', 'bulk SNMP validates all stored credentials before writes', 'bulk SNMP failure rolls back entire primary selection', 'bulk SNMP replaces credentials through Symfony', 'bulk SNMP verifies remote credentials', 'bulk SNMP secrets stay out of database diagnostics']
    placement_checks = ['tree legacy placement shares destination locks and rejects duplicates', 'report legacy placement shares destination locks and rejects duplicates', 'tree placement verifies final state after callbacks', 'report placement verifies final state after callbacks', 'tree placement saves through Symfony', 'report placement saves through Symfony', 'tree placement rolls back entire selection', 'report placement rolls back entire selection', 'tree placement preserves selected parent', 'report placement preserves display settings', 'tree placement does not duplicate existing devices', 'report placement does not duplicate existing devices']
    maintenance_checks = ['maintenance enables debug through Symfony', 'maintenance confirms remote debug setting', 'maintenance SQL rejection cannot report success', 'maintenance failure rolls back primary debug settings', 'maintenance refreshes polling cache through Symfony', 'maintenance connectivity probes the real SNMP fixture', 'collector ping returns sanitized diagnostics', 'collector runquery returns sanitized diagnostics', 'maintenance executes reload-query against the SNMP fixture', 'maintenance executes reindex against the SNMP fixture', 'maintenance executes query-diagnostics against the SNMP fixture', 'maintenance rejects stale device settings']
    graph_checks = ['graph association adds through Symfony', 'graph association invokes plugin hook once with exact payload', 'graph association automation creates a graph', 'graph association removes through Symfony', 'graph association failure rolls back primary writes', 'graph association verifies remote template', 'graph association removal retains existing graphs']
    query_checks = ['query association adds through Symfony', 'query association removes through Symfony', 'query association failure rolls back primary writes', 'query reindex method changes through Symfony', 'query reindex method is verified on collector', 'query removal retains existing graphs', 'query removal clears associations cache and reindex state']
    option_checks = ['bulk options save through Symfony', 'bulk options failure rolls back entire primary batch', 'bulk options verifies remote values', 'bulk options changes selected fields and preserves unchecked values', 'bulk options invokes action 4 once for the complete selection', 'rejected bulk options do not invoke action 4']
    failures = {
        'source-hash': 'Covered source differs',
        'test-hash': 'Integration test source differs',
        'details-test-hash': 'Integration test source differs',
        'sites-test-hash': 'Integration test source differs',
        'site-edit-test-hash': 'Integration test source differs',
        'missing-check': 'Incomplete Symfony integration',
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
        'unmeasured-LegacyDeviceCreationCatalog.php': 'Missing measured execution: src/Inventory/Infrastructure/Legacy/LegacyDeviceCreationCatalog.php',
        'unmeasured-LegacyDeviceCreator.php': 'Missing measured execution: src/Inventory/Infrastructure/Legacy/LegacyDeviceCreator.php',
        'path-traversal': 'Invalid integration source path',
    }
    for index in range(len(statistics_checks)):
        failures['missing-statistics-check-' + str(index)] = 'Incomplete Symfony integration'
    for index in range(len(synchronization_checks)):
        failures['missing-synchronization-check-' + str(index)] = 'Incomplete Symfony integration'
    for index in range(len(assignment_checks)):
        failures['missing-assignment-check-' + str(index)] = 'Incomplete Symfony integration'
    for index in range(len(snmp_checks)):
        failures['missing-snmp-check-' + str(index)] = 'Incomplete Symfony integration'
    for index in range(len(placement_checks)):
        failures['missing-placement-check-' + str(index)] = 'Incomplete Symfony integration'
    for index in range(len(maintenance_checks)):
        failures['missing-maintenance-check-' + str(index)] = 'Incomplete Symfony integration'
    for index in range(len(graph_checks)):
        failures['missing-graph-check-' + str(index)] = 'Incomplete Symfony integration'
    for index in range(len(query_checks)):
        failures['missing-query-check-' + str(index)] = 'Incomplete Symfony integration'
    for index in range(len(option_checks)):
        failures['missing-option-check-' + str(index)] = 'Incomplete Symfony integration'
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
            elif case == 'missing-device-creation-check':
                evidence['checks'].remove('legacy template graph associations are preserved')
            elif case.startswith('missing-statistics-check-'):
                missing = statistics_checks[int(case.rsplit('-', 1)[1])]
                evidence['checks'] = [check for check in evidence['checks'] if check != missing]
            elif case.startswith('missing-synchronization-check-'):
                missing = synchronization_checks[int(case.rsplit('-', 1)[1])]
                evidence['checks'] = [check for check in evidence['checks'] if check != missing]
            elif case.startswith('missing-assignment-check-'):
                missing = assignment_checks[int(case.rsplit('-', 1)[1])]
                evidence['checks'] = [check for check in evidence['checks'] if check != missing]
            elif case.startswith('missing-snmp-check-'):
                missing = snmp_checks[int(case.rsplit('-', 1)[1])]
                evidence['checks'] = [check for check in evidence['checks'] if check != missing]
            elif case.startswith('missing-placement-check-'):
                missing = placement_checks[int(case.rsplit('-', 1)[1])]
                evidence['checks'] = [check for check in evidence['checks'] if check != missing]
            elif case.startswith('missing-maintenance-check-'):
                missing = maintenance_checks[int(case.rsplit('-', 1)[1])]
                evidence['checks'] = [check for check in evidence['checks'] if check != missing]
            elif case.startswith('missing-graph-check-'):
                missing = graph_checks[int(case.rsplit('-', 1)[1])]
                evidence['checks'] = [check for check in evidence['checks'] if check != missing]
            elif case.startswith('missing-query-check-'):
                missing = query_checks[int(case.rsplit('-', 1)[1])]
                evidence['checks'] = [check for check in evidence['checks'] if check != missing]
            elif case.startswith('missing-option-check-'):
                missing = option_checks[int(case.rsplit('-', 1)[1])]
                evidence['checks'] = [check for check in evidence['checks'] if check != missing]
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
