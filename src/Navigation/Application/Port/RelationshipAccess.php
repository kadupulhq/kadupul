<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2004-2026 The Cacti Group
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

namespace Kadupul\Navigation\Application\Port;

use Kadupul\Navigation\Contract\Relationships\RelationshipScreen;

/** Decides whether the signed-in user may view a relationship list. */
interface RelationshipAccess
{
    /**
     * Checks access to the screen's parent list.
     *
     * @throws \Kadupul\Navigation\Application\Query\AdministrationListDenied when there is no user or the user lacks access
     */
    public function authorize(RelationshipScreen $screen): void;
}
