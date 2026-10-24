<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Kadupul\ModelRegistry\Domain;

final class DeviceModelAssociation
{
    private function __construct(
        private readonly int $deviceId,
        private readonly ModelIdentity $identity,
        private readonly ModelRevision $revision,
        private readonly AssociationState $state,
        private readonly ?VerificationRecord $verification,
    ) {}

    public static function unknown(int $deviceId, ModelIdentity $identity, ModelRevision $revision): self
    {
        return new self($deviceId, $identity, $revision, AssociationState::Unknown, null);
    }

    public static function discovered(int $deviceId, ModelIdentity $identity, ModelRevision $revision): self
    {
        return self::unknown($deviceId, $identity, $revision)->rediscover();
    }

    public function deviceId(): int
    {
        return $this->deviceId;
    }

    public function identity(): ModelIdentity
    {
        return $this->identity;
    }

    public function revision(): ModelRevision
    {
        return $this->revision;
    }

    public function state(): AssociationState
    {
        return $this->state;
    }

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

    public function verify(VerificationRecord $record): self
    {
        return $this->transitionTo($record->resultingState(), $record);
    }

    public function markUnsupported(): self
    {
        return $this->transitionTo(AssociationState::Unsupported, null);
    }

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
