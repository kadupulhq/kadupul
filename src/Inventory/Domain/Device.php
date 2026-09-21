<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Inventory\Domain;

final class Device
{
    public function __construct(public readonly int $id, private string $description, private string $hostname, private string $notes, private bool $enabled) {}
    public function description(): string
    {
        return $this->description;
    }
    public function hostname(): string
    {
        return $this->hostname;
    }
    public function notes(): string
    {
        return $this->notes;
    }
    public function enabled(): bool
    {
        return $this->enabled;
    }
    public function revision(): string
    {
        return hash('sha256', json_encode([$this->id, $this->description, $this->hostname, $this->notes, $this->enabled], JSON_THROW_ON_ERROR));
    }
    public function revise(string $description, string $hostname, string $notes, bool $enabled, string $expectedRevision): void
    {
        if (!hash_equals($this->revision(), $expectedRevision)) {
            throw new DeviceEditConflict('This device changed. Reload it before saving.');
        }
        $description = trim($description);
        $hostname = trim($hostname);
        if ($description === '' || !mb_check_encoding($description, 'UTF-8') || mb_strlen($description) > 150 || str_contains($description, "\0")) {
            throw new \InvalidArgumentException('The name must contain 1–150 characters.');
        }
        if ($hostname === '' || strlen($hostname) > 100 || !preg_match('/\A[a-zA-Z0-9._:\[\]%-]+\z/D', $hostname)) {
            throw new \InvalidArgumentException('Enter a hostname or IP address of at most 100 characters.');
        }
        if (strlen($notes) > 65535 || !mb_check_encoding($notes, 'UTF-8') || str_contains($notes, "\0")) {
            throw new \InvalidArgumentException('Notes must be valid text of at most 65,535 bytes.');
        }
        $this->description = $description;
        $this->hostname = $hostname;
        $this->notes = $notes;
        $this->enabled = $enabled;
    }
}
