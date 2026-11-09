<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

/*
 * Pest bootstrap.
 */

require_once __DIR__ . '/Helpers/PestCodeCoverageCompatibility.php';

// Legacy functions extracted into tests can delegate to dependency-free
// Graphing domain classes; load those from src/ as the application does.
spl_autoload_register(static function (string $class): void {
    $prefix = 'Kadupul\\Graphing\\Domain\\';
    if (str_starts_with($class, $prefix) && preg_match('/\A[A-Za-z0-9_]+\z/D', substr($class, strlen($prefix))) === 1) {
        $file = dirname(__DIR__) . '/src/Graphing/Domain/' . substr($class, strlen($prefix)) . '.php';
        if (is_file($file)) {
            require_once $file;
        }
    }
});

uses(PHPUnit\Framework\TestCase::class, PestCodeCoverageCompatibility::class)->in('Unit', 'integration', 'mutation', 'handoff');
