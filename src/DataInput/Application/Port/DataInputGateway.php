<?php

/* SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later */

namespace Kadupul\DataInput\Application\Port;

interface DataInputGateway
{
    public function execute(int $actorId, string $action, int $id, array $payload = []): array;
}
