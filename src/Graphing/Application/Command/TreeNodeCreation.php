<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Graphing\Application\Command;

use Kadupul\IdentityAccess\Contract\OperatorDatabase;

final readonly class TreeNodeCreation
{
    public const string CREATED = 'created';
    public const string BAD_TREE = 'invalid_tree';
    public const string UNKNOWN_TREE = 'unknown_tree';
    public const string BAD_PARENT = 'invalid_parent';
    public const string UNKNOWN_PARENT = 'unknown_parent';
    public const string PARENT_NOT_HEADER = 'parent_not_header';
    public const string NAME_REQUIRED = 'name_required';
    public const string UNKNOWN_GRAPH = 'unknown_graph';
    public const string UNKNOWN_SITE = 'unknown_site';
    public const string UNKNOWN_DEVICE = 'unknown_device';
    public const string BAD_GROUPING = 'invalid_grouping';
    public const string FAILED = 'failed';

    /**
     * @param ?int $treeId the tree as the script read it, after the integer cast
     */
    public function __construct(public OperatorDatabase $database, public string $status, public ?int $treeId = null, public ?int $nodeId = null) {}

    public function succeeded(): bool
    {
        return $this->status === self::CREATED;
    }
}
