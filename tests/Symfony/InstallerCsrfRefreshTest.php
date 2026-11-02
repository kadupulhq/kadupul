<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

namespace Kadupul\Tests;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

final class InstallerCsrfRefreshTest extends TestCase
{
    public function testExpiredNativeTokenRefreshesOnceBeforeDispatchAndPreservesSessionState(): void
    {
        $directory = sys_get_temp_dir() . '/installer-csrf-refresh-' . bin2hex(random_bytes(12));
        $filesystem = new Filesystem();
        $filesystem->mkdir($directory, 0700);
        $program = <<<'PHP'
define('IN_CACTI_INSTALL', true);
define('KADUPUL_INSTALLER_WEB', true);
session_save_path($argv[2]);
session_id('installer-refresh-fixture-session');
session_start();
$original = session_id();
$_SESSION['fixture_selection'] = 'retain';
$_COOKIE = ['PHPSESSID' => $original];
$_SERVER['REQUEST_METHOD'] = 'POST';
$_SERVER['HTTP_X_REQUESTED_WITH'] = 'XMLHttpRequest';
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
$_POST = ['__csrf_magic' => 'sid:expired,1'];
$config = ['include_path' => $argv[1] . '/include', 'base_path' => $argv[1], 'is_web' => true, 'url_path' => '/'];
$dispatched = false;
register_shutdown_function(static function () use (&$dispatched, $original): void {
    $_POST['__csrf_magic'] = csrf_get_tokens();
    print "\nEVIDENCE=" . json_encode(['status' => http_response_code(), 'valid' => csrf_check(false),
        'preserved' => ($_SESSION['fixture_selection'] ?? null) === 'retain',
        'regenerated' => session_id() !== $original, 'dispatched' => $dispatched], JSON_THROW_ON_ERROR);
});
require $argv[1] . '/include/csrf.php';
$dispatched = true;
PHP;
        try {
            $process = new Process([PHP_BINARY, '-r', $program, dirname(__DIR__, 2), $directory]);
            $this->assertSame(0, $process->run(), $process->getErrorOutput());
            $this->assertSame('', $process->getErrorOutput());
            [$response, $evidence] = explode("\nEVIDENCE=", $process->getOutput(), 2);
            $body = json_decode($response, true, flags: JSON_THROW_ON_ERROR);
            $state = json_decode($evidence, true, flags: JSON_THROW_ON_ERROR);
            $this->assertSame('csrf_expired', $body['error']);
            $this->assertIsString($body['token']);
            $this->assertSame(['status' => 403, 'valid' => true, 'preserved' => true,
                'regenerated' => true, 'dispatched' => false], $state);
        } finally {
            $filesystem->remove($directory);
        }
    }
}
