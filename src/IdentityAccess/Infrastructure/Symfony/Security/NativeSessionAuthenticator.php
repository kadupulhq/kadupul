<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\IdentityAccess\Infrastructure\Symfony\Security;

use Kadupul\IdentityAccess\Application\Query\CurrentActor;
use Kadupul\IdentityAccess\Contract\AuthenticatedAccess;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Core\Exception\BadCredentialsException;
use Symfony\Component\Security\Http\Authenticator\AbstractAuthenticator;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Passport;
use Symfony\Component\Security\Http\Authenticator\Passport\SelfValidatingPassport;

final class NativeSessionAuthenticator extends AbstractAuthenticator
{
    public function __construct(
        private readonly NativeSessionRoutes $routes,
        private readonly AuthenticatedAccess $access,
        private readonly CurrentActor $currentActor,
    ) {}

    public function supports(Request $request): bool
    {
        return $this->routes->matches($request);
    }

    public function authenticate(Request $request): Passport
    {
        // /session retains console admission; only About may restore a Basic
        // or remembered login. Both adapters recheck the native credential.
        $actor = $request->attributes->get('_route') === 'session'
            ? ($this->currentActor)()
            : $this->access->authenticatedActor();
        if ($actor === null) {
            throw new BadCredentialsException('Authentication required.');
        }
        $identity = new SessionIdentity($actor);

        return new SelfValidatingPassport(new UserBadge($identity->getUserIdentifier(), static fn(): SessionIdentity => $identity));
    }

    public function onAuthenticationSuccess(Request $request, TokenInterface $token, string $firewallName): ?Response
    {
        return null;
    }

    public function onAuthenticationFailure(Request $request, AuthenticationException $exception): ?Response
    {
        // Keep each route's existing localized HTML or JSON refusal contract.
        return null;
    }
}
