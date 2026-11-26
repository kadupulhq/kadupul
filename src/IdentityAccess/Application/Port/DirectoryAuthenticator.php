<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\IdentityAccess\Application\Port;

use Kadupul\IdentityAccess\Domain\LdapDirectoryConfig;

/**
 * Outbound port for authenticating credentials against an LDAP / Active Directory server.
 */
interface DirectoryAuthenticator
{
    public function authenticate(
        LdapDirectoryConfig $config,
        string $username,
        #[\SensitiveParameter]
        string $password,
    ): DirectoryAuthenticationResult;
}
