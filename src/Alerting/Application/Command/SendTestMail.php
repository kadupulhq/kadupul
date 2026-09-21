<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Alerting\Application\Command;

use Kadupul\Alerting\Application\Port\TestMailDelivery;

final class SendTestMail
{
    public function __construct(private readonly TestMailDelivery $delivery) {}

    public function __invoke(): void
    {
        $this->delivery->send('Kadupul test email', 'This test email was sent by Kadupul using Symfony Mailer.');
    }
}
