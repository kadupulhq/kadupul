<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Kadupul\Monitoring\Domain;

/**
 * Ties one logical series (device, metric, resource, protocol) to one
 * storage field. It records where samples would go; it does not decide who
 * may write there, which is the writer ownership's job.
 */
final readonly class MetricBinding
{
    public function __construct(
        public BindingKey $key,
        public string $packName,
        public string $packVersion,
        public StorageField $field,
        public RrdDataSourceType $expectedType,
        public BindingStatus $status,
        public BindingProvenance $provenance,
        public ?\DateTimeImmutable $lastVerifiedAt,
    ) {
        if (preg_match('/^[a-z][a-z0-9]*(-[a-z0-9]+)*$/D', $packName) !== 1) {
            throw new InvalidBinding(sprintf('Pack name "%s" is not a pack name.', $packName));
        }
        try {
            SemanticVersion::parse($packVersion);
        } catch (\InvalidArgumentException $e) {
            throw new InvalidBinding($e->getMessage(), 0, $e);
        }
    }

    /** Same target and same expectations; status, provenance and verification are lifecycle, not definition. */
    public function definitionEquals(self $other): bool
    {
        return $this->key->equals($other->key)
            && $this->packName === $other->packName
            && $this->packVersion === $other->packVersion
            && $this->field->equals($other->field)
            && $this->expectedType === $other->expectedType;
    }

    public function withStatus(BindingStatus $next): self
    {
        if (!$this->status->canMoveTo($next)) {
            throw new BindingTransitionRejected(sprintf('Binding %s cannot move from %s to %s.', $this->key->bindingId(), $this->status->value, $next->value));
        }

        return new self($this->key, $this->packName, $this->packVersion, $this->field, $this->expectedType, $next, $this->provenance, $this->lastVerifiedAt);
    }
}
