<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Tests;

use Kadupul\Inventory\Application\Query\InventoryAccessDenied;
use Kadupul\Inventory\Domain\DeviceEditConflict;
use Kadupul\Inventory\Infrastructure\Legacy\DeviceAssignmentProcess;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class DeviceAssignmentProcessTest extends TestCase
{
    public static function outcomes(): iterable
    {
        foreach (['collector', 'template', 'associations'] as $kind) {
            foreach ([
                ['ok', 0, null],
                ['ok', 1, \RuntimeException::class],
                ['conflict', 1, DeviceEditConflict::class],
                ['denied', 1, InventoryAccessDenied::class],
                ['invalid', 1, \InvalidArgumentException::class],
                ['failed', 1, \RuntimeException::class],
                ['unknown', 0, \RuntimeException::class],
            ] as [$status, $exit, $error]) {
                yield $kind . '-' . $status . '-' . $exit => [$kind, $status, $exit, $error];
            }
        }
    }

    #[DataProvider('outcomes')]
    public function testWorkerStatusAndPayloadContract(string $kind, string $status, int $exit, ?string $error): void
    {
        $directory = sys_get_temp_dir() . '/assignment-protocol-' . bin2hex(random_bytes(8));
        mkdir($directory . '/bin', 0700, true);
        $file = $directory . '/bin/legacy-device-' . $kind . '.php';
        $command = ['actor' => 2, 'id' => 7, $kind . '_id' => 3, 'revision' => 'fixture'];
        $response = 'KADUPUL_' . strtoupper($kind) . '_RESULT=' . json_encode(['status' => $status]);
        $stub = '<?php $input = json_decode(stream_get_contents(STDIN), true);'
            . ' if ($input !== ' . var_export($command, true) . ') { exit(9); }'
            . ' echo ' . var_export($response, true) . '; exit(' . $exit . ');';
        file_put_contents($file, $stub);
        try {
            $database = new \PDO('sqlite::memory:');
            $database->exec('CREATE TABLE settings (name TEXT, value TEXT)');
            $database->prepare('INSERT INTO settings VALUES (?, ?)')->execute(['path_php_binary', PHP_BINARY]);
            if ($error !== null) {
                $this->expectException($error);
            }
            if ($status === 'invalid') {
                $this->expectExceptionMessage('Select a valid device ' . ($kind === 'associations' ? 'association' : $kind) . '.');
            }
            DeviceAssignmentProcess::run($database, $directory, $kind, $command);
            self::assertNull($error);
        } finally {
            unlink($file);
            rmdir($directory . '/bin');
            rmdir($directory);
        }
    }

    public function testUnknownWorkerIsRejectedBeforeExecution(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        DeviceAssignmentProcess::run(new \PDO('sqlite::memory:'), '/unused', '../other', []);
    }
}
