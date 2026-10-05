<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

require_once __DIR__ . '/ManagementBulkCoverageRegistration.php';

final class ManagementListCoverageRegistration
{
    public const SOURCES = [...ManagementBulkCoverageRegistration::SOURCES,
        'tests/Fixtures/management-list-native.php', 'tests/Helpers/ManagementListCoverageRegistration.php',
        'lib/graph_data_removal.php', 'src/Platform/Infrastructure/Legacy/LegacyReferenceWriteTransaction.php'];
    public const MARKERS = ['native-policy-operation-returned', 'policy-session-observed', 'management-list-rendered', 'management-list-scope-observed'];
}
