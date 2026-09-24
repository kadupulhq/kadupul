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
                    // A Domain/Application file may depend on its own module's other
                    // Domain/Application code (including its own namespace declaration,
                    // which tokenizes the same way as a use import) but never reaches
                    // Platform's Contract or Infrastructure layers, its own included.
                    $ownModuleUseCase = $targetModule === $module && in_array($targetLayer, ['Domain', 'Application'], true);
                    if (in_array($layer, ['Domain', 'Application'], true) && !$ownModuleUseCase) {
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

    /**
     * TableConversionStep sends DDL with no operator check and no audit. Only
     * ConvertTables, which adds both, and the installer's adapter may use it,
     * and only lib/installer.php may call that adapter, so a new command
     * cannot pick up unchecked DDL by injecting either one.
     */
    public function testOnlyTheInstallerConvertsTablesWithoutAnOperator(): void
    {
        $root = dirname(__DIR__, 2);
        $allowed = [
            'TableConversionStep' => [
                'src/Platform/Application/Command/TableConversionStep.php',
                'src/Platform/Application/Command/ConvertTables.php',
                'src/Platform/Infrastructure/Legacy/InstallerTableConversion.php',
                'config/services.yaml',
            ],
            'InstallerTableConversion' => [
                'src/Platform/Infrastructure/Legacy/InstallerTableConversion.php',
                'config/services.yaml',
                'lib/installer.php',
            ],
        ];
        // Tests may use both; the rest are dependencies, build output or state.
        $skipped = ['.git', '.superpowers', 'node_modules', 'tests', 'var', 'vendor'];
        $directories = new \RecursiveCallbackFilterIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
            static fn(\SplFileInfo $file): bool => !$file->isDir() || !in_array($file->getFilename(), $skipped, true),
        );
        $found = array_fill_keys(array_keys($allowed), []);
        foreach (new \RecursiveIteratorIterator($directories) as $file) {
            if (!in_array($file->getExtension(), ['php', 'yaml', 'yml'], true)) {
                continue;
            }
            $source = (string) file_get_contents($file->getPathname());
            foreach (array_keys($allowed) as $class) {
                if (str_contains($source, $class)) {
                    $found[$class][] = substr($file->getPathname(), strlen($root) + 1);
                }
            }
        }
        foreach ($allowed as $class => $files) {
            sort($files);
            sort($found[$class]);
            self::assertSame($files, $found[$class], $class);
        }
    }

    public function testSymfonyEntryPointsDoNotBootstrapLegacyApplication(): void
    {
        foreach (['app.php', 'public/index.php', 'sites.php'] as $file) {
            $source = file_get_contents(dirname(__DIR__, 2) . '/' . $file);
            self::assertStringNotContainsString('include/auth.php', $source);
            self::assertStringNotContainsString('include/global.php', $source);
        }
    }
}
