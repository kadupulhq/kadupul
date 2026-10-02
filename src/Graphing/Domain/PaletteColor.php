<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Graphing\Domain;

final readonly class PaletteColor
{
    public string $revision;
    public function __construct(public int $id, public string $name, public string $hex, public bool $readOnly, public int $graphs = 0, public int $templates = 0, public int $otherReferences = 0)
    {
        $this->revision = hash('sha256', json_encode([$id, $name, $hex, $readOnly], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
    }
    public function isDeletable(): bool
    {
        return !$this->readOnly && $this->graphs === 0 && $this->templates === 0 && $this->otherReferences === 0;
    }

    public function hasVisibleName(): bool
    {
        return preg_match('/[^\s\p{Z}\p{Cf}]/u', $this->name) === 1;
    }

    public function getDisplayName(): string
    {
        return $this->hasVisibleName() ? $this->name : $this->hex;
    }

    public function previewHex(): string
    {
        if (preg_match('/\A[a-fA-F0-9]{3}\z/D', $this->hex) === 1) {
            return $this->hex[0] . $this->hex[0] . $this->hex[1] . $this->hex[1] . $this->hex[2] . $this->hex[2];
        }
        return preg_match('/\A[a-fA-F0-9]{6}\z/D', $this->hex) === 1 ? $this->hex : '000000';
    }
    public static function validate(string $name, string $hex): void
    {
        if (mb_strlen($name, 'UTF-8') > 40 || preg_match('//u', $name) !== 1 || str_contains($name, "\0")) {
            throw new \InvalidArgumentException('Color name must contain at most 40 valid characters.');
        }
        if (preg_match('/\A(?:[a-fA-F0-9]{3}|[a-fA-F0-9]{6})\z/D', $hex) !== 1) {
            throw new \InvalidArgumentException('Hex must contain 3 or 6 hexadecimal digits.');
        }
    }
}
