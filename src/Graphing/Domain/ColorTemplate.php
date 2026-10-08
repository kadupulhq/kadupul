<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Graphing\Domain;

final readonly class ColorTemplate
{
    /** Matches color_templates.name and the legacy form's max_length. */
    public const int MAX_LENGTH = 255;

    public string $revision;

    public function __construct(public int $id, public string $name, public int $graphs = 0, public int $templates = 0, public int $items = 0)
    {
        $this->revision = hash('sha256', json_encode([$id, $name], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
    }

    /** Aggregate graphs and aggregate templates both keep the ID; neither has a foreign key. */
    public function isDeletable(): bool
    {
        return $this->graphs === 0 && $this->templates === 0;
    }

    public static function validateName(string $name): void
    {
        if ($name === '' || mb_strlen($name, 'UTF-8') > self::MAX_LENGTH || preg_match('//u', $name) !== 1 || str_contains($name, "\0")) {
            throw new \InvalidArgumentException('Color Template name must contain 1 to 255 valid characters.');
        }
    }

    /** The legacy duplicate action substitutes <template_title> in the submitted format. */
    public static function duplicateName(string $titleFormat, string $name): string
    {
        $duplicate = str_replace('<template_title>', $name, $titleFormat);
        self::validateName($duplicate);

        return $duplicate;
    }
}
