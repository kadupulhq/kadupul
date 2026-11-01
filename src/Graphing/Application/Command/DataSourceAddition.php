<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Graphing\Application\Command;

use Kadupul\Graphing\Application\Port\CatalogChoice;
use Kadupul\IdentityAccess\Contract\OperatorDatabase;

final readonly class DataSourceAddition
{
    public const string ADDED = 'added';
    public const string UNKNOWN_DEVICE = 'unknown_device';
    public const string UNKNOWN_TEMPLATE = 'unknown_data_template';
    public const string FAILED = 'failed';

    public function __construct(
        public OperatorDatabase $database,
        public string $status,
        public ?CatalogChoice $device = null,
        public ?CatalogChoice $dataTemplate = null,
        public int $id = 0,
    ) {}

    public function succeeded(): bool
    {
        return $this->status === self::ADDED;
    }
}
