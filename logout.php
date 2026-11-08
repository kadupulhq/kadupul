<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2004-2026 The Cacti Group
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

define('CACTI_IN_INSTALL', 1);
include('./include/auth.php');

global $config;

require_once __DIR__ . '/include/vendor/autoload.php';
$logoutRequest = \Symfony\Component\HttpFoundation\Request::createFromGlobals();
try {
    $logoutAction = \Kadupul\Platform\Infrastructure\Symfony\LegacyLogoutPage::action($logoutRequest);
} catch (\Symfony\Component\HttpKernel\Exception\HttpExceptionInterface $error) {
    (new \Symfony\Component\HttpFoundation\Response('', $error->getStatusCode(), $error->getHeaders()))->send(false);
    return;
}
$logoutConfirmed = $logoutRequest->isMethod('POST');
if ($logoutConfirmed) {
    // The native bootstrap checks CSRF too. Retain an explicit mutation guard
    // here so a customized bootstrap cannot accidentally authorize a bare POST.
    if (csrf_check(false) !== true) {
        http_response_code(403);
        return;
    }
    set_default_action();
    api_plugin_hook('logout_pre_session_destroy');

    // Revoke remembered credentials before discarding cookie/session ownership.
    clear_auth_cookie();
    cacti_cookie_logout();
    cacti_session_destroy();
    $version = get_cacti_version();
    api_plugin_hook('logout_post_session_destroy');

    if (api_plugin_hook_function('custom_logout_message', OPER_MODE_NATIVE) === OPER_MODE_RESKIN) {
        exit;
    }
    // Trusted hooks may change the native action as well as presentation state.
    $logoutAction = get_request_var('action');
} else {
    $version = get_cacti_version();
}

// GET and HEAD display confirmation; only a validated POST completes logout.
if (!$logoutConfirmed || in_array($logoutAction, ['timeout', 'disabled', 'remote'], true)) {
    if (!$logoutConfirmed) {
        $message = '';
    } elseif ($logoutAction === 'timeout') {
        $message = __('You have been logged out of Kadupul due to a session timeout.');
    } elseif ($logoutAction === 'disabled') {
        $message = __('You have been logged out of Kadupul due to an account suspension.');
    } elseif ($logoutAction === 'remote') {
        $message = __('You have been logged out of Kadupul due to a Remote Data Collector state change');
    } else {
        $message = '';
    }

    // Keep native header/footer evaluation in global scope: plugins may change
    // globals or restart a session after teardown. Only rendering crosses over.
    require_once __DIR__ . '/include/vendor/autoload.php';
    $logoutBufferLevel = ob_get_level();
    ob_start();
    html_common_header(__('Logout of Kadupul'));
    $logoutHead = \Kadupul\Platform\Infrastructure\Symfony\LegacyLogoutPage::finishCapture($logoutBufferLevel);
    $logoutPrompt = $logoutConfirmed ? __('Please close your browser or %sLogin Again%s', '</p><center>[<a href="index.php">', '</a>]</center>') : '';
    $logoutHeading = $logoutConfirmed ? __('Automatic Logout') : __('Logout of Kadupul');
    $logoutVersion = __('Version %s', $version);
    $logoutNonce = CactiSecureHeaders::getNonceAttribute();
    ob_start();
    include('./include/global_session.php');
    $logoutFooter = \Kadupul\Platform\Infrastructure\Symfony\LegacyLogoutPage::finishCapture($logoutBufferLevel);
    $logoutKernel = require __DIR__ . '/config/bootstrap.php';
    \Kadupul\Platform\Infrastructure\Symfony\LegacyLogoutPage::send(
        $logoutKernel,
        new \Kadupul\Platform\Infrastructure\Symfony\NativeLogoutPage(
            $logoutHead,
            $logoutFooter,
            $message,
            $logoutPrompt,
            $logoutHeading,
            $logoutVersion,
            COPYRIGHT_YEARS_SHORT,
            $logoutNonce,
            CactiSecureHeaders::contentSecurityPolicies(),
            $logoutConfirmed ? null : [
                // Use the configured same-origin path; never derive a form target
                // carrying a CSRF token from the untrusted Host request header.
                'target' => \Kadupul\Platform\Infrastructure\Symfony\LegacyLogoutPage::confirmationTarget($config['url_path']),
                'action' => $logoutAction,
                'token' => csrf_get_tokens(),
                'label' => __('Logout'),
            ],
        ),
    );
} else {
    header('Location: index.php', true, 303);
}
