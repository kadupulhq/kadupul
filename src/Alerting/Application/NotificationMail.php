<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Kadupul\Alerting\Application;

use Kadupul\Alerting\Application\Port\NotificationContent;
use Kadupul\Alerting\Domain\Evaluation\Transition;

/**
 * The subject and HTML body of an alert notification. The administrative
 * mail path sends HTML (AdministrativeMailDelivery), so every stored text is
 * escaped for HTML: device text cannot add markup or a legacy send_mail()
 * placeholder such as <SUBJECT>. The subject holds no stored text at all, so
 * nothing stored reaches a mail header. Times are UTC, so the mail does not
 * depend on the sender's time zone.
 */
final class NotificationMail
{
    /**
     * The incident view's path under the Kadupul site, through the app.php
     * front controller. The mail path has no base URL, so the body names the
     * page and this path instead of a link that might not resolve.
     */
    public const string INCIDENT_PATH = 'app.php/alerting/incidents/%d';

    public static function subject(NotificationContent $content): string
    {
        return 'Alert rule ' . $content->ruleId . ($content->kind === Transition::Opened ? ' opened an incident' : ' recovered');
    }

    public static function html(NotificationContent $content): string
    {
        $opened = $content->kind === Transition::Opened;
        $lines = [
            self::subject($content) . '.',
            'Rule: alert rule ' . $content->ruleId,
            'Series: ' . self::series($content),
            'Transition: ' . $content->kind->value,
            'Value: ' . self::number($content->value),
            ($opened ? 'Trigger' : 'Clear') . ($content->ruleKind === 'baseline' ? ' band edge: ' : ' threshold: ') . self::number($content->threshold),
            'Time: ' . gmdate('Y-m-d H:i:s', $content->observedAt) . ' UTC',
            'Incident: ' . $content->incidentId . ', on the Alert Incidents page (' . sprintf(self::INCIDENT_PATH, $content->incidentId) . ' on the Kadupul site)',
        ];

        return implode("<br>\n", $lines);
    }

    private static function series(NotificationContent $content): string
    {
        if ($content->localDataId === null || $content->rrdName === null) {
            return 'no longer defined';
        }
        $parts = ['data source ' . $content->localDataId, self::escape($content->rrdName)];
        if ($content->deviceDescription !== null) {
            array_unshift($parts, 'device ' . self::escape($content->deviceDescription));
        }

        return implode(', ', $parts);
    }

    private static function number(float $value): string
    {
        return sprintf('%.15G', $value);
    }

    private static function escape(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
    }
}
