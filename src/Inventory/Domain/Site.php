<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Inventory\Domain;

/**
 * A site (a named group of devices with address and map details), edited through revise() with a revision check.
 */
final class Site
{
    /**
     * Loads a site from stored values.
     *
     * @param array $details Address and map fields beyond the name and notes; missing ones use NewSite::DEFAULTS.
     */
    public function __construct(public readonly int $id, private string $name, private string $notes, private array $details = []) {}

    /**
     * Returns every site field, with defaults filled in.
     *
     * @return array Map from string to string.
     */
    public function fields(): array
    {
        return array_replace(NewSite::DEFAULTS, $this->details, ['name' => $this->name, 'notes' => $this->notes]);
    }

    /**
     * Returns the site name.
     */
    public function name(): string
    {
        return $this->name;
    }

    /**
     * Returns the site notes.
     */
    public function notes(): string
    {
        return $this->notes;
    }

    /**
     * Builds a new site from this one, naming it by replacing "<site>" in $pattern with this site's name.
     *
     * @throws \InvalidArgumentException When the resulting name or fields are invalid.
     */
    public function duplicate(string $pattern): NewSite
    {
        return new NewSite(array_replace($this->fields(), ['name' => str_replace('<site>', $this->name, $pattern)]));
    }

    /**
     * Returns a SHA-256 token over the site, used to detect concurrent edits.
     */
    public function revision(): string
    {
        return hash('sha256', json_encode([$this->id, $this->fields()], JSON_THROW_ON_ERROR));
    }

    /**
     * Applies an edit after checking that the caller saw the current revision.
     * A null $fields leaves the address and map details unchanged.
     *
     * @throws SiteEditConflict When the site changed since $expectedRevision was issued.
     * @throws \InvalidArgumentException When a value is invalid.
     */
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

    /**
     * Trims and validates a site name and notes.
     *
     * @return array The trimmed name and the notes.
     * @throws \InvalidArgumentException When the name is not 1 to 100 characters or the notes exceed 1,024 characters.
     */
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
