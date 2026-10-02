<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

final class CdefNativeCoverageRegistration
{
    public static function cases(): array
    {
        return [
            'regeneration' => ['aggregate_generation_native_probe.php', 'PASS native aggregate regeneration and cleanup complete', ['lib/api_aggregate.php','lib/aggregate.php','lib/reference_write.php']],
            'branches' => ['aggregate_generation_branches_native_probe.php', 'PASS native aggregate admitted branch matrix complete', ['lib/api_aggregate.php', 'lib/aggregate.php', 'lib/api_graph.php']],
            'outer' => ['aggregate_outer_caller_native_probe.php', 'PASS native aggregate outer callers and cleanup complete', ['lib/api_aggregate.php','lib/aggregate.php','lib/api_graph.php']],
            'contract' => ['cdef_reference_contract_native_probe.php', 'PASS native contract and cleanup complete', ['src/Platform/Infrastructure/Legacy/CdefReferenceContract.php']],
            'version' => ['cdef_reference_version_native_probe.php', 'PASS native version confirmation and cleanup complete', ['lib/installer.php']],
            'delete' => ['cdef_reference_legacy_delete_native_probe.php', 'PASS native legacy deletion and cleanup complete', ['src/GraphDefinition/Infrastructure/Legacy/LegacyCdefDeletion.php']],
            'copy' => ['cdef_reference_copy_native_probe.php', 'PASS native production copy and cleanup complete', ['lib/cdef.php']],
            'import' => ['cdef_reference_callers_native_probe.php', 'PASS native import callers and cleanup complete', ['lib/import.php']],
        ];
    }

    public static function measured(): array
    {
        return ['lib/cdef.php', 'lib/import.php', 'lib/api_aggregate.php','lib/aggregate.php','lib/api_graph.php','lib/reference_write.php',
            'lib/installer.php','lib/cdef_reference.php','lib/functions.php',
            'src/Platform/Infrastructure/Legacy/CdefReferenceContract.php',
            'src/Platform/Infrastructure/Legacy/CdefReferenceReadiness.php',
            'src/Platform/Infrastructure/Legacy/CdefReferenceTriggers.php',
            'src/Platform/Infrastructure/Legacy/CdefReferenceReadinessProcedure.php',
            'src/GraphDefinition/Infrastructure/Legacy/LegacyCdefDeletion.php',
            'src/Platform/Infrastructure/Legacy/LegacyReferenceWriteTransaction.php'];
    }

    public static function sources(): array
    {
        return array_merge(self::measured(), ['composer.lock','cacti.sql','include/cacti_version',
            'tests/Helpers/CdefNativeCoverageRegistration.php','tests/Helpers/NativeChildCoverageEvidence.php',
            'tests/Fixtures/cdef-native-coverage.php','tests/Symfony/CdefNativeProbeCoverageTest.php',
            'tests/Fixtures/aggregate-percentile-original.php','tests/security/cdef_reference_installer_native_probe.php','tests/Helpers/PhpSource.php',
            'aggregate_graphs.php','aggregate_templates.php','color_templates.php','lib/html_utility.php','lib/html_validate.php','lib/html.php','lib/html_form.php','lib/headers_secure.php',
            'src/Platform/Contract/CdefReferenceReadiness.php','lib/database.php','lib/variables.php','lib/graph_template_input.php','lib/plugins.php','lib/auth.php',
            'include/global_constants.php','include/global_arrays.php','include/global_form.php',
            'include/global_languages.php','lib/boost.php']);
    }

    public static function runtimeMarker(): string
    {
        $collector = (new ReflectionClass(SebastianBergmann\CodeCoverage\CodeCoverage::class))->getFileName();
        $collectorHash = is_string($collector) ? hash_file('sha256', $collector) : false;
        $binaryHash = hash_file('sha256', PHP_BINARY);
        $pcov = ini_get('extension_dir') . '/pcov.so';
        $moduleHash = is_file($pcov) ? hash_file('sha256', $pcov) : false;
        if ($binaryHash === false || $collectorHash === false || $moduleHash === false || !extension_loaded('pcov')) {
            throw new RuntimeException('The actual native PHP/PCOV/coverage runtime could not be bound.');
        }
        return 'runtime:' . PHP_VERSION . ':pcov:' . phpversion('pcov') . ':binary:' . $binaryHash
            . ':module:' . $moduleHash . ':collector:' . $collectorHash;
    }
}
