<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\IdentityAccess\Application\Port;

use Kadupul\IdentityAccess\Domain\AuthDomain;
use Kadupul\IdentityAccess\Domain\LdapDirectoryConfig;

/**
 * Read port for configured LDAP / Active Directory login domains and global LDAP settings.
 */
interface AuthDomainRepository
{
    public function findEnabledById(int $domainId): ?AuthDomain;

    public function findDefaultEnabled(): ?AuthDomain;

    public function findGlobalLdapConfig(): ?LdapDirectoryConfig;
}
