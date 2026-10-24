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

    /**
     * Refuses to overwrite $stored unless this binding follows from it. A
     * store shared by several processes checks this inside its write, so a
     * caller holding a stale copy cannot undo another caller's rejection or
     * swap the definition of a binding that is still live. A new definition
     * is accepted only as a fresh proposal over a rejected one, which is how
     * RegisterBinding replaces it.
     */
    public function assertMayReplace(self $stored): void
    {
        $id = $stored->key->bindingId();
        if (!$this->key->equals($stored->key)) {
            throw new InvalidBinding(sprintf('Binding %s cannot replace binding %s; the keys differ.', $this->key->bindingId(), $id));
        }
        if ($this->definitionEquals($stored)) {
            if ($this->status !== $stored->status && !$stored->status->canMoveTo($this->status)) {
                throw new BindingTransitionRejected(sprintf('Binding %s is stored as %s and cannot become %s; reload it before changing it.', $id, $stored->status->value, $this->status->value));
            }

            return;
        }
        if ($stored->status !== BindingStatus::Rejected || $this->status !== BindingStatus::Proposed) {
            throw new BindingConflict(sprintf('Binding %s is stored as %s with a different definition; reject it before proposing another.', $id, $stored->status->value));
        }
    }

    public function withStatus(BindingStatus $next): self
    {
        if (!$this->status->canMoveTo($next)) {
            throw new BindingTransitionRejected(sprintf('Binding %s cannot move from %s to %s.', $this->key->bindingId(), $this->status->value, $next->value));
        }

        return new self($this->key, $this->packName, $this->packVersion, $this->field, $this->expectedType, $next, $this->provenance, $this->lastVerifiedAt);
    }
}
