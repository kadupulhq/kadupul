<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2004-2026 The Cacti Group
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

namespace Kadupul\Navigation\Infrastructure\Persistence;

use Kadupul\Navigation\Application\Port\AdministrationListAccess;
use Kadupul\Navigation\Application\Port\RelationshipAccess;
use Kadupul\Navigation\Contract\Relationships\RelationshipScreen;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

/** Allows a relationship list to whoever may view its parent list. */
#[AsAlias(RelationshipAccess::class)]
final readonly class ParentRelationshipAccess implements RelationshipAccess
{
    /** Wires the administration list access check. */
    public function __construct(private AdministrationListAccess $parents) {}

    /** Delegates to the check for the screen's parent list. */
    public function authorize(RelationshipScreen $screen): void
    {
        $this->parents->authorize($screen->parentScreen());
    }
}
