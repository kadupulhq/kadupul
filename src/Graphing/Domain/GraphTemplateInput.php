<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Graphing\Domain;

/** A graph item input of one graph template and the template items it groups. */
final readonly class GraphTemplateInput
{
    /** graph_template_input.id is a mediumint unsigned. */
    public const int MAX_ID = 16777215;
    /** graph_template_input.name is a varchar(255). */
    public const int MAX_NAME = 255;
    /** graph_template_input.description is a TEXT column. */
    public const int MAX_DESCRIPTION_BYTES = 65535;

    public string $revision;

    /** @param list<int> $itemIds template item IDs in ascending order */
    public function __construct(
        public int $id,
        public int $templateId,
        public string $name,
        public string $description,
        public string $column,
        public array $itemIds,
    ) {
        $this->revision = hash('sha256', json_encode([$id, $templateId, $name, $description, $column, $itemIds], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
    }

    /** Legacy refused an empty name and kept any other text as entered. */
    public static function validateName(string $name): void
    {
        if ($name === '' || preg_match('//u', $name) !== 1 || mb_strlen($name, 'UTF-8') > self::MAX_NAME || str_contains($name, "\0")) {
            throw new \InvalidArgumentException('Enter a name of 1 to 255 characters.');
        }
    }

    public static function validateDescription(string $description): void
    {
        if (preg_match('//u', $description) !== 1 || strlen($description) > self::MAX_DESCRIPTION_BYTES || str_contains($description, "\0")) {
            throw new \InvalidArgumentException('Enter a description of at most 65535 bytes.');
        }
    }
}
