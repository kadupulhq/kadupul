<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Kadupul\ModelRegistry\Domain;

final class Provenance
{
    public function __construct(private readonly string $source, private readonly \DateTimeImmutable $retrievedAt, private readonly ?string $originUri)
    {
        if (trim($source) === '') {
            throw new InvalidModelDefinition('Model provenance needs a source.');
        }
        if ($originUri !== null && preg_match('/\A[A-Za-z][A-Za-z0-9+.-]*:\/\/[^\s]+\z/', $originUri) !== 1) {
            throw new InvalidModelDefinition(sprintf('Model origin URI "%s" is not an absolute URI.', $originUri));
        }
    }

    public function source(): string
    {
        return $this->source;
    }

    public function retrievedAt(): \DateTimeImmutable
    {
        return $this->retrievedAt;
    }

    public function originUri(): ?string
    {
        return $this->originUri;
    }
}
