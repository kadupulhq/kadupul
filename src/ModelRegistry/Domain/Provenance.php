<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Kadupul\ModelRegistry\Domain;

/**
 * Records where a model came from and when it was retrieved.
 */
final class Provenance
{
    /**
     * Validates the source and origin URI.
     *
     * @throws InvalidModelDefinition when the source is blank or the origin URI is not absolute
     */
    public function __construct(private readonly string $source, private readonly \DateTimeImmutable $retrievedAt, private readonly ?string $originUri)
    {
        if (trim($source) === '') {
            throw new InvalidModelDefinition('Model provenance needs a source.');
        }
        if ($originUri !== null && preg_match('/\A[A-Za-z][A-Za-z0-9+.-]*:\/\/[^\s]+\z/', $originUri) !== 1) {
            throw new InvalidModelDefinition(sprintf('Model origin URI "%s" is not an absolute URI.', $originUri));
        }
    }

    /**
     * Returns the name of the source.
     */
    public function source(): string
    {
        return $this->source;
    }

    /**
     * Returns when the model was retrieved.
     */
    public function retrievedAt(): \DateTimeImmutable
    {
        return $this->retrievedAt;
    }

    /**
     * Returns the absolute URI the model was fetched from, or null when unknown.
     */
    public function originUri(): ?string
    {
        return $this->originUri;
    }
}
