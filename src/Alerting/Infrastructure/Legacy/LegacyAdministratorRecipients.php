<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Alerting\Infrastructure\Legacy;

use Kadupul\Alerting\Application\Port\AdministratorRecipients;
use Kadupul\Alerting\Domain\AdministratorRecipient;
use Kadupul\IdentityAccess\Contract\UserContacts;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

#[AsAlias(AdministratorRecipients::class)]
final readonly class LegacyAdministratorRecipients implements AdministratorRecipients
{
    public function __construct(private UserContacts $contacts) {}

    public function find(int $id): ?AdministratorRecipient
    {
        $contact = $this->contacts->find($id);

        return $contact === null ? null : new AdministratorRecipient($contact->email, $contact->name);
    }
}
