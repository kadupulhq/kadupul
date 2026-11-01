<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Tests;

use Kadupul\Platform\Infrastructure\Legacy\InstallerRuntimeComparison;
use Kadupul\Platform\Infrastructure\Legacy\InstallerRuntimeSnapshot;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;

final class InstallerRuntimeComparisonTest extends TestCase
{
    private string $directory;
    private string $secret;
    private string|false $oldSecret;
    private mixed $oldEnvironment;
    private bool $hadEnvironment;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/installer-comparison-' . bin2hex(random_bytes(12));
        $this->secret = bin2hex(random_bytes(32));
        $this->oldSecret = getenv('APP_SECRET', true);
        $this->hadEnvironment = array_key_exists('APP_SECRET', $_ENV);
        $this->oldEnvironment = $_ENV['APP_SECRET'] ?? null;
        $filesystem = new Filesystem();
        $filesystem->mkdir([$this->directory . '/bin', $this->directory . '/src/Platform/Infrastructure/Legacy']);
        $filesystem->mkdir(array_map(fn(string $path): string => $this->directory . '/' . $path, InstallerRuntimeSnapshot::DIRECTORIES));
        $root = dirname(__DIR__, 2);
        copy($root . '/bin/installer-runtime.php', $this->directory . '/bin/installer-runtime.php');
        copy($root . '/src/Platform/Infrastructure/Legacy/InstallerRuntimeSnapshot.php', $this->directory . '/src/Platform/Infrastructure/Legacy/InstallerRuntimeSnapshot.php');
        file_put_contents($this->directory . '/composer.json', '{"require":{"php":">=8.4","ext-json":"*"}}');
        putenv('APP_SECRET=' . $this->secret);
    }

    protected function tearDown(): void
    {
        putenv($this->oldSecret === false ? 'APP_SECRET' : 'APP_SECRET=' . $this->oldSecret);
        if ($this->hadEnvironment) {
            $_ENV['APP_SECRET'] = $this->oldEnvironment;
        } else {
            unset($_ENV['APP_SECRET']);
        }
        (new Filesystem())->remove($this->directory);
    }

    public function testActualChildConfirmsPrivateEvidenceWithoutWritingOrExportingIt(): void
    {
        $before = $this->files();
        $checks = (new InstallerRuntimeComparison($this->directory, $this->secret))->compare(PHP_BINARY);
        self::assertCount(6, $checks);
        self::assertNotContains(false, array_column($checks, 'ok'));
        self::assertSame('verified', $checks[5]['status']);
        self::assertSame($before, $this->files());
        $this->assertRedacted($checks);
    }

    public function testSymfonyEnvironmentOverlayCannotMaskMissingOrDifferentInheritedSecret(): void
    {
        foreach ([false, 'private-worker-secret-' . str_repeat('x', 40)] as $secret) {
            putenv($secret === false ? 'APP_SECRET' : 'APP_SECRET=' . $secret);
            $_ENV['APP_SECRET'] = $this->secret;
            $checks = (new InstallerRuntimeComparison($this->directory, $this->secret))->compare(PHP_BINARY);
            self::assertSame('Shared application secret', $checks[0]['name']);
            self::assertFalse($checks[0]['ok']);
            $this->assertRedacted($checks);
        }
    }

    public function testActualProductionManifestAndExtensionProducerFormatsAreAccepted(): void
    {
        copy(dirname(__DIR__, 2) . '/composer.json', $this->directory . '/composer.json');
        $checks = (new InstallerRuntimeComparison($this->directory, $this->secret))->compare(PHP_BINARY);
        self::assertCount(6, $checks);
        self::assertSame('Shared application secret', $checks[5]['name']);
        self::assertTrue($checks[5]['ok']);
        $this->assertRedacted($checks);
    }

    public function testMissingWebSecretNeverLaunchesEvenAnInvalidInterpreter(): void
    {
        $checks = (new InstallerRuntimeComparison($this->directory, 'short'))->compare('/missing/private-interpreter');
        self::assertSame('Shared application secret', $checks[0]['name']);
        self::assertFalse($checks[0]['ok']);
        $this->assertRedacted($checks);
    }

    public function testMalformedAndOversizedChildOutputRemainUnconfirmedAndPrivate(): void
    {
        foreach (['<?php echo "private-child-output";', '<?php echo str_repeat("private-child-output", 4000);'] as $source) {
            file_put_contents($this->directory . '/bin/installer-runtime.php', $source);
            $checks = (new InstallerRuntimeComparison($this->directory, $this->secret))->compare(PHP_BINARY);
            self::assertSame('Runtime comparison', $checks[0]['name']);
            self::assertFalse($checks[0]['ok']);
            $this->assertRedacted($checks);
        }
    }

    public function testFixedEntrypointCannotBeReplacedByASymlink(): void
    {
        $entry = $this->directory . '/bin/installer-runtime.php';
        unlink($entry);
        foreach (['/bin/other.php', '/other.php'] as $relative) {
            $target = $this->directory . $relative;
            file_put_contents($target, '<?php echo "private-child-output";');
            symlink($target, $entry);
            try {
                $checks = (new InstallerRuntimeComparison($this->directory, $this->secret))->compare(PHP_BINARY);
                self::assertFalse($checks[0]['ok']);
                $this->assertRedacted($checks);
            } finally {
                unlink($entry);
            }
        }
    }

    public function testChildTimeoutIsBoundedAndDoesNotBecomeASuccess(): void
    {
        file_put_contents($this->directory . '/bin/installer-runtime.php', '<?php sleep(20);');
        $start = microtime(true);
        $checks = (new InstallerRuntimeComparison($this->directory, $this->secret))->compare(PHP_BINARY);
        self::assertLessThan(8.0, microtime(true) - $start);
        self::assertFalse($checks[0]['ok']);
        $this->assertRedacted($checks);
    }

    public function testSupportedVersionAccountAndPermissionDifferencesAreAdvisory(): void
    {
        $web = InstallerRuntimeSnapshot::capture($this->directory);
        $worker = $web;
        $worker['php'] = '8.4.99';
        $worker['version_id'] = 80499;
        $worker['extensions']['json'] = '8.4.99';
        $worker['uid'] = 123456;
        $worker['directories']['log']['writable'] = !$web['directories']['log']['writable'];
        $nonce = bin2hex(random_bytes(32));
        $method = new \ReflectionMethod(InstallerRuntimeComparison::class, 'assess');
        $checks = $method->invoke(
            new InstallerRuntimeComparison($this->directory, $this->secret),
            $web,
            $worker,
            InstallerRuntimeSnapshot::proof($worker, $nonce, $this->secret),
            $nonce
        );
        self::assertSame(['advisory', 'advisory', 'advisory', 'advisory', 'verified', 'verified'], array_column($checks, 'status'));
        self::assertNotContains(false, array_column($checks, 'ok'));
        self::assertStringContainsString('json:', $checks[1]['value']);
        $this->assertRedacted($checks);
    }

    public function testIdenticalMissingDirectoriesCannotBecomeVerifiedAccess(): void
    {
        foreach (['log', 'cache/boost'] as $relative) {
            $path = $this->directory . '/' . $relative;
            rmdir($path);
            try {
                $checks = (new InstallerRuntimeComparison($this->directory, $this->secret))->compare(PHP_BINARY);
                self::assertFalse($checks[3]['ok']);
                self::assertSame('action_required', $checks[3]['status']);
                self::assertSame('action_required', $checks[4]['status']);
                self::assertSame('verified', $checks[5]['status']);
            } finally {
                mkdir($path);
            }
        }
    }

    public function testAnExtensionMissingFromBothRuntimesRemainsActionable(): void
    {
        file_put_contents($this->directory . '/composer.json', '{"require":{"ext-json":"*","ext-kadupul_missing_fixture":"*"}}');
        $checks = (new InstallerRuntimeComparison($this->directory, $this->secret))->compare(PHP_BINARY);
        self::assertFalse($checks[1]['ok']);
        self::assertSame('action_required', $checks[1]['status']);
        self::assertStringContainsString('kadupul_missing_fixture: missing / missing', $checks[1]['value']);
    }

    public function testTamperedSnapshotShapeIsRejectedBeforeAnyEvidenceIsPublished(): void
    {
        $source = '<?php echo json_encode(["protocol"=>1,"snapshot"=>["php"=>"private-child-output"],"proof"=>str_repeat("a",64)]);';
        file_put_contents($this->directory . '/bin/installer-runtime.php', $source);
        $checks = (new InstallerRuntimeComparison($this->directory, $this->secret))->compare(PHP_BINARY);
        self::assertFalse($checks[0]['ok']);
        self::assertSame('not_assessed', $checks[0]['status']);
        $this->assertRedacted($checks);
    }

    private function files(): array
    {
        $files = [];
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->directory, \FilesystemIterator::SKIP_DOTS)) as $file) {
            $files[$file->getPathname()] = hash_file('sha256', $file->getPathname());
        }
        ksort($files);
        return $files;
    }

    private function assertRedacted(array $checks): void
    {
        $json = json_encode($checks, JSON_THROW_ON_ERROR);
        foreach ([$this->secret, $this->directory, 'private-child-output', 'private-worker-secret', '"uid"', '"gid"', '"proof"', '"snapshot"'] as $private) {
            self::assertStringNotContainsString($private, $json);
        }
        self::assertDoesNotMatchRegularExpression('/[a-f0-9]{64}/', $json);
    }
}
