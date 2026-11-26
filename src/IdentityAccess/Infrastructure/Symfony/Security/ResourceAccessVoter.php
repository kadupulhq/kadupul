<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\IdentityAccess\Infrastructure\Symfony\Security;

use Kadupul\IdentityAccess\Contract\ResourceAccess;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * Symfony Security Voter delegating tree and report ownership checks to
 * IdentityAccess's ResourceAccess contract.
 *
 * @extends Voter<string, int>
 */
final class ResourceAccessVoter extends Voter
{
    public const string MANAGE_TREE = 'KADUPUL_MANAGE_TREE';
    public const string MANAGE_REPORT = 'KADUPUL_MANAGE_REPORT';

    public function __construct(
        private readonly ResourceAccess $resourceAccess,
    ) {}

    #[\Override]
    public function supportsAttribute(string $attribute): bool
    {
        return $attribute === self::MANAGE_TREE || $attribute === self::MANAGE_REPORT;
    }

    #[\Override]
    protected function supports(string $attribute, mixed $subject): bool
    {
        return $this->supportsAttribute($attribute) && is_int($subject);
    }

    #[\Override]
    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        $user = $token->getUser();
        if (!$user instanceof SessionIdentity) {
            return false;
        }

        return match ($attribute) {
            self::MANAGE_TREE => $this->resourceAccess->canManageTree($user->actor->id, $subject),
            self::MANAGE_REPORT => $this->resourceAccess->canManageReport($user->actor->id, $subject),
            default => false,
        };
    }
}
