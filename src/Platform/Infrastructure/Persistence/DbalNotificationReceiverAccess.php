<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Platform\Infrastructure\Persistence;

use Kadupul\IdentityAccess\Contract\Actor;
use Kadupul\Platform\Application\Port\NotificationReceiverAccess;
use Kadupul\Platform\Application\Port\RrdCheckAccess;
use Kadupul\Platform\Application\Query\NotificationReceiverAccessDenied;
use Kadupul\Platform\Application\Query\RrdCheckAccessDenied;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

/**
 * managers.php and the RRD check share the Utilities realm (15), so the
 * receivers reuse that reviewed policy instead of a copy of it.
 */
#[AsAlias(NotificationReceiverAccess::class)]
final readonly class DbalNotificationReceiverAccess implements NotificationReceiverAccess
{
    public function __construct(private RrdCheckAccess $utilities) {}

    public function authorize(): Actor
    {
        try {
            return $this->utilities->authorize();
        } catch (RrdCheckAccessDenied $error) {
            throw new NotificationReceiverAccessDenied($error->unauthenticated);
        }
    }

    public function assertCurrent(int $actorId): void
    {
        try {
            $this->utilities->assertCurrent($actorId);
        } catch (RrdCheckAccessDenied $error) {
            throw new NotificationReceiverAccessDenied($error->unauthenticated);
        }
    }
}
