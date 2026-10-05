<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

final class DataSourceControllerCoverageRegistration
{
    public const SOURCES = ['data_sources.php','tests/Helpers/DataSourceControllerNativeHarness.php','tests/Helpers/DataSourceControllerCoverageRegistration.php','tests/Fixtures/device-graph-caller-native.php','tests/Unit/Security/Auth/DataSourceControllerNativeTest.php','lib/auth.php','lib/html_utility.php','lib/html.php','lib/functions.php','lib/api_device.php','lib/graph_template_input.php','lib/graph_data_removal.php','src/Platform/Infrastructure/Legacy/LegacyReferenceWriteTransaction.php','src/Inventory/Infrastructure/Legacy/LegacyDeviceSiteWriter.php','tests/Helpers/PestCodeCoverageCompatibility.php','graphs_new.php','host.php','graphs.php','lib/template.php','lib/html_graph.php','include/global_constants.php','include/vendor/csrf/csrf-magic.php','include/vendor/csrf/csrf-conf.php','tests/Helpers/PhpSource.php','tests/Helpers/NativeChildCoverageEvidence.php','tests/Helpers/DeviceGraphCallerNativeHarness.php','tests/Helpers/DeviceGraphCallerCoverageRegistration.php','tests/Fixtures/rrd-process-coverage.php','tests/Unit/Security/Auth/DeviceGraphCallerNativeTest.php','composer.lock','tests/composer.lock','cacti.sql','lib/rrd.php','src/Graphing/Infrastructure/Rrd/ProxyCipher.php','lib/dsdebug.php','lib/rrd_maintenance.php','lib/poller.php','lib/boost.php','lib/api_data_source.php','lib/rrdcheck.php','lib/dsstats.php'];
    public const MARKERS = ['data-source-outcome-observed', 'data-source-persisted-state-observed'];
}
