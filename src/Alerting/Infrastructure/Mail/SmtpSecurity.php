<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Alerting\Infrastructure\Mail;

/** The settings_smtp_secure values Symfony Mailer can honour. */
enum SmtpSecurity: string
{
    case None = 'none';
    case Tls = 'tls';
    case Ssl = 'ssl';

    /** Implicit TLS on connect. */
    public function implicitTls(): bool
    {
        return $this === self::Ssl;
    }

    /** STARTTLS after EHLO. */
    public function startTls(): bool
    {
        return $this === self::Tls;
    }

    /** Refuse to send in clear text. */
    public function requiresTls(): bool
    {
        return $this !== self::None;
    }
}
