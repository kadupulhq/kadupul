<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

namespace Kadupul\Tests;

use Kadupul\GraphDefinition\Domain\CdefFunctions;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

final class CdefTranslationsTest extends TestCase
{
    public function testFrenchCatalogueCoversFormChoicesAndStaticCdefValidationFailures(): void
    {
        $root = dirname(__DIR__, 2);
        $messages = Yaml::parseFile($root . '/config/translations/graph_definition.fr.yaml');
        $required = ['Title format', ...array_values(CdefFunctions::TYPES), ...array_values(CdefFunctions::DATA_SOURCES)];
        $source = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root . '/src/GraphDefinition'));
        foreach ($source as $file) {
            if (!$file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }
            preg_match_all("/throw new \\\\InvalidArgumentException\\('([^']+)'\\)/", file_get_contents($file->getPathname()), $matches);
            $required = [...$required, ...$matches[1]];
        }
        foreach (array_unique($required) as $key) {
            self::assertArrayHasKey($key, $messages, 'Missing French CDEF catalogue key: ' . $key);
            self::assertNotSame($key, $messages[$key]);
            self::assertNotSame('', $messages[$key]);
        }
    }

    public function testInheritedEntryPointRetainsBothProjectCopyrightNotices(): void
    {
        $source = file_get_contents(dirname(__DIR__, 2) . '/cdef.php');
        self::assertStringContainsString('SPDX-FileCopyrightText: 2004-2026 The Cacti Group', $source);
        self::assertStringContainsString('SPDX-FileCopyrightText: 2026 The Kadupul project and contributors', $source);
    }
}
