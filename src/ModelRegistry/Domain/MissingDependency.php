<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Kadupul\ModelRegistry\Domain;

/**
 * Raised when a model depends on an identity that has no registered revision.
 */
final class MissingDependency extends \DomainException
{
    /**
     * Names the missing identity and the model that requires it.
     */
    public function __construct(private readonly ModelIdentity $identity, string $requiredBy)
    {
        parent::__construct(sprintf('Model %s requires %s, which is not registered.', $requiredBy, $identity->key()));
    }

    /**
     * Returns the identity that is not registered.
     */
    public function identity(): ModelIdentity
    {
        return $this->identity;
    }
}
