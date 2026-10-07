<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

// Trusted notification boundary: record handoff, never instantiate a mail transport.
return new class {
    public function boot(): void
    {
        $GLOBALS['notificationLifecycle'][] = 'boot';
    }

    public function getContainer(): object
    {
        return new class {
            public function get(string $service): object
            {
                if ($service !== \Kadupul\Alerting\Infrastructure\Legacy\AdministratorNotificationBridge::class) {
                    throw new RuntimeException('Unexpected notification service');
                }
                $GLOBALS['notificationLifecycle'][] = 'bridge';
                return new class {
                    public function send(string $subject, string $html): void
                    {
                        $GLOBALS['notificationCalls'][] = [$subject, $html];
                    }
                };
            }
        };
    }

    public function shutdown(): void
    {
        $GLOBALS['notificationLifecycle'][] = 'shutdown';
    }
};
