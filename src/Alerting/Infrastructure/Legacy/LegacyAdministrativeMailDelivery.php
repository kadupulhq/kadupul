<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Alerting\Infrastructure\Legacy;

use Kadupul\Alerting\Application\MailDeliveryFailed;
use Kadupul\Alerting\Application\Port\AdministrativeMailDelivery;
use Kadupul\Alerting\Domain\AdministratorRecipient;
use Kadupul\Alerting\Infrastructure\Mail\SmtpMessageSender;

final class LegacyAdministrativeMailDelivery implements AdministrativeMailDelivery
{
    public function __construct(private readonly SmtpMessageSender $smtp) {}

    public function send(AdministratorRecipient $recipient, string $subject, string $html): void
    {
        $settings = [];
        foreach (['how', 'from_email', 'from_name', 'smtp_host', 'smtp_port', 'smtp_username', 'smtp_password', 'smtp_secure', 'smtp_timeout'] as $key) {
            $settings['settings_' . $key] = (string) \read_config_option('settings_' . $key);
        }
        // Preserve the old timeout/security defaults at this compatibility boundary.
        $timeout = $settings['settings_smtp_timeout'];
        if ($timeout === '' || !is_numeric($timeout) || (float) $timeout <= 0 || (float) $timeout > 300) {
            $settings['settings_smtp_timeout'] = '5';
        }
        if ($settings['settings_smtp_secure'] === '') {
            $settings['settings_smtp_secure'] = 'none';
        }
        if ($this->supports($settings, $recipient, $subject, $html)) {
            $this->smtp->send($settings, $recipient->email, $recipient->name, $subject, $html, true);
            \cacti_log('INFO: Administrative Email accepted by SMTP server.', false, 'MAILER');

            return;
        }
        // Select compatibility before attempting delivery. Never retry a failed SMTP send.
        $email = $settings['settings_from_email'];
        $name = $settings['settings_from_name'];
        $from = $name !== '' ? "$name <$email>" : $email;
        $to = $recipient->name !== '' ? '"' . $recipient->name . '" <' . $recipient->email . '>' : $recipient->email;
        $error = \send_mail($to, $from, $subject, $html, '', '', true);
        if (!empty($error)) {
            throw new MailDeliveryFailed();
        }
    }

    private function supports(array $settings, AdministratorRecipient $recipient, string $subject, string $html): bool
    {
        $host = $settings['settings_smtp_host'];
        $singleHost = filter_var($host, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME)
            || filter_var(trim($host, '[]'), FILTER_VALIDATE_IP, FILTER_FLAG_IPV6);
        // Complex legacy address lists, host lists and template substitutions remain legacy-owned.
        return $settings['settings_how'] === '2' && $singleHost
            && $settings['settings_from_name'] !== ''
            && $settings['settings_smtp_username'] !== '0'
            && filter_var($settings['settings_from_email'], FILTER_VALIDATE_EMAIL)
            && filter_var($recipient->email, FILTER_VALIDATE_EMAIL)
            && ctype_digit($settings['settings_smtp_port'])
            && ctype_digit($settings['settings_smtp_timeout'])
            && in_array($settings['settings_smtp_secure'], ['none', 'tls', 'ssl'], true)
            && !str_contains($subject, '|date_time|')
            && !preg_match('/<(?:SUBJECT|TO|CC|FROM|REPLYTO)>/', $html);
    }
}
