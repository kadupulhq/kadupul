<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Alerting\Application\ReadModel;

use Kadupul\Alerting\Application\DeliverNotifications;

/**
 * One alert_notification row as the incident page shows it. While a row is
 * `sending`, next_attempt_at is the end of its claim's lease (ADR 0011
 * decision 7). Once that lease has passed nobody is sending it: the row is
 * due again, or, on its last attempt, failed on the next delivery run. So
 * an expired claim is shown as retrying or failed, never as sending.
 * A queued row that has been tried before is retrying.
 */
final readonly class NotificationView
{
    public const string QUEUED = 'queued';
    public const string RETRYING = 'retrying';
    public const string SENDING = 'sending';
    public const string SENT = 'sent';
    public const string SUPPRESSED = 'suppressed';
    public const string FAILED = 'failed';
    public const string UNKNOWN = 'unknown';

    public function __construct(
        public int $id,
        public string $kind,
        public string $status,
        public int $attempts,
        public int $queuedAt,
        public ?int $nextAttemptAt,
        public ?int $sentAt,
        public string $lastError,
    ) {}

    public static function fromStored(int $id, string $kind, string $stored, int $attempts, int $queuedAt, int $nextAttemptAt, ?int $sentAt, string $lastError, int $now): self
    {
        $status = match ($stored) {
            self::QUEUED => $attempts > 0 ? self::RETRYING : self::QUEUED,
            self::SENDING => match (true) {
                $nextAttemptAt > $now => self::SENDING,
                $attempts >= DeliverNotifications::MAX_ATTEMPTS => self::FAILED,
                default => self::RETRYING,
            },
            self::SENT, self::SUPPRESSED, self::FAILED => $stored,
            default => self::UNKNOWN,
        };
        // Only a row that will be tried again has a next attempt to show.
        $next = in_array($status, [self::QUEUED, self::RETRYING], true) ? $nextAttemptAt : null;

        return new self($id, $kind, $status, $attempts, $queuedAt, $next, $sentAt, $lastError);
    }
}
