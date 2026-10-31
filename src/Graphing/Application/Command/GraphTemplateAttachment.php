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

final readonly class GraphTemplateAttachment
{
    public const string ATTACHED = 'attached';
    public const string UNKNOWN_DEVICE = 'unknown_device';
    public const string UNKNOWN_TEMPLATE = 'unknown_template';
    public const string ALREADY = 'already_associated';
    public const string FAILED = 'failed';

    public function __construct(
        public OperatorDatabase $database,
        public string $status,
        public ?CatalogDevice $device = null,
        public ?CatalogChoice $template = null,
    ) {}

    public function succeeded(): bool
    {
        return $this->status === self::ATTACHED;
    }
}
