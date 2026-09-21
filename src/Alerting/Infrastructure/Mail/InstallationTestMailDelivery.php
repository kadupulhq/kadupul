<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Alerting\Infrastructure\Mail;

use Kadupul\Alerting\Application\MailDeliveryFailed;
use Kadupul\Alerting\Application\Port\TestMailDelivery;
use Kadupul\Platform\Contract\DatabaseConnection;

final class InstallationTestMailDelivery implements TestMailDelivery
{
    public function __construct(private readonly DatabaseConnection $database, private readonly SmtpMessageSender $sender) {}

    public function send(string $subject, string $text): void
    {
        try {
            $settings = $this->database->get()->query("SELECT name, value FROM settings WHERE name IN (
                'settings_how', 'settings_from_email', 'settings_from_name', 'settings_test_email',
                'settings_smtp_host', 'settings_smtp_port', 'settings_smtp_username',
                'settings_smtp_password', 'settings_smtp_secure', 'settings_smtp_timeout'
            )")->fetchAll(\PDO::FETCH_KEY_PAIR);
            $this->sender->send($settings, $settings['settings_test_email'] ?? '', '', $subject, $text);
        } catch (\Throwable) {
            // Transport/DB errors and their traces may contain credentials or server responses.
            // Do not retain them as a previous exception or log them through the console.
            throw new MailDeliveryFailed();
        }
    }
}
