<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Inventory\Application\Port;

use Kadupul\Inventory\Domain\DeviceTemplateDefinition;

interface DeviceTemplateDefinitions
{
    public function authorize(int $actor): void;
    public function defaults(int $actor, bool $reset = false): array;
    public function remember(int $actor, array $filters): void;
    public function list(array $filters): array;
    public function find(int $id): ?DeviceTemplateDefinition;
    public function graphChoices(): array;
    public function choices(): array;
    public function execute(int $actor, string $action, array $command): array;
    public function hooks(int $actor, int $id): array;
}
