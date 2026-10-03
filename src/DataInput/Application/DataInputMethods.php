<?php

/* SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later */

namespace Kadupul\DataInput\Application;

use Kadupul\DataInput\Application\Port\DataInputAccess;
use Kadupul\DataInput\Application\Port\DataInputGateway;

final readonly class DataInputMethods
{
    public function __construct(private DataInputAccess $access, private DataInputGateway $gateway) {}
    public function execute(string $action, int $id = 0, array $payload = []): array
    {
        return $this->gateway->execute($this->access->authorize()->id, $action, $id, $payload);
    }
}
