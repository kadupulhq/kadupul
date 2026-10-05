<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

require_once __DIR__ . '/ManagementListCoverageRegistration.php';

final class GraphDeviceChangeCoverageRegistration
{
    public const SOURCES = [...ManagementListCoverageRegistration::SOURCES,
        'lib/api_graph.php', 'lib/graph_template_input.php', 'tests/Fixtures/graph-device-change-native.php', 'tests/Helpers/GraphDeviceChangeCoverageRegistration.php'];
    public const MARKERS = ['native-policy-operation-returned', 'policy-session-observed', 'graph-device-child-scope-observed'];
}
