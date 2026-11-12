<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\DataInput\Domain;

/**
 * A validated edit of a data query's own snmp_query columns. Its graph
 * template associations are edited separately on data_queries.php. Whether
 * the XML file and the Data Input Method exist is checked by the worker that
 * saves, against the files and rows it reads.
 */
final readonly class DataQuerySettings
{
    /** Widths of snmp_query.name, description and xml_path in cacti.sql. */
    public const int NAME_LENGTH = 100;
    public const int DESCRIPTION_LENGTH = 255;
    public const int XML_PATH_LENGTH = 255;
    /** snmp_query.data_input_id is an unsigned mediumint. */
    public const int ID_MAX = 16777215;
    /** The Data Input Method types data_queries.php offered: Script Query, SNMP Query and Script Server Query. */
    public const array INPUT_TYPES = [3, 4, 6];
    /** The columns an open form's revision covers. */
    public const array REVISION_COLUMNS = ['id', 'name', 'description', 'xml_path', 'data_input_id'];

    public string $name;
    public string $description;
    public string $xmlPath;

    public function __construct(string $name, string $description, string $xmlPath, public int $dataInputId)
    {
        $this->name = self::text($name, 'name', self::NAME_LENGTH, true);
        $this->description = self::text($description, 'description', self::DESCRIPTION_LENGTH, false);
        $this->xmlPath = self::text($xmlPath, 'xml_path', self::XML_PATH_LENGTH, true);
        if ($dataInputId < 1 || $dataInputId > self::ID_MAX) {
            throw new DataQueryRejected('Choose an existing Data Input Method.', [], 'data_input_id');
        }
    }

    /**
     * Builds the settings from a decoded payload, refusing anything that is not
     * the expected scalar so a malformed request never reaches a cast.
     *
     * @param array<mixed> $data
     */
    public static function fromArray(array $data): self
    {
        foreach (['name', 'description', 'xml_path'] as $field) {
            if (!is_string($data[$field] ?? null)) {
                throw new DataQueryRejected('Enter single-line text.', [], $field);
            }
        }
        if (!is_int($data['data_input_id'] ?? null)) {
            throw new DataQueryRejected('Choose an existing Data Input Method.', [], 'data_input_id');
        }
        return new self($data['name'], $data['description'], $data['xml_path'], $data['data_input_id']);
    }

    /** @return array{name: string, description: string, xml_path: string, data_input_id: int} */
    public function columns(): array
    {
        return ['name' => $this->name, 'description' => $this->description, 'xml_path' => $this->xmlPath, 'data_input_id' => $this->dataInputId];
    }

    /**
     * The revision of a stored row: a change to any edited column, by this
     * editor or another writer, makes an open form stale.
     *
     * @param array<string, mixed> $row
     */
    public static function revision(array $row): string
    {
        $values = [];
        foreach (self::REVISION_COLUMNS as $column) {
            $value = $row[$column] ?? null;
            // PDO returns the columns as strings or integers; anything else is no stored value.
            $values[$column] = is_int($value) || is_string($value) ? (string) $value : null;
        }
        return hash('sha256', json_encode($values, JSON_THROW_ON_ERROR));
    }

    private static function text(string $value, string $field, int $length, bool $required): string
    {
        // Legacy trimmed only the XML path; trimming every field keeps stray
        // whitespace from an edit out of the stored names.
        if (preg_match('//u', $value) !== 1 || preg_match('/[\x00-\x1f\x7f]/', $value) === 1) {
            throw new DataQueryRejected('Enter single-line text.', [], $field);
        }
        $value = trim($value);
        if ($required && $value === '') {
            throw new DataQueryRejected('Enter a value.', [], $field);
        }
        if (mb_strlen($value) > $length) {
            throw new DataQueryRejected('Enter at most %count% characters.', ['%count%' => (string) $length], $field);
        }
        return $value;
    }
}
