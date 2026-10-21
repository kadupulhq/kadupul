<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Platform\Infrastructure\Legacy;

use Kadupul\IdentityAccess\Contract\Actor;
use Kadupul\Platform\Application\Port\PermissionDeniedContent;
use Kadupul\Platform\Application\ReadModel\PermissionDenied;

final readonly class LegacyPermissionDeniedContent implements PermissionDeniedContent
{
    public function __construct(private LegacyBrowserWorker $worker) {}

    public function forActor(Actor $actor): PermissionDenied
    {
        $result = $this->worker->permissionDenied($actor->id);
        $replacement = $result['replacement'] ?? null;
        if (($replacement !== null && !is_string($replacement)) || !is_bool($result['ajax'] ?? null)) {
            throw new \RuntimeException('Invalid permission denied extension output.');
        }
        $returnTo = $result['return_to'] ?? null;
        // validate_redirect_url() already refused foreign and scripted targets.
        // Recheck its output shape before it becomes an href.
        if ($returnTo !== null && (!is_string($returnTo) || $returnTo === '' || strlen($returnTo) > 8192
            || str_starts_with($returnTo, '//') || preg_match('/[\x00-\x1f\x7f]/', $returnTo) === 1
            || preg_match('/\A[a-z][a-z0-9+.-]*:/i', $returnTo) === 1)) {
            throw new \RuntimeException('Invalid permission denied return location.');
        }
        foreach (['version', 'copyright'] as $key) {
            if (!is_string($result[$key] ?? null) || strlen($result[$key]) > 256) {
                throw new \RuntimeException('Invalid permission denied product information.');
            }
        }
        if (!is_array($result['policies'] ?? null) || $result['policies'] === []) {
            throw new \RuntimeException('Invalid permission denied security policy.');
        }
        foreach ($result['policies'] as $name => $value) {
            if (!in_array($name, ['Content-Security-Policy', 'Content-Security-Policy-Report-Only'], true)
                || !is_string($value) || strlen($value) > 16384 || preg_match('/[\x00-\x1f\x7f]/', $value)) {
                throw new \RuntimeException('Invalid permission denied security policy.');
            }
        }

        return new PermissionDenied($returnTo, $result['ajax'], $result['version'], $result['copyright'], $replacement, $result['policies']);
    }
}
