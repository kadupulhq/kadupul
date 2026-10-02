<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Navigation\Application\Port;

interface LinkStore
{
    public function snapshot(): array;
    public function list(array $filters): array;
    public function files(): array;
    public function defaultRows(): int;
    public function save(int $actorId, ?int $id, array $fields, string $revision): int;
    public function mutate(int $actorId, array $ids, string $operation, string $revision): void;
}
