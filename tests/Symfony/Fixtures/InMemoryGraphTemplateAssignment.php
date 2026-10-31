<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Tests\Fixtures;

use Kadupul\Graphing\Application\Port\CatalogChoice;
use Kadupul\Graphing\Application\Port\CatalogDevice;
use Kadupul\Graphing\Application\Port\GraphTemplateAssignmentCatalog;
use Kadupul\Graphing\Application\Port\GraphTemplateAttacher;
use Kadupul\IdentityAccess\Contract\OperatorDatabase;

/** One device (7, "core1"), two graph templates, an existing association, and an attacher that records its calls. */
final class InMemoryGraphTemplateAssignment implements GraphTemplateAssignmentCatalog, GraphTemplateAttacher
{
    /** @var list<array{int, int}> */
    public array $attached = [];

    public function __construct(private readonly bool $fails = false) {}

    #[\Override]
    public function findDevice(OperatorDatabase $database, string $id): ?CatalogDevice
    {
        return is_numeric($id) && (float) $id === 7.0 ? new CatalogDevice(7, 'core1', 2, 'Core router') : null;
    }

    #[\Override]
    public function findGraphTemplate(OperatorDatabase $database, string $id): ?CatalogChoice
    {
        return match ((int) $id) {
            3 => new CatalogChoice(3, 'Traffic'),
            4 => new CatalogChoice(4, 'Errors'),
            default => null,
        };
    }

    #[\Override]
    public function isAssociated(OperatorDatabase $database, int $deviceId, int $graphTemplateId): bool
    {
        return $deviceId === 7 && $graphTemplateId === 4;
    }

    #[\Override]
    public function devices(OperatorDatabase $database): array
    {
        return [new CatalogDevice(7, 'core1', 2, 'Core router')];
    }

    #[\Override]
    public function graphTemplates(OperatorDatabase $database): array
    {
        return [new CatalogChoice(3, 'Traffic'), new CatalogChoice(4, 'Errors')];
    }

    #[\Override]
    public function attach(OperatorDatabase $database, int $deviceId, int $graphTemplateId): void
    {
        $this->attached[] = [$deviceId, $graphTemplateId];
        if ($this->fails) {
            throw new \RuntimeException('The graph template could not be associated.');
        }
    }
}
