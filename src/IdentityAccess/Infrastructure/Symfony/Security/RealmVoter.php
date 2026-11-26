<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\IdentityAccess\Infrastructure\Symfony\Security;

use Kadupul\IdentityAccess\Application\Port\RealmAccessChecker;
use Kadupul\IdentityAccess\Domain\AuthRealm;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * Symfony Security Voter evaluating KADUPUL_REALM_<id> attributes against the
 * authenticated SessionIdentity using direct and enabled group realm grants.
 *
 * @extends Voter<string, mixed>
 */
final class RealmVoter extends Voter
{
    public function __construct(
        private readonly RealmAccessChecker $realms,
    ) {}

    #[\Override]
    public function supportsAttribute(string $attribute): bool
    {
        return AuthRealm::fromSecurityAttribute($attribute) !== null;
    }

    #[\Override]
    protected function supports(string $attribute, mixed $subject): bool
    {
        return $subject === null && AuthRealm::fromSecurityAttribute($attribute) !== null;
    }

    #[\Override]
    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        $user = $token->getUser();
        if (!$user instanceof SessionIdentity) {
            return false;
        }

        $realm = AuthRealm::fromSecurityAttribute($attribute);
        if ($realm === null) {
            return false;
        }

        // Direct UsersGroups (realm 1) never grants system admin via group membership alone.
        $includeGroups = $realm !== AuthRealm::UsersGroups;

        return $this->realms->hasRealm($user->actor->id, $realm->value, $includeGroups);
    }
}
