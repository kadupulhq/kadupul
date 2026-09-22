<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Inventory\Domain;

final class Site
{
    public function __construct(public readonly int $id, private string $name, private string $notes, private array $details = []) {}

    public function fields(): array
    {
        return array_replace(NewSite::DEFAULTS, $this->details, ['name' => $this->name, 'notes' => $this->notes]);
    }

    public function name(): string
    {
        return $this->name;
    }

    public function notes(): string
    {
        return $this->notes;
    }

    public function duplicate(string $pattern): NewSite
    {
        return new NewSite(array_replace($this->fields(), ['name' => str_replace('<site>', $this->name, $pattern)]));
    }

    public function revision(): string
    {
        return hash('sha256', json_encode([$this->id, $this->fields()], JSON_THROW_ON_ERROR));
    }

    public function revise(string $name, string $notes, string $expectedRevision, ?array $fields = null): void
    {
        if (!hash_equals($this->revision(), $expectedRevision)) {
            throw new SiteEditConflict('This site changed. Reload it before saving.');
        }
        [$name, $notes] = self::validateText($name, $notes);
        if ($fields !== null) {
            $validated = new NewSite(array_replace($fields, ['name' => $name, 'notes' => $notes]));
            $this->details = $validated->fields;
        }
        $this->name = $name;
        $this->notes = $notes;
    }

    public static function validateText(string $name, string $notes): array
    {
        $name = trim($name, " \t\n\r\x0B");
        if ($name === '' || !mb_check_encoding($name, 'UTF-8') || mb_strlen($name, 'UTF-8') > 100 || str_contains($name, "\0")) {
            throw new \InvalidArgumentException('The name must contain 1–100 characters.');
        }
        if (!mb_check_encoding($notes, 'UTF-8') || mb_strlen($notes, 'UTF-8') > 1024 || str_contains($notes, "\0")) {
            throw new \InvalidArgumentException('Notes must be valid text of at most 1,024 characters.');
        }
        return [$name, $notes];
    }
}
