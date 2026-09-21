<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Alerting\Infrastructure\Mail;

use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport;

/** Reject HELO fallback, which can bypass the ESMTP required-TLS check. */
final class EsmtpOnlyTransport extends EsmtpTransport
{
    public function executeCommand(string $command, array $codes): string
    {
        try {
            $response = parent::executeCommand($command, $codes);
            if (str_starts_with($command, 'HELO ') && $this->getUsername() !== '' && !isset($this->getCapabilities()['AUTH'])) {
                throw new \RuntimeException('SMTP authentication is required.');
            }

            return $response;
        } catch (TransportExceptionInterface $error) {
            if (str_starts_with($command, 'EHLO ')) {
                // A non-transport exception prevents Symfony's automatic HELO fallback.
                throw new \RuntimeException('An ESMTP server is required.');
            }

            throw $error;
        }
    }
}
