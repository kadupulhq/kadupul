<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Tests\Fixtures;

use Kadupul\IdentityAccess\Application\Port\ItemPermissions;
use Kadupul\IdentityAccess\Contract\OperatorDatabase;
use Kadupul\IdentityAccess\Domain\ItemType;

/** ItemPermissions over a list of user ids and "type:id" items, recording each grant. */
final class InMemoryItemPermissions implements ItemPermissions
{
    /** @var list<string> */
    public array $grants = [];

    /**
     * @param list<int> $users
     * @param list<string> $items items as "<type value>:<id>"
     */
    public function __construct(private readonly array $users = [7], private readonly array $items = ['3:5', '1:9'], private readonly bool $result = true) {}

    #[\Override]
    public function userExists(OperatorDatabase $database, int $userId): bool
    {
        return in_array($userId, $this->users, true);
    }

    #[\Override]
    public function itemExists(OperatorDatabase $database, ItemType $type, int $itemId): bool
    {
        return in_array($type->value . ':' . $itemId, $this->items, true);
    }

    #[\Override]
    public function grant(OperatorDatabase $database, int $userId, ItemType $type, int $itemId): bool
    {
        $this->grants[] = $database->name . ':' . $userId . ':' . $type->value . ':' . $itemId;

        return $this->result;
    }
}
