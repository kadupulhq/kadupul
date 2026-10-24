<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Kadupul\ModelRegistry\Domain;

final class MissingDependency extends \DomainException
{
    public function __construct(private readonly ModelIdentity $identity, string $requiredBy)
    {
        parent::__construct(sprintf('Model %s requires %s, which is not registered.', $requiredBy, $identity->key()));
    }

    public function identity(): ModelIdentity
    {
        return $this->identity;
    }
}
