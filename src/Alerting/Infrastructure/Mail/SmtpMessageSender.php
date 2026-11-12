<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Alerting\Infrastructure\Mail;

use Kadupul\Alerting\Application\MailDeliveryFailed;
use Symfony\Component\Mailer\Mailer;
use Symfony\Component\Mailer\Transport\Smtp\Stream\SocketStream;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

/** Sends one message to one recipient over a fresh ESMTP connection, without retry. */
final readonly class SmtpMessageSender
{
    /** @throws MailDeliveryFailed when the message is rejected, refused or not confirmed */
    public function send(SmtpSettings $smtp, string $recipient, string $name, string $subject, string $body, bool $html = false): void
    {
        try {
            $email = (new Email())
                ->from($this->address($smtp->fromEmail, $smtp->fromName))
                ->to($this->address($recipient, $name))
                ->subject($subject);
            if ($html) {
                $body .= '<br>';
                $email->html($body);
                $plain = strip_tags(str_ireplace(['<br>', '<br />', '</br>'], "\n", $body));
                if ($plain !== '') {
                    $email->text($plain);
                }
            } else {
                $email->text($body);
            }
            $stream = (new SocketStream())->setTimeout($smtp->timeout);
            $transport = new EsmtpOnlyTransport($smtp->host, $smtp->port, $smtp->security->implicitTls(), stream: $stream);
            $transport->setAutoTls($smtp->security->startTls());
            $transport->setRequireTls($smtp->security->requiresTls());
            $transport->setUsername($smtp->username);
            $transport->setPassword($smtp->password);
            try {
                (new Mailer($transport))->send($email);
            } finally {
                $transport->stop();
                $stream->terminate();
            }
        } catch (\Throwable) {
            // Neither raw transport errors nor their credential-bearing traces may escape.
            throw new MailDeliveryFailed();
        }
    }

    private function address(string $email, string $name = ''): Address
    {
        if (preg_match('/[\x00-\x1f\x7f]/', $email . $name) === 1) {
            throw new \InvalidArgumentException('Invalid mail address.');
        }

        return new Address($email, $name);
    }
}
