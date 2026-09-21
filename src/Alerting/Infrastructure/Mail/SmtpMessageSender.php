<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Alerting\Infrastructure\Mail;

use Kadupul\Alerting\Application\MailDeliveryFailed;
use Symfony\Component\Mailer\Mailer;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

final class SmtpMessageSender
{
    public function send(array $settings, string $recipient, string $name, string $subject, string $body, bool $html = false): void
    {
        try {
            if (($settings['settings_how'] ?? '0') !== '2') {
                throw new \InvalidArgumentException('SMTP is required.');
            }
            $host = $settings['settings_smtp_host'] ?? 'localhost';
            $ip = trim($host, '[]');
            if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
                $host = '[' . $ip . ']';
            } elseif (!filter_var($host, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME)) {
                throw new \InvalidArgumentException('One SMTP hostname or IP address is required.');
            }
            $port = $this->integer($settings['settings_smtp_port'] ?? '25', 65535);
            $timeout = $this->integer($settings['settings_smtp_timeout'] ?? '10', 300);
            $security = $settings['settings_smtp_secure'] ?? 'none';
            if (!in_array($security, ['none', 'tls', 'ssl'], true)) {
                throw new \InvalidArgumentException('Invalid SMTP security mode.');
            }
            $email = (new Email())
                ->from($this->address($settings['settings_from_email'] ?? '', $settings['settings_from_name'] ?? ''))
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
            $transport = new EsmtpOnlyTransport($host, $port, $security === 'ssl');
            $transport->setAutoTls($security === 'tls');
            $transport->setRequireTls($security !== 'none');
            $transport->setUsername($settings['settings_smtp_username'] ?? '');
            $transport->setPassword($settings['settings_smtp_password'] ?? '');
            $transport->getStream()->setTimeout($timeout);
            try {
                (new Mailer($transport))->send($email);
            } finally {
                $transport->stop();
                $transport->getStream()->terminate();
            }
        } catch (\Throwable) {
            // Neither raw transport errors nor their credential-bearing traces may escape.
            throw new MailDeliveryFailed();
        }
    }

    private function integer(string $value, int $maximum): int
    {
        if (!ctype_digit($value) || (int) $value < 1 || (int) $value > $maximum) {
            throw new \InvalidArgumentException('Invalid SMTP port or timeout.');
        }

        return (int) $value;
    }

    private function address(string $email, string $name = ''): Address
    {
        if (preg_match('/[\x00-\x1f\x7f]/', $email . $name)) {
            throw new \InvalidArgumentException('Invalid mail address.');
        }

        return new Address($email, $name);
    }
}
