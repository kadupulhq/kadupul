<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Kadupul\ModelRegistry\Contract;

/**
 * Read-only view of the registry for other modules. Arguments and results are
 * plain values so callers never depend on registry domain types.
 */
interface ModelCatalog
{
    public function find(string $family, string $namespace, string $name, string $revision): ?ModelSummary;

    /** @return list<string> empty when the model is not registered */
    public function capabilities(string $family, string $namespace, string $name, string $revision): array;

    /** @return list<DeviceModelLink> devices associated with the model, ordered by device id */
    public function devicesFor(string $family, string $namespace, string $name, string $revision): array;

    /** @return list<DeviceModelLink> */
    public function modelsForDevice(int $deviceId): array;
}
