<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Kadupul\Tests\Graphing;

use Kadupul\Graphing\Application\Port\StorageRejected;
use Kadupul\Graphing\Infrastructure\Rrd\ConfiguredRootTargetLocator;
use Kadupul\Graphing\Infrastructure\Rrd\InvalidRrdConfiguration;
use Kadupul\Monitoring\Contract\StorageReference;
use Kadupul\Telemetry\Contract\RejectionReason;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ConfiguredRootTargetLocatorTest extends TestCase
{
    private TemporaryRrdRoot $root;
    private TemporaryRrdRoot $outside;

    protected function setUp(): void
    {
        $this->root = TemporaryRrdRoot::create();
        $this->outside = TemporaryRrdRoot::create();
    }

    protected function tearDown(): void
    {
        $this->root->remove();
        $this->outside->remove();
    }

    public function testAReferenceMapsToTheRealPathOfItsFileInsideTheRoot(): void
    {
        $file = $this->root->write('42/traffic_7.rrd', 'rrd');

        $target = $this->locator([7 => '42/traffic_7.rrd'])->locate(new StorageReference('local-data:7'));

        self::assertSame($file, $target->path);
    }

    public function testASymlinkThatStaysInsideTheRootIsFollowed(): void
    {
        $file = $this->root->write('real/traffic_7.rrd', 'rrd');
        $this->root->symlink($file, 'alias_7.rrd');

        self::assertSame($file, $this->locator([7 => 'alias_7.rrd'])->locate(new StorageReference('local-data:7'))->path);
    }

    /** @return iterable<string, array{string}> */
    public static function references(): iterable
    {
        yield 'unknown id' => ['local-data:8'];
        yield 'other scheme' => ['graph:7'];
        yield 'non-numeric id' => ['local-data:abc'];
        yield 'zero id' => ['local-data:0'];
    }

    #[DataProvider('references')]
    public function testAReferenceWithoutAConfiguredFileIsTargetMissing(string $reference): void
    {
        $this->root->write('traffic_7.rrd', 'rrd');

        $this->assertRefused(RejectionReason::TargetMissing, [7 => 'traffic_7.rrd'], $reference);
    }

    public function testAConfiguredButAbsentFileIsTargetMissing(): void
    {
        $this->assertRefused(RejectionReason::TargetMissing, [7 => 'gone_7.rrd']);
    }

    /** @return iterable<string, array{string}> */
    public static function unsafePaths(): iterable
    {
        yield 'traversal' => ['../escape.rrd'];
        yield 'nested traversal' => ['42/../../escape.rrd'];
        yield 'absolute' => ['/etc/escape.rrd'];
        yield 'not rrd' => ['traffic_7.txt'];
        yield 'NUL' => ["traffic_7.rrd\0.rrd"];
        yield 'backslash' => ['42\\traffic_7.rrd'];
        yield 'empty segment' => ['42//traffic_7.rrd'];
        yield 'dot segment' => ['./traffic_7.rrd'];
        yield 'empty' => [''];
    }

    #[DataProvider('unsafePaths')]
    public function testAConfiguredPathThatIsNotAPlainRelativeRrdPathIsRefused(string $relative): void
    {
        $this->root->write('traffic_7.rrd', 'rrd');
        $this->root->write('traffic_7.txt', 'rrd');

        $this->assertRefused(RejectionReason::StorageFailure, [7 => $relative]);
    }

    public function testASymlinkedFileEscapingTheRootIsRefused(): void
    {
        $this->root->symlink($this->outside->write('secret.rrd', 'rrd'), 'traffic_7.rrd');

        $this->assertRefused(RejectionReason::StorageFailure, [7 => 'traffic_7.rrd']);
    }

    public function testASymlinkedDirectoryEscapingTheRootIsRefused(): void
    {
        $this->outside->write('data/traffic_7.rrd', 'rrd');
        $this->root->symlink($this->outside->path . '/data', 'devices');

        $this->assertRefused(RejectionReason::StorageFailure, [7 => 'devices/traffic_7.rrd']);
    }

    public function testASymlinkInsideTheRootToANonRrdFileIsRefused(): void
    {
        $this->root->symlink($this->root->write('notes.txt', 'text'), 'traffic_7.rrd');

        $this->assertRefused(RejectionReason::StorageFailure, [7 => 'traffic_7.rrd']);
    }

    public function testADirectoryNamedLikeAnRrdFileIsRefused(): void
    {
        $this->root->directory('traffic_7.rrd');

        $this->assertRefused(RejectionReason::StorageFailure, [7 => 'traffic_7.rrd']);
    }

    public function testADanglingSymlinkIsTargetMissing(): void
    {
        $this->root->symlink($this->outside->path . '/never.rrd', 'traffic_7.rrd');

        $this->assertRefused(RejectionReason::TargetMissing, [7 => 'traffic_7.rrd']);
    }

    /** @return iterable<string, array{string}> */
    public static function badRoots(): iterable
    {
        yield 'relative' => ['rra'];
        yield 'empty' => [''];
        yield 'filesystem root' => ['/'];
        yield 'NUL' => ["/tmp\0"];
    }

    #[DataProvider('badRoots')]
    public function testTheRootMustBeAnAbsoluteDirectoryOtherThanTheFilesystemRoot(string $root): void
    {
        $this->expectException(InvalidRrdConfiguration::class);

        new ConfiguredRootTargetLocator($root, []);
    }

    public function testAMissingRootOrAFileAsRootIsRefusedAtConstruction(): void
    {
        $file = $this->root->write('plain.rrd', 'rrd');
        foreach ([$this->root->path . '/absent', $file] as $root) {
            try {
                new ConfiguredRootTargetLocator($root, []);
                self::fail('A root that is not an existing directory was accepted.');
            } catch (InvalidRrdConfiguration $e) {
                self::assertStringContainsString('existing directory', $e->getMessage());
            }
        }
    }

    /** @param array<int, string> $paths */
    private function locator(array $paths): ConfiguredRootTargetLocator
    {
        return new ConfiguredRootTargetLocator($this->root->path, $paths);
    }

    /** @param array<int, string> $paths */
    private function assertRefused(RejectionReason $reason, array $paths, string $reference = 'local-data:7'): void
    {
        try {
            $this->locator($paths)->locate(new StorageReference($reference));
            self::fail('The reference was located.');
        } catch (StorageRejected $e) {
            self::assertSame($reason, $e->reason, $e->getMessage());
            self::assertStringNotContainsString('/', $e->getMessage());
        }
    }
}
