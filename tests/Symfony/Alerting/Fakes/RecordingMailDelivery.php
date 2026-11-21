<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Kadupul\Tests\Alerting\Fakes;

use Kadupul\Alerting\Application\Port\AdministrativeMailDelivery;
use Kadupul\Alerting\Domain\AdministratorRecipient;

/** Keeps every mail; each queued failure is thrown by one send, in order. */
final class RecordingMailDelivery implements AdministrativeMailDelivery
{
    /** @var list<array{recipient: AdministratorRecipient, subject: string, html: string}> */
    public array $sent = [];
    /** @var list<\Throwable> */
    public array $failures = [];
    /** @var ?\Closure(): void run during each send, before it succeeds or fails */
    public ?\Closure $during = null;

    public function send(AdministratorRecipient $recipient, string $subject, string $html): void
    {
        if ($this->during !== null) {
            ($this->during)();
        }
        $failure = array_shift($this->failures);
        if ($failure !== null) {
            throw $failure;
        }
        $this->sent[] = ['recipient' => $recipient, 'subject' => $subject, 'html' => $html];
    }
}
