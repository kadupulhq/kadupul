<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Graphing\Application\Port;

/** A tree node as the --list-nodes mode prints it: a header or site node counts as a header, as in the original. */
final readonly class TreeNodeRow
{
    public const string HEADER = 'header';
    public const string GRAPH = 'graph';
    public const string HOST = 'host';

    /**
     * @param string $parent "N/A" below the root, else the parent as the original printed it (the operator's text at the top level)
     * @param ?int $attribute the sort type of a header or the host grouping type of a device; null for a graph
     */
    public function __construct(public string $kind, public int $id, public string $parent, public string $title, public ?int $attribute) {}
}
