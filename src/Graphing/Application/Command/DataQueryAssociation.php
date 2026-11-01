<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Graphing\Application\Command;

use Kadupul\Graphing\Application\Port\CatalogChoice;
use Kadupul\Graphing\Application\Port\CatalogDevice;
use Kadupul\IdentityAccess\Contract\OperatorDatabase;

/** The outcome of associating a data query with a device; $status is one of the constants. */
final readonly class DataQueryAssociation
{
    public const string ASSOCIATED = 'associated';
    public const string UNKNOWN_DEVICE = 'unknown_device';
    public const string UNKNOWN_QUERY = 'unknown_data_query';
    public const string ALREADY = 'already_associated';
    public const string FAILED = 'failed';

    public function __construct(
        public OperatorDatabase $database,
        public string $status,
        public ?CatalogDevice $device = null,
        public ?CatalogChoice $dataQuery = null,
    ) {}

    /** Whether the data query was associated. */
    public function succeeded(): bool
    {
        return $this->status === self::ASSOCIATED;
    }
}
