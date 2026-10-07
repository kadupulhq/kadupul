<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Graphing\Domain;

final readonly class GprintPreset
{
    /** Matches the legacy form's max_length for both fields. */
    public const int MAX_LENGTH = 50;

    public string $revision;

    public function __construct(public int $id, public string $name, public string $gprintText, private string $hash, public int $graphs = 0, public int $templates = 0)
    {
        $this->revision = hash('sha256', json_encode([$id, $name, $gprintText, $hash], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
    }

    public function isDeletable(): bool
    {
        return $this->graphs === 0 && $this->templates === 0;
    }

    public static function validate(string $name, string $gprintText): void
    {
        foreach (['GPRINT Preset Name' => $name, 'GPRINT Text' => $gprintText] as $label => $value) {
            if ($value === '' || mb_strlen($value, 'UTF-8') > self::MAX_LENGTH || preg_match('//u', $value) !== 1 || str_contains($value, "\0")) {
                throw new \InvalidArgumentException($label . ' must contain 1 to 50 valid characters.');
            }
        }
    }
}
