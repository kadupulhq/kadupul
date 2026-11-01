<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\IdentityAccess\Domain;

/** The kinds of item a user can be given access to, with the type ids user_auth_perms stores. */
enum ItemType: int
{
    case Graph = 1;
    case Tree = 2;
    case Host = 3;
    case GraphTemplate = 4;

    /** The name the command line takes: --item-type=graph_template. */
    public function option(): string
    {
        return match ($this) {
            self::Graph => 'graph',
            self::Tree => 'tree',
            self::Host => 'host',
            self::GraphTemplate => 'graph_template',
        };
    }

    /**
     * @return ?self null when the name is not one of the option names
     */
    public static function fromOption(string $name): ?self
    {
        return array_find(self::cases(), static fn(self $type): bool => $type->option() === $name);
    }

    /** How the original named the type in "Invalid ... item id" errors. */
    public function label(): string
    {
        return match ($this) {
            self::Graph => 'Graph',
            self::Tree => 'Tree',
            self::Host => 'Host',
            self::GraphTemplate => 'Graph Template',
        };
    }
}
