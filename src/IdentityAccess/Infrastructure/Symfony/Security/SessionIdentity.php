<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\IdentityAccess\Infrastructure\Symfony\Security;

use Kadupul\IdentityAccess\Contract\Actor;
use Symfony\Component\DependencyInjection\Attribute\Exclude;
use Symfony\Component\Security\Core\User\UserInterface;

/** Request identity only: application policies still decide realm/resource access. */
#[Exclude]
final readonly class SessionIdentity implements UserInterface
{
    public function __construct(public Actor $actor) {}

    public function getUserIdentifier(): string
    {
        return (string) $this->actor->id;
    }

    public function getRoles(): array
    {
        return [];
    }

    #[\Deprecated]
    public function eraseCredentials(): void {}
}
