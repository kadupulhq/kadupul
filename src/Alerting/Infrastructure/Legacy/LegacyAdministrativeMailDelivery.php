<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Alerting\Infrastructure\Legacy;

use Kadupul\Alerting\Application\MailDeliveryFailed;
use Kadupul\Alerting\Application\Port\AdministrativeMailDelivery;
use Kadupul\Alerting\Domain\AdministratorRecipient;
use Kadupul\Alerting\Infrastructure\Mail\SmtpMessageSender;
use Kadupul\Alerting\Infrastructure\Mail\SmtpSettings;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

/**
 * Sends through Symfony Mailer when the installation names one plain SMTP
 * server, and through the legacy mailer for every configuration it cannot
 * represent. The choice is made before any attempt; a failed send is never
 * retried on the other path.
 */
#[AsAlias(AdministrativeMailDelivery::class)]
final readonly class LegacyAdministrativeMailDelivery implements AdministrativeMailDelivery
{
    private const array SETTINGS = ['how', 'from_email', 'from_name', 'smtp_host', 'smtp_port', 'smtp_username', 'smtp_password', 'smtp_secure', 'smtp_timeout'];

    public function __construct(private SmtpMessageSender $smtp) {}

    public function send(AdministratorRecipient $recipient, string $subject, string $html): void
    {
        $settings = [];
        foreach (self::SETTINGS as $key) {
            $settings['settings_' . $key] = self::option('settings_' . $key);
        }
        // Preserve the old timeout/security defaults at this compatibility boundary.
        $timeout = $settings['settings_smtp_timeout'];
        if ($timeout === '' || !is_numeric($timeout) || (float) $timeout <= 0 || (float) $timeout > 300) {
            $settings['settings_smtp_timeout'] = '5';
        }
        if ($settings['settings_smtp_secure'] === '') {
            $settings['settings_smtp_secure'] = 'none';
        }
        $smtp = SmtpSettings::tryFromInstallationSettings($settings);
        if ($smtp !== null && $this->symfonyCanSend($smtp, $recipient, $subject, $html)) {
            $this->smtp->send($smtp, $recipient->email, $recipient->name, $subject, $html, true);
            \cacti_log('INFO: Administrative Email accepted by SMTP server.', false, 'MAILER');

            return;
        }
        $email = $settings['settings_from_email'];
        $name = $settings['settings_from_name'];
        $from = $name !== '' ? "$name <$email>" : $email;
        $to = $recipient->name !== '' ? '"' . $recipient->name . '" <' . $recipient->email . '>' : $recipient->email;
        // mailer() returns '' on success and an error description otherwise.
        if ((bool) \send_mail($to, $from, $subject, $html, '', '', true)) {
            throw new MailDeliveryFailed();
        }
    }

    /**
     * Conditions beyond a parseable server: legacy address lists, template
     * substitutions and the "0" username placeholder remain legacy-owned.
     */
    private function symfonyCanSend(SmtpSettings $smtp, AdministratorRecipient $recipient, string $subject, string $html): bool
    {
        return $smtp->fromName !== ''
            && preg_match('/[\x00-\x1f\x7f]/', $smtp->fromName . $recipient->name) !== 1
            && $smtp->username !== '0'
            && filter_var($smtp->fromEmail, FILTER_VALIDATE_EMAIL) !== false
            && filter_var($recipient->email, FILTER_VALIDATE_EMAIL) !== false
            && !str_contains($subject, '|date_time|')
            && preg_match('/<(?:SUBJECT|TO|CC|FROM|REPLYTO)>/', $html) !== 1;
    }

    private static function option(string $name): string
    {
        $value = \read_config_option($name);

        return is_scalar($value) ? (string) $value : '';
    }
}
