<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Kadupul\ModelRegistry\Domain;

/**
 * Names a model independent of its revision: family, namespace and name.
 */
final class ModelIdentity
{
    // The key joins parts with "|", so neither part may contain it.
    private const NAMESPACE_PATTERN = '/\A[A-Za-z0-9][A-Za-z0-9._:\/#+-]{0,254}\z/';
    private const NAME_PATTERN = '/\A[A-Za-z][A-Za-z0-9._-]{0,127}\z/';

    /**
     * Validates the namespace and name.
     *
     * @throws InvalidModelDefinition when either is malformed
     */
    public function __construct(public readonly ModelFamily $family, public readonly string $namespace, public readonly string $name)
    {
        if (preg_match(self::NAMESPACE_PATTERN, $namespace) !== 1) {
            throw new InvalidModelDefinition(sprintf('Invalid model namespace "%s".', $namespace));
        }
        if (preg_match(self::NAME_PATTERN, $name) !== 1) {
            throw new InvalidModelDefinition(sprintf('Invalid model name "%s".', $name));
        }
    }

    /**
     * Builds an identity from a family given as a string.
     *
     * @throws InvalidModelDefinition when the family, namespace or name is invalid
     */
    public static function parse(string $family, string $namespace, string $name): self
    {
        return new self(ModelFamily::fromString($family), $namespace, $name);
    }

    /**
     * Returns the model name.
     */
    public function name(): string
    {
        return $this->name;
    }

    /**
     * Returns the unique key, as family|namespace|name.
     */
    public function key(): string
    {
        return $this->family->value . '|' . $this->namespace . '|' . $this->name;
    }

    /**
     * Tells whether both identities have the same key.
     */
    public function equals(self $other): bool
    {
        return $this->key() === $other->key();
    }
}
