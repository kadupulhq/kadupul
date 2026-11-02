<?php

declare(strict_types=1);

/* SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Platform\Application\Installer;

use Kadupul\IdentityAccess\Contract\ConsoleOperator;
use Kadupul\IdentityAccess\Contract\OperatorDatabase;
use Kadupul\Platform\Domain\Installer\OperationalVerificationRequest;

final class VerifyInstallationOperation
{
    public function __construct(private readonly ConsoleOperator $operator, private readonly OperationalEvidence $evidence) {}

    /** @param array<array-key, mixed> $graphIds
     *  @return array<string, mixed>
     */
    public function run(?string $username, array $graphIds, bool $confirmed): array
    {
        if (!$confirmed) {
            throw new \InvalidArgumentException('Explicitly acknowledge the read-only operational assessment.');
        }
        $this->operator->select($username, OperatorDatabase::Local);
        $actor = $this->operator->actor();
        if ($actor === null || !$this->operator->canUpgradeInstallation($actor)) {
            throw new \RuntimeException('Installation/Upgrades operator permission is required.');
        }
        $request = OperationalVerificationRequest::fromGraphIds($graphIds);
        if ($request->graphIds !== [] && !$this->operator->canManageGraphs($actor)) {
            throw new \RuntimeException('Graph management permission is required for graph evidence.');
        }
        return $this->evidence->inspect($actor->id, $request->graphIds);
    }
}
