<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Kadupul\Tests\Monitoring;

use Kadupul\Monitoring\Domain\InvalidPack;
use Kadupul\Monitoring\Domain\PackValidator;
use Kadupul\Monitoring\Infrastructure\Pack\JsonPackReader;
use Kadupul\Monitoring\Infrastructure\Pack\PackDirectoryUnreadable;
use PHPUnit\Framework\TestCase;

final class JsonPackReaderTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $directory = sys_get_temp_dir() . '/kadupul-packs-' . bin2hex(random_bytes(6));
        mkdir($directory);
        // The reader reports resolved paths; on macOS the temporary directory is behind a symlink.
        $this->directory = (string) realpath($directory);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->directory . '/*') ?: [] as $file) {
            unlink($file);
        }
        rmdir($this->directory);
    }

    public function testTheShippedExampleDirectoryYieldsTheExamplePack(): void
    {
        $documents = (new JsonPackReader())->readDirectory(dirname(PackDocuments::EXAMPLE));

        $path = realpath(PackDocuments::EXAMPLE);
        self::assertSame([$path], array_keys($documents));
        $pack = (new PackValidator('1.2.35'))->parse($documents[$path], $path);
        self::assertSame('openconfig-interfaces', $pack->name);
    }

    public function testOnlyPackFilesAreReadInNameOrder(): void
    {
        $this->write('b.pack.json', '{"name": "b"}');
        $this->write('a.pack.json', '{"name": "a"}');
        $this->write('a.pack.yaml', 'name: a');
        $this->write('notes.json', '{}');

        $documents = (new JsonPackReader())->readDirectory($this->directory);

        self::assertSame([$this->directory . '/a.pack.json', $this->directory . '/b.pack.json'], array_keys($documents));
        self::assertInstanceOf(\stdClass::class, $documents[$this->directory . '/a.pack.json']);
    }

    public function testEmptyObjectsAndArraysStayDistinct(): void
    {
        $this->write('p.pack.json', '{"capabilities": {}, "metrics": []}');

        $document = (new JsonPackReader())->readFile($this->directory . '/p.pack.json');

        self::assertInstanceOf(\stdClass::class, $document->capabilities);
        self::assertSame([], $document->metrics);
    }

    public function testIntegersBeyondPhpIntMaxAreNotTurnedIntoFloats(): void
    {
        $this->write('p.pack.json', '{"collection": {"interval_seconds": {"min": 18446744073709551616}}}');

        $document = (new JsonPackReader())->readFile($this->directory . '/p.pack.json');

        self::assertSame('18446744073709551616', $document->collection->interval_seconds->min);
    }

    public function testMalformedJsonFailsWithTheFileNamed(): void
    {
        $this->write('broken.pack.json', '{"name": "x",}');

        try {
            (new JsonPackReader())->readDirectory($this->directory);
            self::fail('Malformed JSON was accepted.');
        } catch (InvalidPack $e) {
            self::assertSame($this->directory . '/broken.pack.json', $e->source);
            self::assertStringContainsString('not valid JSON', $e->getMessage());
        }
    }

    public function testAMissingDirectoryIsReported(): void
    {
        $this->expectException(PackDirectoryUnreadable::class);
        $this->expectExceptionMessage($this->directory . '/missing');

        (new JsonPackReader())->readDirectory($this->directory . '/missing');
    }

    private function write(string $name, string $content): void
    {
        file_put_contents($this->directory . '/' . $name, $content);
    }
}
