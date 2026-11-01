<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Graphing\Application\Command;

use Kadupul\IdentityAccess\Contract\OperatorDatabase;

/** The outcome of creating a tree; $treeId is the new tree, or the existing one for EXISTS. */
final readonly class TreeCreation
{
    public const string CREATED = 'created';
    public const string EXISTS = 'already_exists';
    public const string FAILED = 'failed';

    public function __construct(public OperatorDatabase $database, public string $status, public ?int $treeId = null) {}

    /** Whether a tree was created; an existing tree of that name is not a success. */
    public function succeeded(): bool
    {
        return $this->status === self::CREATED;
    }
}
