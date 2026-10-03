<?php

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

// Loaded only when the parent PHPUnit run is collecting real coverage.
$coverageRoot = dirname(__DIR__, 2);
if (defined('LEGACY_COMMAND_OUTPUT_TEST_COVERAGE') || defined('AUDIT_TRAIL_TEST_COVERAGE') || defined('DATA_INPUT_LIST_TEST_COVERAGE') || defined('UTILITY_LOG_TEST_COVERAGE')) {
    require_once $coverageRoot . '/include/vendor/autoload.php';
} else {
    require_once $coverageRoot . '/tests/vendor/autoload.php';
}
$coverageFilter = new SebastianBergmann\CodeCoverage\Filter();
if (defined('DATA_INPUT_LIST_TEST_COVERAGE')) {
    $coverageFilter->includeFile($coverageRoot . '/lib/data_input_worker.php');
    $coverageFilter->includeFile($coverageRoot . '/src/DataInput/Domain/DataInputState.php');
}
if (defined('INPUT_WHITELIST_TEST_COVERAGE')) {
    $coverageFilter->includeFile($coverageRoot . '/lib/input_whitelist.php');
    if (in_array('--audit', $_SERVER['argv'], true)) {
        $coverageFilter->includeFile($coverageRoot . '/lib/template.php');
        $coverageFilter->includeFile($coverageRoot . '/lib/graph_template_input.php');
    }
}
if (defined('INPUT_STRING_VALIDATOR_TEST_COVERAGE')) {
    $coverageFilter->includeFile($coverageRoot . '/lib/functions.php');
}
if (defined('PERMISSION_FILTER_TEST_COVERAGE')) {
    $coverageFilter->includeFile($coverageRoot . '/user_admin.php');
    $coverageFilter->includeFile($coverageRoot . '/user_group_admin.php');
    $coverageFilter->includeFile($coverageRoot . '/src/IdentityAccess/Infrastructure/Legacy/PermissionFilter.php');
    $coverageFilter->includeFile($coverageRoot . '/src/IdentityAccess/Infrastructure/Legacy/PermissionAssociations.php');
    $coverageFilter->includeFile($coverageRoot . '/lib/html.php');
}
if (defined('PERMISSION_REQUEST_TEST_COVERAGE')) {
    $coverageFilter->includeFile($coverageRoot . '/user_admin.php');
    $coverageFilter->includeFile($coverageRoot . '/user_group_admin.php');
    $coverageFilter->includeFile($coverageRoot . '/lib/html_utility.php');
    $coverageFilter->includeFile($coverageRoot . '/lib/functions.php');
    $coverageFilter->includeFile($coverageRoot . '/src/IdentityAccess/Infrastructure/Legacy/PermissionRequests.php');
}
if (defined('ADMIN_LIST_TEST_COVERAGE')) {
    $coverageFilter->includeFile($coverageRoot . '/user_admin.php');
    $coverageFilter->includeFile($coverageRoot . '/user_group_admin.php');
    $coverageFilter->includeFile($coverageRoot . '/lib/html.php');
    $coverageFilter->includeFile($coverageRoot . '/lib/html_form.php');
    $coverageFilter->includeFile($coverageRoot . '/lib/html_utility.php');
    $coverageFilter->includeFile($coverageRoot . '/src/IdentityAccess/Infrastructure/Legacy/PermissionTemplateGrid.php');
}
if (defined('REALM_RENDER_TEST_COVERAGE')) {
    $coverageFilter->includeFile($coverageRoot . '/user_admin.php');
    $coverageFilter->includeFile($coverageRoot . '/user_group_admin.php');
    $coverageFilter->includeFile($coverageRoot . '/src/IdentityAccess/Infrastructure/Legacy/PermissionRealms.php');
    $coverageFilter->includeFile($coverageRoot . '/lib/html.php');
    $coverageFilter->includeFile($coverageRoot . '/lib/html_form.php');
    $coverageFilter->includeFile($coverageRoot . '/lib/auth.php');
}
if (defined('AUTH_POLICY_TEST_COVERAGE')) {
    $coverageFilter->includeFile($coverageRoot . '/lib/auth.php');
}
if (defined('HTML_REPORT_RENDER_TEST_COVERAGE')) {
    $coverageFilter->includeFile($coverageRoot . '/lib/html.php');
    $coverageFilter->includeFile($coverageRoot . '/lib/reports.php');
}
if (defined('UTILITY_LOG_TEST_COVERAGE')) {
    $coverageFilter->includeFile($coverageRoot . '/utilities.php');
}
if (defined('UTILITY_VIEW_TEST_COVERAGE')) {
    $coverageFilter->includeFile($coverageRoot . '/utilities.php');
    $coverageFilter->includeFile($coverageRoot . '/lib/html.php');
    $coverageFilter->includeFile($coverageRoot . '/lib/html_utility.php');
    $coverageFilter->includeFile($coverageRoot . '/lib/functions.php');
    $coverageFilter->includeFile($coverageRoot . '/lib/clog_webapi.php');
    $coverageFilter->includeFile($coverageRoot . '/src/Platform/Infrastructure/Legacy/UtilityRows.php');
}
if (defined('CSRF_CALLBACK_TEST_COVERAGE')) {
    $coverageFilter->includeFile($coverageRoot . '/include/csrf.php');
}

if (defined('STRING_PREDICATE_TEST_COVERAGE')) {
    $coverageFilter->includeFile($coverageRoot . '/lib/functions.php');
    $coverageFilter->includeFile($coverageRoot . '/lib/html_utility.php');
    $coverageFilter->includeFile($coverageRoot . '/lib/database.php');
    $coverageFilter->includeFile($coverageRoot . '/lib/path_helpers.php');
}
if (defined('ADMIN_PERMISSION_TEST_COVERAGE')) {
    $coverageFilter->includeFile($coverageRoot . '/src/IdentityAccess/Infrastructure/Legacy/PermissionMutation.php');
    $coverageFilter->includeFile($coverageRoot . '/src/IdentityAccess/Infrastructure/Legacy/PermissionAssociations.php');
    $coverageFilter->includeFile($coverageRoot . '/user_admin.php');
    $coverageFilter->includeFile($coverageRoot . '/user_group_admin.php');
    $coverageFilter->includeFile($coverageRoot . '/lib/auth.php');
}
if (defined('PERMISSION_MUTATION_TEST_COVERAGE')) {
    $coverageFilter->includeFile($coverageRoot . '/src/IdentityAccess/Infrastructure/Legacy/PermissionMutation.php');
    $coverageFilter->includeFile($coverageRoot . '/src/IdentityAccess/Infrastructure/Legacy/PermissionAssociations.php');
    $coverageFilter->includeFile($coverageRoot . '/lib/database.php');
}
if (defined('REPORT_PERSISTENCE_TEST_COVERAGE')) {
    $coverageFilter->includeFile($coverageRoot . '/lib/auth.php');
    $coverageFilter->includeFile($coverageRoot . '/lib/reports.php');
    $coverageFilter->includeFile($coverageRoot . '/lib/html_reports.php');
}
if (defined('AUTH_CONTROLLER_TEST_COVERAGE')) {
    $coverageFilter->includeFile($coverageRoot . '/lib/auth.php');
    $coverageFilter->includeFile($coverageRoot . '/auth_login.php');
    $coverageFilter->includeFile($coverageRoot . '/auth_changepassword.php');
    $coverageFilter->includeFile($coverageRoot . '/logout.php');
}
if (defined('DATA_INPUT_INDEX_UPGRADE_TEST_COVERAGE')) {
    $coverageFilter->includeFile($coverageRoot . '/install/upgrades/1_2_33.php');
    $coverageFilter->includeFile($coverageRoot . '/include/global_arrays.php');
}
if (defined('AUDIT_TRAIL_TEST_COVERAGE')) {
    $coverageFilter->includeFile($coverageRoot . '/src/IdentityAccess/Infrastructure/Legacy/LegacyAuditTrail.php');
}
if (defined('REQUEST_CONTEXT_TEST_COVERAGE')) {
    $coverageFilter->includeFile($coverageRoot . '/lib/functions.php');
    $coverageFilter->includeFile($coverageRoot . '/src/Platform/Infrastructure/Legacy/LegacyRequestContext.php');
}
if (defined('FORM_RENDERER_TEST_COVERAGE')) {
    $coverageFilter->includeFile($coverageRoot . '/lib/html_form.php');
}
if (defined('COLOR_DROPDOWN_TEST_COVERAGE')) {
    $coverageFilter->includeFile($coverageRoot . '/lib/html_form.php');
}
if (defined('PACKAGE_XML_TEST_COVERAGE')) {
    $coverageFilter->includeFile($coverageRoot . '/lib/import.php');
}
if (defined('PLUGIN_COMPAT_TEST_COVERAGE')) {
    $coverageFilter->includeFile($coverageRoot . '/lib/plugins.php');
}
if (defined('LEGACY_COMMAND_OUTPUT_TEST_COVERAGE')) {
    $coverageFilter->includeFile($coverageRoot . '/src/Platform/Infrastructure/Legacy/LegacyCommandOutput.php');
}
if (defined('FORCE_HTTPS_TEST_COVERAGE')) {
    $coverageFilter->includeFile($coverageRoot . '/lib/html_utility.php');
}
if (defined('HOST_REINDEX_TEST_COVERAGE')) {
    $coverageFilter->includeFile($coverageRoot . '/host.php');
    $coverageFilter->includeFile($coverageRoot . '/lib/html_utility.php');
}
if (defined('GRAPH_ZOOM_TEST_COVERAGE')) {
    $coverageFilter->includeFile($coverageRoot . '/lib/graph_zoom.php');
    $coverageFilter->includeFile($coverageRoot . '/lib/functions.php');
}
if (defined('REALTIME_EXEC_TEST_COVERAGE')) {
    $coverageFilter->includeFile($coverageRoot . '/graph_realtime.php');
    $coverageFilter->includeFile($coverageRoot . '/lib/html_utility.php');
}
if (defined('WHITELIST_EXEC_TEST_COVERAGE')) {
    $coverageFilter->includeFile($coverageRoot . '/data_input.php');
    $coverageFilter->includeFile($coverageRoot . '/lib/functions.php');
    $coverageFilter->includeFile($coverageRoot . '/lib/html_utility.php');
}
if (defined('AGGREGATE_QUERY_TEST_COVERAGE')) {
    $coverageFilter->includeFile($coverageRoot . '/lib/api_aggregate.php');
}
if (defined('SPIKE_CSRF_TEST_COVERAGE')) {
    $coverageFilter->includeFile($coverageRoot . '/spikekill.php');
    $coverageFilter->includeFile($coverageRoot . '/lib/html_utility.php');
}
if (defined('IMPORT_PREVIEW_TEST_COVERAGE')) {
    $coverageFilter->includeFile($coverageRoot . '/package_import.php');
    $coverageFilter->includeFile($coverageRoot . '/templates_import.php');
    $coverageFilter->includeFile($coverageRoot . '/lib/import.php');
}
if (defined('BULK_CSRF_CONTROLLER')) {
    $coverageFilter->includeFile(BULK_CSRF_CONTROLLER);
    $coverageFilter->includeFile($coverageRoot . '/lib/html_utility.php');
}
if (defined('ADMIN_MUTATION_TEST_COVERAGE')) {
    $coverageFilter->includeFile($coverageRoot . '/plugins.php');
    $coverageFilter->includeFile($coverageRoot . '/user_admin.php');
    $coverageFilter->includeFile($coverageRoot . '/user_group_admin.php');
    $coverageFilter->includeFile($coverageRoot . '/lib/html_utility.php');
}
if (defined('GRAPH_INPUT_TEST_COVERAGE')) {
    $coverageFilter->includeFile($coverageRoot . '/lib/graph_template_input.php');
    $coverageFilter->includeFile($coverageRoot . '/lib/import.php');
    $coverageFilter->includeFile($coverageRoot . '/lib/api_graph.php');
    $coverageFilter->includeFile($coverageRoot . '/lib/html_form_template.php');
    $coverageFilter->includeFile($coverageRoot . '/graphs.php');
    $coverageFilter->includeFile($coverageRoot . '/graph_templates_inputs.php');
    $coverageFilter->includeFile($coverageRoot . '/lib/template.php');
    $coverageFilter->includeFile($coverageRoot . '/lib/html_utility.php');
}
if (defined('INSTALLER_CSRF_BOOTSTRAP_COVERAGE')) {
    $coverageFilter->includeFile($coverageRoot . '/include/csrf.php');
}
if (defined('GRAPH_TEMPLATE_SECURITY_TEST_COVERAGE')) {
    $coverageFilter->includeFile($coverageRoot . '/graph_templates.php');
    $coverageFilter->includeFile($coverageRoot . '/lib/html_utility.php');
}
if (defined('DATA_SOURCE_LIMIT_TEST_COVERAGE')) {
    $coverageFilter->includeFile($coverageRoot . '/data_sources.php');
    $coverageFilter->includeFile($coverageRoot . '/data_templates.php');
    $coverageFilter->includeFile($coverageRoot . '/lib/functions.php');
}
if (defined('BASIC_AUTH_TEST_COVERAGE')) {
    $coverageFilter->includeFile($coverageRoot . '/include/auth.php');
    $coverageFilter->includeFile($coverageRoot . '/lib/auth.php');
    $coverageFilter->includeFile($coverageRoot . '/user_admin.php');
}
if (defined('MIB_CACHE_TEST_COVERAGE')) {
    $coverageFilter->includeFile($coverageRoot . '/lib/mib_cache.php');
}
$coverageFilter->includeFile($coverageRoot . '/lib/rrd.php');
$coverageFilter->includeFile($coverageRoot . '/src/Graphing/Infrastructure/Rrd/ProxyCipher.php');
$coverageFilter->includeFile($coverageRoot . '/lib/dsdebug.php');
$coverageFilter->includeFile($coverageRoot . '/lib/rrd_maintenance.php');
$coverageFilter->includeFile($coverageRoot . '/lib/poller.php');
$coverageFilter->includeFile($coverageRoot . '/lib/boost.php');
$coverageFilter->includeFile($coverageRoot . '/lib/api_data_source.php');
$coverageFilter->includeFile($coverageRoot . '/lib/rrdcheck.php');
$coverageFilter->includeFile($coverageRoot . '/lib/dsstats.php');
if (defined('RRD_TEST_INSTALLER_COVERAGE')) {
    $coverageFilter->includeFile($coverageRoot . '/lib/installer.php');
    $coverageFilter->includeFile($coverageRoot . '/install/upgrades/1_1_6.php');
    $coverageFilter->includeFile($coverageRoot . '/install/upgrades/1_2_31.php');
}
if (defined('REPORT_SECURITY_TEST_COVERAGE')) {
    $coverageFilter->includeFile($coverageRoot . '/lib/auth.php');
    $coverageFilter->includeFile($coverageRoot . '/lib/html_reports.php');
    $coverageFilter->includeFile($coverageRoot . '/lib/reports.php');
}
if (defined('PROFILE_SECURITY_TEST_COVERAGE')) {
    $coverageFilter->includeFile($coverageRoot . '/auth_profile.php');
    $coverageFilter->includeFile($coverageRoot . '/auth_changepassword.php');
    $coverageFilter->includeFile($coverageRoot . '/auth_login.php');
    $coverageFilter->includeFile($coverageRoot . '/lib/html_utility.php');
}
if (defined('GRAPH_ITEM_EDITOR_TEST_COVERAGE')) {
    $coverageFilter->includeFile($coverageRoot . '/lib/graph_item_editor.php');
}
if (defined('RRD_TEST_CLI_COVERAGE_COPY')) {
    $coverageFilter->includeFile(RRD_TEST_CLI_COVERAGE_COPY);
}
if (defined('THEME_SELECTION_TEST_COVERAGE')) {
    $coverageFilter->includeFile($coverageRoot . '/lib/functions.php');
}
if (defined('MAILER_TEST_COVERAGE')) {
    $coverageFilter->includeFile($coverageRoot . '/lib/functions.php');
}
if (defined('PERMISSION_FILTER_TEST_COVERAGE')) {
    $coverageFilter->includeFile($coverageRoot . '/src/IdentityAccess/Infrastructure/Legacy/PermissionMutation.php');
    $coverageFilter->includeFile($coverageRoot . '/user_admin.php');
    $coverageFilter->includeFile($coverageRoot . '/user_group_admin.php');
    $coverageFilter->includeFile($coverageRoot . '/src/IdentityAccess/Infrastructure/Legacy/PermissionFilter.php');
    $coverageFilter->includeFile($coverageRoot . '/src/IdentityAccess/Infrastructure/Legacy/PermissionAssociations.php');
    $coverageFilter->includeFile($coverageRoot . '/lib/html.php');
}
if (defined('PERMISSION_REQUEST_TEST_COVERAGE')) {
    $coverageFilter->includeFile($coverageRoot . '/user_admin.php');
    $coverageFilter->includeFile($coverageRoot . '/user_group_admin.php');
    $coverageFilter->includeFile($coverageRoot . '/lib/html_utility.php');
    $coverageFilter->includeFile($coverageRoot . '/lib/functions.php');
    $coverageFilter->includeFile($coverageRoot . '/src/IdentityAccess/Infrastructure/Legacy/PermissionRequests.php');
}
if (defined('ADMIN_LIST_TEST_COVERAGE')) {
    $coverageFilter->includeFile($coverageRoot . '/user_admin.php');
    $coverageFilter->includeFile($coverageRoot . '/user_group_admin.php');
    $coverageFilter->includeFile($coverageRoot . '/lib/html.php');
    $coverageFilter->includeFile($coverageRoot . '/lib/html_form.php');
    $coverageFilter->includeFile($coverageRoot . '/lib/html_utility.php');
    $coverageFilter->includeFile($coverageRoot . '/src/IdentityAccess/Infrastructure/Legacy/PermissionTemplateGrid.php');
}
if (defined('REALM_RENDER_TEST_COVERAGE')) {
    $coverageFilter->includeFile($coverageRoot . '/user_admin.php');
    $coverageFilter->includeFile($coverageRoot . '/user_group_admin.php');
    $coverageFilter->includeFile($coverageRoot . '/src/IdentityAccess/Infrastructure/Legacy/PermissionRealms.php');
    $coverageFilter->includeFile($coverageRoot . '/lib/html.php');
    $coverageFilter->includeFile($coverageRoot . '/lib/html_form.php');
    $coverageFilter->includeFile($coverageRoot . '/lib/auth.php');
}
if (defined('INPUT_WHITELIST_TEST_COVERAGE')) {
    require_once $coverageRoot . '/tests/Helpers/NativeChildCoverageEvidence.php';
    $whitelistSources = array('composer.lock', 'tests/composer.lock', 'tests/Fixtures/rrd-process-coverage.php', 'tests/Helpers/NativeChildCoverageEvidence.php', 'tests/Unit/InputWhitelistNativeTest.php');
    foreach ($coverageFilter->files() as $file) {
        $source = $file === realpath(RRD_TEST_CLI_COVERAGE_COPY) ? RRD_TEST_CLI_COVERAGE_SOURCE : $file;
        $whitelistSources[] = substr($source, strlen($coverageRoot) + 1);
    }
    $whitelistCoverageEvidence = NativeChildCoverageEvidence::snapshot($coverageRoot, 'tests/Fixtures/input-whitelist-native.php', INPUT_WHITELIST_NATIVE_SCENARIO, $whitelistSources);
}
if (defined('DATA_INPUT_LIST_TEST_COVERAGE')) {
    require_once $coverageRoot . '/tests/Helpers/NativeChildCoverageEvidence.php';
    $listSources = ['composer.lock', 'tests/composer.lock', 'tests/Fixtures/rrd-process-coverage.php', 'tests/Helpers/NativeChildCoverageEvidence.php', 'tests/Symfony/DataInputStorageNativeTest.php'];
    foreach ($coverageFilter->files() as $file) {
        $source = $file === realpath(RRD_TEST_CLI_COVERAGE_COPY) ? RRD_TEST_CLI_COVERAGE_SOURCE : $file;
        $listSources[] = substr($source, strlen($coverageRoot) + 1);
    }
    $dataInputListEvidence = NativeChildCoverageEvidence::snapshot($coverageRoot, 'tests/Fixtures/data-input-list-native.php', DATA_INPUT_LIST_NATIVE_SCENARIO, $listSources);
}
if (defined('UTILITY_VIEW_TEST_COVERAGE') || defined('UTILITY_LOG_TEST_COVERAGE') || defined('HELPER_UNION_TEST_COVERAGE') || defined('STRING_PREDICATE_TEST_COVERAGE') || defined('PHP80_STRING_NATIVE_TEST_COVERAGE')) {
    require_once $coverageRoot . '/tests/Helpers/NativeChildCoverageEvidence.php';
    $nativeSources = array('composer.lock', 'tests/composer.lock', 'tests/Fixtures/rrd-process-coverage.php', 'tests/Helpers/NativeChildCoverageEvidence.php');
    foreach ($coverageFilter->files() as $file) {
        // Preserve copied-source attribution only after the existing byte identity check.
        $source = defined('RRD_TEST_CLI_COVERAGE_COPY') && $file === realpath(RRD_TEST_CLI_COVERAGE_COPY) ? RRD_TEST_CLI_COVERAGE_SOURCE : $file;
        $nativeSources[] = substr($source, strlen($coverageRoot) + 1);
    }
    $nativeScenario = $argv[1];
    if (defined('UTILITY_VIEW_TEST_COVERAGE')) {
        $nativeSources = array_merge($nativeSources, array('tests/Unit/UtilityViewNativeCoverageTest.php', 'include/global_constants.php', 'lib/html_form.php', 'lib/variables.php', 'lib/utility.php'));
        $nativeProducer = 'tests/Fixtures/utility-view-native.php';
    } elseif (defined('UTILITY_LOG_TEST_COVERAGE')) {
        $nativeSources[] = 'tests/Symfony/UtilityLogPersistenceTest.php';
        $nativeProducer = 'tests/Fixtures/utility-log-native.php';
    } elseif (defined('STRING_PREDICATE_TEST_COVERAGE')) {
        $nativeSources = array_merge($nativeSources, array('tests/Unit/Core/Helpers/StringPredicateNativeTest.php', 'include/global_constants.php', 'lib/html.php'));
        $nativeProducer = 'tests/Fixtures/string-predicates-native.php';
        $nativeScenario = 'native-string-predicates';
    } elseif (defined('PHP80_STRING_NATIVE_TEST_COVERAGE')) {
        $nativeSources = array_merge($nativeSources, array('tests/Unit/Core/Helpers/Php80StringNativeTest.php', 'include/global_constants.php'));
        $nativeProducer = 'tests/Fixtures/php80-string-native.php';
        $nativeScenario = 'native-string-selectors';
    } else {
        $nativeSources = array_merge($nativeSources, array('tests/Unit/Core/Helpers/HelperUnionNativeTest.php', 'src/Platform/Infrastructure/Legacy/LegacyComponentAutoloader.php'));
        $nativeProducer = 'tests/Fixtures/helper-union-native.php';
    }
    $nativeCoverageEvidence = NativeChildCoverageEvidence::snapshot($coverageRoot, $nativeProducer, $nativeScenario, $nativeSources);
}
$childCoverage = new SebastianBergmann\CodeCoverage\CodeCoverage(
    (new SebastianBergmann\CodeCoverage\Driver\Selector())->forLineCoverage($coverageFilter),
    $coverageFilter
);
$childCoverage->start('native RRD child ' . getmypid());
$childCoverageFile = RRD_TEST_COVERAGE_DIRECTORY . '/child-' . getmypid() . '.coverage';
register_shutdown_function(function () use ($childCoverage, $childCoverageFile) {
    // Append collection after application shutdown handlers so implicit pipe
    // close/drain is measured too, not just the main body of the child script.
    register_shutdown_function(function () use ($childCoverage, $childCoverageFile) {
        $childCoverage->stop();
        if (defined('RRD_TEST_CLI_COVERAGE_COPY')) {
            // Measure the real copied CLI, then map only its filename. Refuse
            // attribution unless every source byte (and thus line) is identical.
            $copyHash = hash_file('sha256', RRD_TEST_CLI_COVERAGE_COPY);
            $sourceHash = hash_file('sha256', RRD_TEST_CLI_COVERAGE_SOURCE);
            if ($copyHash === false || $sourceHash === false || !hash_equals($sourceHash, $copyHash)) {
                throw new RuntimeException('Copied CLI changed while measuring coverage');
            }
            // Coverage records canonical paths; macOS temporary directories may
            // use /var aliases for /private/var. Map the actual measured names.
            $childCoverage->getData(true)->renameFile(realpath(RRD_TEST_CLI_COVERAGE_COPY), realpath(RRD_TEST_CLI_COVERAGE_SOURCE));
            $childCoverage->filter()->excludeFile(RRD_TEST_CLI_COVERAGE_COPY);
            $childCoverage->filter()->includeFile(RRD_TEST_CLI_COVERAGE_SOURCE);
        }
        $serializedCoverage = serialize($childCoverage);
        if (file_put_contents($childCoverageFile, $serializedCoverage) !== strlen($serializedCoverage)) {
            throw new RuntimeException('Unable to preserve child process coverage');
        }
        if (isset($GLOBALS['whitelistCoverageEvidence'])) {
            if (!defined('INPUT_WHITELIST_NATIVE_COMPLETED')) {
                throw new RuntimeException('Whitelist CLI completion evidence missing');
            }
            NativeChildCoverageEvidence::write($childCoverageFile, dirname(__DIR__, 2), $GLOBALS['whitelistCoverageEvidence'], INPUT_WHITELIST_NATIVE_COMPLETED);
        }
        if (isset($GLOBALS['dataInputListEvidence'])) {
            if (!defined('DATA_INPUT_LIST_NATIVE_COMPLETED')) {
                throw new RuntimeException('List worker completion evidence missing');
            }
            NativeChildCoverageEvidence::write($childCoverageFile, dirname(__DIR__, 2), $GLOBALS['dataInputListEvidence'], DATA_INPUT_LIST_NATIVE_COMPLETED);
        }
        if (isset($GLOBALS['nativeChildCoverageSnapshot'])) {
            NativeChildCoverageEvidence::write($childCoverageFile, dirname(__DIR__, 2), $GLOBALS['nativeChildCoverageSnapshot'], $GLOBALS['nativeChildCoverageMarkers'] ?? array());
        }
        if (isset($GLOBALS['nativeCoverageEvidence'])) {
            if (!defined('NATIVE_COVERAGE_COMPLETED')) {
                throw new RuntimeException('Native coverage production scenario did not complete.');
            }
            NativeChildCoverageEvidence::write($childCoverageFile, dirname(__DIR__, 2), $GLOBALS['nativeCoverageEvidence'], NATIVE_COVERAGE_COMPLETED);
        }
        if (defined('CSRF_ROTATION_TEST_COVERAGE')) {
            require_once dirname(__DIR__) . '/Helpers/CsrfRotationCoverage.php';
            CsrfRotationCoverage::record($childCoverageFile, dirname(__DIR__, 2));
        }
    });
});
