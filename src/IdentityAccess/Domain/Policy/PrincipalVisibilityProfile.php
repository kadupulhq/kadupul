<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\IdentityAccess\Domain\Policy;

/**
 * Snapshot of one principal's (user or enabled group) visibility rules across
 * graphs, devices, trees, and graph templates.
 */
final readonly class PrincipalVisibilityProfile
{
    public function __construct(
        public VisibilityRuleSet $graphs,
        public VisibilityRuleSet $devices,
        public VisibilityRuleSet $trees,
        public VisibilityRuleSet $graphTemplates,
    ) {}
}
