<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Kadupul\ModelRegistry\Domain;

final class Model
{
    /**
     * @param list<ModelDependency> $dependencies
     * @param list<string> $capabilities
     */
    public function __construct(
        private readonly ModelIdentity $identity,
        private readonly ModelRevision $revision,
        private readonly array $dependencies,
        private readonly array $capabilities,
        private readonly Provenance $provenance,
        private readonly Checksum $checksum,
    ) {
        if ($revision->family() !== $identity->family) {
            throw new InvalidModelDefinition(sprintf('Revision %s does not belong to the %s family of %s.', $revision->value(), $identity->family->value, $identity->name));
        }
        $seen = [];
        foreach ($dependencies as $dependency) {
            $key = $dependency->identity->key();
            if ($dependency->identity->equals($identity)) {
                throw new InvalidModelDefinition(sprintf('Model %s cannot depend on itself.', $identity->key()));
            }
            if (isset($seen[$key])) {
                throw new InvalidModelDefinition(sprintf('Model %s lists dependency %s twice.', $identity->key(), $key));
            }
            $seen[$key] = true;
        }
        foreach ($capabilities as $capability) {
            if (preg_match('/\A[A-Za-z0-9][A-Za-z0-9._:\/-]*\z/', $capability) !== 1) {
                throw new InvalidModelDefinition(sprintf('Invalid capability name "%s" on model %s.', $capability, $identity->key()));
            }
        }
    }

    public function identity(): ModelIdentity
    {
        return $this->identity;
    }

    public function revision(): ModelRevision
    {
        return $this->revision;
    }

    /** @return list<ModelDependency> */
    public function dependencies(): array
    {
        return $this->dependencies;
    }

    /** @return list<string> */
    public function capabilities(): array
    {
        return $this->capabilities;
    }

    public function provenance(): Provenance
    {
        return $this->provenance;
    }

    public function checksum(): Checksum
    {
        return $this->checksum;
    }

    /** Human-readable reference used in messages, e.g. yang|urn:x|x@2024-01-15. */
    public function reference(): string
    {
        return $this->identity->key() . '@' . $this->revision->value();
    }
}
