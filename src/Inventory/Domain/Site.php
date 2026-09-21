<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Inventory\Domain;

final class Site
{
    public function __construct(public readonly int $id, private string $name, private string $notes) {}

    public function name(): string
    {
        return $this->name;
    }

    public function notes(): string
    {
        return $this->notes;
    }

    public function revision(): string
    {
        return hash('sha256', json_encode([$this->id, $this->name, $this->notes], JSON_THROW_ON_ERROR));
    }

    public function revise(string $name, string $notes, string $expectedRevision): void
    {
        if (!hash_equals($this->revision(), $expectedRevision)) {
            throw new SiteEditConflict('This site changed. Reload it before saving.');
        }
        $name = trim($name);
        if ($name === '' || !mb_check_encoding($name, 'UTF-8') || mb_strlen($name, 'UTF-8') > 100 || str_contains($name, "\0")) {
            throw new \InvalidArgumentException('The name must contain 1–100 characters.');
        }
        if (!mb_check_encoding($notes, 'UTF-8') || mb_strlen($notes, 'UTF-8') > 1024 || str_contains($notes, "\0")) {
            throw new \InvalidArgumentException('Notes must be valid text of at most 1,024 characters.');
        }
        $this->name = $name;
        $this->notes = $notes;
    }
}
