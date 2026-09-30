<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

namespace Kadupul\Inventory\Infrastructure\Symfony;

use Twig\Markup;

/** Only captured output from installed device-template hooks may cross this boundary. */
final class TrustedDeviceTemplatePluginHtml
{
    public static function capturedHooks(array $captured): array
    {
        $output = [];
        foreach (['device_template_top', 'device_template_edit'] as $hook) {
            if (is_string($captured[$hook] ?? null)) {
                $output[$hook] = new Markup($captured[$hook], 'UTF-8');
            }
        }
        return $output;
    }
}
