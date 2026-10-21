<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Platform\Infrastructure\Legacy;

/** Called only by the isolated, authenticated browser worker. */
final class PermissionDeniedRuntime
{
    /** @return array<string, mixed> */
    public function capture(): array
    {
        $level = ob_get_level();
        ob_start();
        try {
            // Same hook, argument and strict comparison as the native page.
            $reskin = api_plugin_hook_function('custom_denied', OPER_MODE_NATIVE) === OPER_MODE_RESKIN;
            if (ob_get_level() <= $level) {
                throw new \RuntimeException('Permission denied extension closed its output boundary.');
            }
            while (ob_get_level() > $level + 1) {
                if (!ob_end_flush()) {
                    throw new \RuntimeException('Permission denied extension output could not be collected.');
                }
            }
            $printed = (string) ob_get_contents();
        } finally {
            while (ob_get_level() > $level) {
                if (!ob_end_clean()) {
                    break;
                }
            }
        }

        $replacement = null;
        if ($reskin) {
            // The native page ran this output through csrf-magic's buffer.
            $replacement = empty($GLOBALS['csrf']['disable']) && !empty($GLOBALS['csrf']['rewrite'])
                ? csrf_ob_handler($printed, PHP_OUTPUT_HANDLER_FINAL)
                : $printed;
        }
        $referer = $_SERVER['HTTP_REFERER'] ?? null;

        return [
            'replacement' => $replacement,
            'ajax' => is_page_ajax(),
            'return_to' => is_string($referer) ? validate_redirect_url($referer, 'index.php') : null,
            'version' => (string) get_cacti_version(),
            'copyright' => COPYRIGHT_YEARS_SHORT,
            'policies' => \CactiSecureHeaders::contentSecurityPolicies(),
        ];
    }
}
