<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Extensions\Contract;

/**
 * Immutable extension hook event crossing bounded context boundaries.
 */
final readonly class HookEvent
{
    private const string HOOK_NAME_PATTERN = '/\A[a-z][a-z0-9_]{0,63}\z/D';

    /**
     * @param array<string, mixed> $context
     */
    public function __construct(
        public string $hook,
        public mixed $payload = null,
        public array $context = [],
    ) {
        if (preg_match(self::HOOK_NAME_PATTERN, $this->hook) !== 1) {
            throw new \InvalidArgumentException(sprintf('Invalid hook name "%s".', $this->hook));
        }
    }

    public function withPayload(mixed $payload): self
    {
        return new self($this->hook, $payload, $this->context);
    }
}
