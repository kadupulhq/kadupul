<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Tests;

use Doctrine\DBAL\Connection;
use Kadupul\Kernel;
use PHPUnit\Framework\TestCase;

final class DoctrineConnectionPlatformTest extends TestCase
{
    /**
     * Without a configured charset, DoctrineBundle's ConnectionFactory asks
     * the driver for a platform using an empty server version, which DBAL
     * reads as MySQL < 8 and reports as a deprecation on every boot.
     */
    public function testBuildingTheConnectionsDoesNotProbeAnUnknownPlatformVersion(): void
    {
        $messages = [];
        $previous = set_error_handler(static function (int $level, string $message) use (&$messages): bool {
            if ($level === E_USER_DEPRECATED) {
                $messages[] = $message;

                return true;
            }

            return false;
        });
        $kernel = new Kernel('test', true);
        $kernel->boot();
        try {
            $container = $kernel->getContainer()->get('test.service_container');
            foreach (['local', 'main', 'web'] as $name) {
                $connection = $container->get('doctrine.dbal.' . $name . '_connection');
                self::assertInstanceOf(Connection::class, $connection);
                self::assertSame('utf8mb4', $connection->getParams()['charset'], $name);
            }
        } finally {
            $kernel->shutdown();
            set_error_handler($previous);
        }
        self::assertSame([], array_values(array_filter($messages, static fn(string $m): bool => str_contains($m, 'MySQL < 8'))));
    }
}
