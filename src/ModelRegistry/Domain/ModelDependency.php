<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Kadupul\ModelRegistry\Domain;

/**
 * A requirement on another model identity, optionally with a minimum revision.
 */
final class ModelDependency
{
    /**
     * Validates that the minimum revision is of the same family as the dependency.
     *
     * @throws InvalidModelDefinition when the families differ
     */
    public function __construct(public readonly ModelIdentity $identity, public readonly ?ModelRevision $minimumRevision = null)
    {
        if ($minimumRevision !== null && $minimumRevision->family() !== $identity->family) {
            throw new InvalidModelDefinition(sprintf('Minimum revision %s is a %s revision but %s is a %s model.', $minimumRevision->value(), $minimumRevision->family()->value, $identity->name, $identity->family->value));
        }
    }
}
