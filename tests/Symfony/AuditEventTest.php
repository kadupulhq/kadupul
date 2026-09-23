<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Tests;

use Kadupul\IdentityAccess\Contract\AuditEvent;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class AuditEventTest extends TestCase
{
    public function testProducesClosedVersionedRecord(): void
    {
        $event = new AuditEvent(
            '0123456789abcdef0123456789abcdef',
            42,
            'inventory.device.edit',
            'device',
            '7',
            AuditEvent::ALLOWED,
            AuditEvent::SUCCEEDED,
            '2026-09-23T04:00:00Z',
        );

        self::assertSame([
            'schema' => 'kadupul.audit.v1',
            'recorded_at' => '2026-09-23T04:00:00Z',
            'correlation_id' => '0123456789abcdef0123456789abcdef',
            'actor' => ['id' => 42],
            'action' => 'inventory.device.edit',
            'target' => ['type' => 'device', 'id' => '7'],
            'decision' => 'allowed',
            'outcome' => 'succeeded',
        ], json_decode($event->json(), true, 8, JSON_THROW_ON_ERROR));
    }

    public static function invalidEvents(): array
    {
        $valid = ['0123456789abcdef0123456789abcdef', 42, 'inventory.device.edit', 'device', '7', 'allowed', 'succeeded', '2026-09-23T04:00:00Z'];
        return [
            'correlation' => [array_replace($valid, [0 => '../request'])],
            'actor' => [array_replace($valid, [1 => 0])],
            'action' => [array_replace($valid, [2 => 'device edit'])],
            'target type' => [array_replace($valid, [3 => 'Device/Secret'])],
            'target id' => [array_replace($valid, [4 => "7\nsecret"])],
            'decision' => [array_replace($valid, [5 => 'maybe'])],
            'outcome' => [array_replace($valid, [6 => 'unknown'])],
            'denied mismatch' => [array_replace($valid, [5 => 'denied'])],
            'allowed mismatch' => [array_replace($valid, [6 => 'denied'])],
            'timestamp' => [array_replace($valid, [7 => 'yesterday'])],
        ];
    }

    #[DataProvider('invalidEvents')]
    public function testRejectsValuesOutsideTheAuditSchema(array $arguments): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new AuditEvent(...$arguments);
    }
}
