<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$fixture = json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR);
$evidence = ['logs' => [], 'recipients' => [], 'legacy' => []];

require __DIR__ . '/admin_notification_functions.php';

require dirname(__DIR__, 2) . '/include/admin_notifications.php';
kadupul_notify_administrator($fixture['subject'] ?? 'Administrative warning', $fixture['body'] ?? '<strong>Storage needs attention.</strong>');
echo json_encode($evidence, JSON_THROW_ON_ERROR);
