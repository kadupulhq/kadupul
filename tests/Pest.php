<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

/*
 * Pest bootstrap.
 */

require_once __DIR__ . '/Helpers/PestCodeCoverageCompatibility.php';

uses(PHPUnit\Framework\TestCase::class, PestCodeCoverageCompatibility::class)->in('Unit', 'integration', 'mutation', 'handoff');
