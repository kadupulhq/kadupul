<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Kadupul\ModelRegistry\Domain;

/**
 * What is known about one device's support for one model revision.
 * Instances are immutable; each change returns a new association.
 */
final class DeviceModelAssociation
{
    /**
     * Keeps the association fields; callers use the named constructors.
     */
    private function __construct(
        private readonly int $deviceId,
        private readonly ModelIdentity $identity,
        private readonly ModelRevision $revision,
        private readonly AssociationState $state,
        private readonly ?VerificationRecord $verification,
    ) {}

    /**
     * Starts an association in the unknown state.
     */
    public static function unknown(int $deviceId, ModelIdentity $identity, ModelRevision $revision): self
    {
        return new self($deviceId, $identity, $revision, AssociationState::Unknown, null);
    }

    /**
     * Starts an association for a device that has just advertised the revision.
     */
    public static function discovered(int $deviceId, ModelIdentity $identity, ModelRevision $revision): self
    {
        return self::unknown($deviceId, $identity, $revision)->rediscover();
    }

    /**
     * Returns the device id.
     */
    public function deviceId(): int
    {
        return $this->deviceId;
    }

    /**
     * Returns the identity of the associated model.
     */
    public function identity(): ModelIdentity
    {
        return $this->identity;
    }

    /**
     * Returns the associated model revision.
     */
    public function revision(): ModelRevision
    {
        return $this->revision;
    }

    /**
     * Returns the current state of the association.
     */
    public function state(): AssociationState
    {
        return $this->state;
    }

    /**
     * Returns the verification record, or null unless the state is verified or partial.
     */
    public function verification(): ?VerificationRecord
    {
        return $this->verification;
    }

    /** @return list<string> */
    public function supportedPaths(): array
    {
        return $this->verification?->supportedPaths() ?? [];
    }

    /**
     * A repeat of the device's capability advertisement. It is not evidence that
     * a verified, partial or explicitly unsupported finding changed, so it only
     * moves an unknown association forward.
     */
    public function rediscover(): self
    {
        return $this->state === AssociationState::Unknown
            ? new self($this->deviceId, $this->identity, $this->revision, AssociationState::Advertised, null)
            : $this;
    }

    /**
     * Applies a verification finding; the state becomes verified or partial according to the record.
     *
     * @throws InvalidAssociationTransition when the current state does not allow it
     */
    public function verify(VerificationRecord $record): self
    {
        return $this->transitionTo($record->resultingState(), $record);
    }

    /**
     * Records that the device does not support the revision.
     *
     * @throws InvalidAssociationTransition when the current state does not allow it
     */
    public function markUnsupported(): self
    {
        return $this->transitionTo(AssociationState::Unsupported, null);
    }

    /**
     * Moves to the given state, enforcing the allowed transitions and that verified and partial states carry a matching record.
     *
     * @throws InvalidAssociationTransition when the move is not allowed or the record is missing or does not match
     */
    public function transitionTo(AssociationState $next, ?VerificationRecord $record): self
    {
        if (!$this->state->mayBecome($next)) {
            throw new InvalidAssociationTransition(sprintf('Device %d cannot move %s@%s from %s to %s.', $this->deviceId, $this->identity->key(), $this->revision->value(), $this->state->value, $next->value));
        }
        if ($next->needsVerification() && $record === null) {
            throw new InvalidAssociationTransition(sprintf('Device %d cannot move %s@%s from %s to %s without a verification record.', $this->deviceId, $this->identity->key(), $this->revision->value(), $this->state->value, $next->value));
        }
        if ($next->needsVerification() && $record?->resultingState() !== $next) {
            throw new InvalidAssociationTransition(sprintf('The verification record describes %s, not %s.', $record?->resultingState()->value, $next->value));
        }

        return new self($this->deviceId, $this->identity, $this->revision, $next, $next->needsVerification() ? $record : null);
    }
}
