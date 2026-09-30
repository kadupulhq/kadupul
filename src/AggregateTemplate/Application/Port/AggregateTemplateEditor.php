<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

namespace Kadupul\AggregateTemplate\Application\Port;

interface AggregateTemplateEditor
{
    /** @param array<string,mixed> $data */
    public function save(int $actorId, int $id, array $data, string $revision): int;

    /** @param array<int,string> $revisions */
    public function delete(int $actorId, array $revisions): void;
}
