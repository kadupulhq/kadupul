<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Platform\Application\Installer;

use Kadupul\Platform\Domain\Installer\InstallerOptions;

final class InstallerWorkflow
{
    public function __construct(private readonly InstallerBackend $backend) {}

    /** @return array<string, mixed> */
    public function inspect(): array
    {
        $this->backend->authorize();
        return $this->backend->inspect();
    }

    /** @return array<string, mixed> */
    public function diagnostics(): array
    {
        $this->backend->authorize();
        return InstallerDiagnostics::export($this->backend->inspect());
    }

    /** @return list<array<string, mixed>> */
    public function compareRuntime(bool $confirmed): array
    {
        $this->backend->authorize();
        if (!$confirmed) {
            throw new \InvalidArgumentException('Confirm the read-only worker runtime comparison.');
        }
        return $this->backend->compareRuntime();
    }

    /** @param array<array-key, mixed> $input
     *  @return array<string, mixed>
     */
    public function configure(#[\SensitiveParameter] array $input, bool $retainBlankSecrets = false): array
    {
        $this->backend->authorize();
        // An untouched or malformed form must fail before native setters run.
        $options = InstallerOptions::fromArray($input, $retainBlankSecrets);
        return $this->backend->configure($options);
    }

    public function start(string $revision, bool $confirmed, bool $discoveryConfirmed, bool $retry = false): void
    {
        $this->backend->authorize();
        if (!$confirmed || preg_match('/\A[a-f0-9]{64}\z/D', $revision) !== 1) {
            throw new \InvalidArgumentException('Review and confirm the current installation plan.');
        }
        $plan = $this->backend->inspect();
        if (($plan['discovery'] ?? false) === true && !$discoveryConfirmed) {
            throw new \InvalidArgumentException('Confirm the discovery scope before starting.');
        }
        if (($plan['ready'] ?? false) !== true) {
            throw new \RuntimeException('Resolve the blocking checks before starting.');
        }
        $this->backend->start($revision, $retry, $discoveryConfirmed);
    }

    /** @param array<array-key, mixed> $input
     *  @return array<string, mixed>
     */
    public function run(#[\SensitiveParameter] array $input, bool $retry, bool $discoveryConfirmed): array
    {
        $this->backend->authorize();
        $options = $input !== [] || !$retry ? InstallerOptions::fromArray($input) : null;
        $plan = $retry ? $this->backend->inspect() : null;
        if ($retry && ($plan['mode'] ?? null) === 'complete') {
            $status = $this->backend->status();
            if (($status['state'] ?? null) === 'succeeded') {
                return $status;
            }
        }
        // Finishing a partial installation must retain its original plan and
        // cleanup receipt instead of configuring or rerunning migrations.
        if ($plan === null || ($options !== null && ($plan['mode'] ?? null) !== 'finish')) {
            $plan = $this->backend->configure($options ?? InstallerOptions::fromArray($input));
        }
        if (!is_string($plan['revision'] ?? null)) {
            throw new \RuntimeException('The installer did not return a reviewed plan.');
        }
        $this->start($plan['revision'], true, $discoveryConfirmed, $retry);
        return $this->status();
    }

    public function recover(int $attempt, bool $confirmed): void
    {
        $this->backend->authorize();
        if (!$confirmed || $attempt < 1) {
            throw new \InvalidArgumentException('Confirm that the previous worker has stopped before recovering its plan.');
        }
        $this->backend->recover($attempt);
    }

    /** @return array<string, mixed> */
    public function status(): array
    {
        $this->backend->authorize();
        return $this->backend->status();
    }
}
