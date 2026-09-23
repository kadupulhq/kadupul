<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\IdentityAccess\Contract;

/**
 * A deliberately closed audit record. Arbitrary context is excluded so request
 * fields, credentials and exception messages cannot enter the audit sink.
 */
final readonly class AuditEvent
{
    public const string ALLOWED = 'allowed';
    public const string DENIED = 'denied';
    public const string SUCCEEDED = 'succeeded';
    public const string FAILED = 'failed';
    public string $recordedAt;

    public function __construct(
        public string $correlationId,
        public ?int $actorId,
        public string $action,
        public string $targetType,
        public string $targetId,
        public string $decision,
        public string $outcome,
        ?string $recordedAt = null,
    ) {
        $this->recordedAt = $recordedAt ?? gmdate('Y-m-d\\TH:i:s\\Z');
        if (!preg_match('/^[a-f0-9]{32}$/D', $correlationId)) {
            throw new \InvalidArgumentException('Invalid audit correlation identifier.');
        }
        if ($actorId !== null && $actorId <= 0) {
            throw new \InvalidArgumentException('Invalid audit actor.');
        }
        foreach ([$action, $targetType] as $name) {
            if (!preg_match('/^[a-z][a-z0-9_.-]{0,63}$/D', $name)) {
                throw new \InvalidArgumentException('Invalid audit name.');
            }
        }
        if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9_.:-]{0,127}$/D', $targetId)) {
            throw new \InvalidArgumentException('Invalid audit target.');
        }
        if (!in_array($decision, [self::ALLOWED, self::DENIED], true)) {
            throw new \InvalidArgumentException('Invalid audit decision.');
        }
        if (!in_array($outcome, [self::SUCCEEDED, self::DENIED, self::FAILED], true)) {
            throw new \InvalidArgumentException('Invalid audit outcome.');
        }
        if ($decision === self::DENIED && $outcome !== self::DENIED) {
            throw new \InvalidArgumentException('A denied audit decision must have a denied outcome.');
        }
        if ($decision === self::ALLOWED && $outcome === self::DENIED) {
            throw new \InvalidArgumentException('An allowed audit decision cannot have a denied outcome.');
        }
        if (!preg_match('/^\\d{4}-\\d{2}-\\d{2}T\\d{2}:\\d{2}:\\d{2}Z$/D', $this->recordedAt)) {
            throw new \InvalidArgumentException('Invalid audit timestamp.');
        }
    }

    public function json(): string
    {
        return json_encode([
            'schema' => 'kadupul.audit.v1',
            'recorded_at' => $this->recordedAt,
            'correlation_id' => $this->correlationId,
            'actor' => $this->actorId === null ? null : ['id' => $this->actorId],
            'action' => $this->action,
            'target' => ['type' => $this->targetType, 'id' => $this->targetId],
            'decision' => $this->decision,
            'outcome' => $this->outcome,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }
}
