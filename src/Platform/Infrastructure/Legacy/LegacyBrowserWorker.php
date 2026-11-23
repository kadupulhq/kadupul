<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Platform\Infrastructure\Legacy;

use Kadupul\Platform\Contract\BrowserWorkerRunner;
use Symfony\Component\HttpFoundation\RequestStack;

/** Isolates procedural browser extensions from the Symfony request runtime. */
final readonly class LegacyBrowserWorker
{
    public function __construct(
        private BrowserWorkerRunner $runner,
        private RequestStack $requests,
        private InstallationConfiguration $configuration,
        // The menu sits on every page, so a timeout leaves the built-in menu.
        private float $menuTimeout = 3.0,
    ) {}

    /** @return array<string, mixed> */
    public function console(int $actorId): array
    {
        return $this->runner->run('legacy-console-home.php', ['index_path' => $this->configuration->urlPath() . '/index.php'], $actorId);
    }

    /** @return array<string, mixed> */
    public function permissionDenied(int $actorId): array
    {
        return $this->runner->run('legacy-permission-denied.php', ['page_path' => $this->configuration->urlPath() . '/permission_denied.php'], $actorId);
    }

    /**
     * The menu after every config_arrays hook. about.php has no realm, so any
     * console account bootstraps; each entry carries its own realm instead.
     *
     * @param list<string> $sections
     * @return array<string, mixed>|null null when the request is not a GET or
     *     HEAD, which the worker handoff refuses
     */
    public function consoleMenu(int $actorId, array $sections): ?array
    {
        $method = $this->requests->getCurrentRequest()?->getMethod();
        if ($method !== 'GET' && $method !== 'HEAD') {
            return null;
        }

        return $this->runner->run('legacy-console-menu.php', ['page_path' => $this->configuration->urlPath() . '/about.php', 'sections' => $sections], $actorId, $this->menuTimeout, false);
    }

}
