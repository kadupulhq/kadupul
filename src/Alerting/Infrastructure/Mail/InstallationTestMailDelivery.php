<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Alerting\Infrastructure\Mail;

use Kadupul\Alerting\Application\MailDeliveryFailed;
use Kadupul\Alerting\Application\Port\TestMailDelivery;
use Kadupul\Platform\Contract\DatabaseConnection;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

#[AsAlias(TestMailDelivery::class)]
final readonly class InstallationTestMailDelivery implements TestMailDelivery
{
    public function __construct(private DatabaseConnection $database, private SmtpMessageSender $sender) {}

    public function send(string $subject, string $text): void
    {
        try {
            $statement = $this->database->get()->query("SELECT name, value FROM settings WHERE name IN (
                'settings_how', 'settings_from_email', 'settings_from_name', 'settings_test_email',
                'settings_smtp_host', 'settings_smtp_port', 'settings_smtp_username',
                'settings_smtp_password', 'settings_smtp_secure', 'settings_smtp_timeout'
            )");
            if ($statement === false) {
                throw new \RuntimeException('The mail settings could not be read.');
            }
            $settings = $statement->fetchAll(\PDO::FETCH_KEY_PAIR);
            $recipient = $settings['settings_test_email'] ?? '';
            $this->sender->send(SmtpSettings::fromInstallationSettings($settings), is_string($recipient) ? $recipient : '', '', $subject, $text);
        } catch (\Throwable) {
            // Transport/DB errors and their traces may contain credentials or server responses.
            // Do not retain them as a previous exception or log them through the console.
            throw new MailDeliveryFailed();
        }
    }
}
