<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Kadupul\Tests\Monitoring\Fakes;

use Kadupul\Monitoring\Application\Port\BindingApprovalAuthorizer;
use Kadupul\Monitoring\Domain\BindingKey;

/** Grants exactly the actor ids it was given; IdentityAccess will answer this for real. */
final class FakeBindingApprovalAuthorizer implements BindingApprovalAuthorizer
{
    /** @var list<string> */
    public array $asked = [];

    /** @param list<string> $authorized */
    public function __construct(private readonly array $authorized) {}

    public function isAuthorized(string $actorId, BindingKey $key): bool
    {
        $this->asked[] = $actorId . ' ' . $key->bindingId();

        return in_array($actorId, $this->authorized, true);
    }
}
