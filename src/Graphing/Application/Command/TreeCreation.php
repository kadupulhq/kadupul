<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Graphing\Application\Command;

use Kadupul\IdentityAccess\Contract\OperatorDatabase;

final readonly class TreeCreation
{
    public const string CREATED = 'created';
    public const string EXISTS = 'already_exists';
    public const string FAILED = 'failed';

    public function __construct(public OperatorDatabase $database, public string $status, public ?int $treeId = null) {}

    public function succeeded(): bool
    {
        return $this->status === self::CREATED;
    }
}
