<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Tests\Fixtures;

use Kadupul\IdentityAccess\Application\Port\UserCopier;
use Kadupul\IdentityAccess\Contract\OperatorDatabase;

/** A UserCopier over a list of usernames, recording each copy it is asked for. */
final class InMemoryUserCopier implements UserCopier
{
    /** @var list<string> */
    public array $copies = [];

    /** @param list<string> $users */
    public function __construct(private array $users, private readonly bool $result = true, private readonly bool $creates = true) {}

    #[\Override]
    public function localUserExists(OperatorDatabase $database, string $username): bool
    {
        return in_array($username, $this->users, true);
    }

    #[\Override]
    public function copy(OperatorDatabase $database, string $template, string $new): bool
    {
        $this->copies[] = $database->name . ':' . $template . '>' . $new;
        if ($this->creates) {
            $this->users[] = $new;
        }

        return $this->result;
    }
}
