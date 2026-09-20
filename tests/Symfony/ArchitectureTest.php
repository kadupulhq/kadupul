<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Tests;

use PHPUnit\Framework\TestCase;

final class ArchitectureTest extends TestCase
{
    public function testModuleDependenciesPointInwardAndCrossOnlyContracts(): void
    {
        $root = dirname(__DIR__, 2) . '/src/';
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root));
        foreach ($files as $file) {
            if ($file->getExtension() !== 'php' || $file->getFilename() === 'Kernel.php') {
                continue;
            }
            $relative = substr($file->getPathname(), strlen($root));
            [$module, $layer] = explode('/', $relative);
            foreach (token_get_all(file_get_contents($file->getPathname())) as $token) {
                if (!is_array($token)) {
                    continue;
                }
                [$kind, $value] = $token;
                if ($layer !== 'Infrastructure') {
                    self::assertNotContains($kind, [T_REQUIRE, T_REQUIRE_ONCE, T_INCLUDE, T_INCLUDE_ONCE, T_GLOBAL], $relative);
                    if ($kind === T_VARIABLE) {
                        self::assertNotContains($value, ['$_SESSION', '$_SERVER', '$_GET', '$_POST', '$GLOBALS'], $relative);
                    }
                    if ($kind === T_STRING) {
                        self::assertDoesNotMatchRegularExpression('/^(db_|session_|get_allowed_)/', $value, $relative);
                    }
                }
                if (!in_array($kind, [T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true)) {
                    continue;
                }
                $name = ltrim($value, '\\');
                if (str_starts_with($name, 'Kadupul\\')) {
                    $parts = explode('\\', $name);
                    $targetModule = $parts[1];
                    $targetLayer = $parts[2] ?? '';
                    if (in_array($layer, ['Domain', 'Application'], true)) {
                        self::assertNotSame('Platform', $targetModule, $relative . ' imports technical integration API ' . $name);
                    }
                    if ($targetModule !== $module) {
                        self::assertSame('Contract', $targetLayer, $relative . ' imports ' . $name);
                    } elseif ($layer === 'Domain') {
                        self::assertSame('Domain', $targetLayer, $relative . ' imports ' . $name);
                    } elseif (in_array($layer, ['Application', 'Contract'], true)) {
                        self::assertNotSame('Infrastructure', $targetLayer, $relative . ' imports ' . $name);
                    }
                } elseif ($layer !== 'Infrastructure' && str_contains($name, '\\')) {
                    self::fail($relative . ' depends on external implementation ' . $name);
                }
            }
        }
    }

    public function testSymfonyEntryPointsDoNotBootstrapLegacyApplication(): void
    {
        foreach (['app.php', 'public/index.php'] as $file) {
            $source = file_get_contents(dirname(__DIR__, 2) . '/' . $file);
            self::assertStringNotContainsString('include/auth.php', $source);
            self::assertStringNotContainsString('include/global.php', $source);
        }
    }
}
