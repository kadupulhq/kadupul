<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Tests;

use Kadupul\Inventory\Infrastructure\Legacy\DeviceAssociationWriter;
use PDO;
use PHPUnit\Framework\TestCase;

final class DeviceAssociationCommitTest extends TestCase
{
    public function testPrimaryCommitPrecedesRemoteAndFailuresCannotPublishSuccess(): void
    {
        foreach (['success', 'primary-false', 'primary-throw', 'remote-false', 'remote-throw'] as $failure) {
            $events = [];
            $primary = $this->createMock(PDO::class);
            $remote = $this->createMock(PDO::class);
            foreach (['primary' => $primary, 'remote' => $remote] as $name => $db) {
                $db->method('inTransaction')->willReturn(true);
                $db->method('commit')->willReturnCallback(static function () use (&$events, $name, $failure): bool {
                    $events[] = $name;
                    if ($failure === $name . '-throw') {
                        throw new \RuntimeException('driver failure');
                    }
                    return $failure !== $name . '-false';
                });
            }
            $error = null;
            try {
                (new DeviceAssociationWriter())->commit($primary, $remote);
            } catch (\RuntimeException $caught) {
                $error = $caught;
            }
            self::assertSame(str_starts_with($failure, 'primary') ? ['primary'] : ['primary', 'remote'], $events);
            self::assertSame($failure !== 'success', $error !== null);
        }
    }

    public function testLostTransactionStateRejectsCommitOnBothConnections(): void
    {
        foreach (['primary', 'remote'] as $missing) {
            $primary = $this->createMock(PDO::class);
            $remote = $this->createMock(PDO::class);
            foreach (['primary' => $primary, 'remote' => $remote] as $name => $db) {
                $db->method('inTransaction')->willReturn($name !== $missing);
                $db->expects(self::never())->method('commit');
            }
            try {
                (new DeviceAssociationWriter())->commit($primary, $remote);
                self::fail('Lost transaction state was accepted');
            } catch (\RuntimeException $error) {
                self::assertSame('Association transaction unavailable', $error->getMessage());
            }
        }
    }

    public function testLocalSuccessCommitsTheActualCallerTransaction(): void
    {
        $db = new PDO('sqlite::memory:');
        $db->exec('CREATE TABLE host_graph (host_id INTEGER,graph_template_id INTEGER)');
        $db->beginTransaction();
        $db->exec('INSERT INTO host_graph VALUES (7,9)');
        (new DeviceAssociationWriter())->commit($db, null);
        self::assertFalse($db->inTransaction());
        self::assertSame(1, (int) $db->query('SELECT COUNT(*) FROM host_graph')->fetchColumn());
    }
}
