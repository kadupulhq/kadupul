<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\IdentityAccess\Application\Port;

/**
 * Outcome categories returned by a DirectoryAuthenticator.
 * Public UI responses must map failure states to non-disclosing messages.
 */
enum DirectoryAuthenticationStatus: string
{
    case Authenticated = 'authenticated';
    case InvalidCredentials = 'invalid_credentials';
    case GroupMembershipDenied = 'group_membership_denied';
    case ConfigurationError = 'configuration_error';
    case Unavailable = 'unavailable';
}
