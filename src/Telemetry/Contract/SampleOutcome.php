<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Kadupul\Telemetry\Contract;

/** The result for one sample: stored, or refused with a reason code and an operator-readable detail. */
final readonly class SampleOutcome
{
    private const MAX_DETAIL = 500;

    private function __construct(
        public ?RejectionReason $reason,
        public string $detail,
    ) {}

    public static function accepted(): self
    {
        return new self(null, '');
    }

    /**
     * The detail reaches logs and API responses, so it may not carry a
     * filesystem path or a SQL statement; those stay in internal logs.
     */
    public static function rejected(RejectionReason $reason, string $detail): self
    {
        if ($detail === '' || strlen($detail) > self::MAX_DETAIL || preg_match('/[\r\n]/', $detail) === 1) {
            throw new InvalidTelemetryValue('A rejection detail must be a single line of 1 to ' . self::MAX_DETAIL . ' characters.');
        }
        if (preg_match('~[/\\\\]~', $detail) === 1) {
            throw new InvalidTelemetryValue('A rejection detail must not contain a filesystem path.');
        }
        if (preg_match('/\b(?:select\b.*\bfrom|insert\s+into|update\b.*\bset|delete\s+from|drop\s+table|alter\s+table)\b/is', $detail) === 1) {
            throw new InvalidTelemetryValue('A rejection detail must not contain SQL.');
        }

        return new self($reason, $detail);
    }

    public function isAccepted(): bool
    {
        return $this->reason === null;
    }
}
