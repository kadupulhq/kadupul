<?php

/* SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later */

namespace Kadupul\DataInput\Domain;

final class DataInputState
{
    public const array SYSTEM = ['3eb92bb845b9660a7445cf9740726522', 'bf566c869ac6443b0c75d1c32b5a350e', '80e9e4c4191a5da189ae26d0e237f015', '332111d8b54ac8ce939af87a7eac0c06'];
    public static function revision(array $method, array $fields): string
    {
        foreach ($method as &$value) {
            $value = $value === null ? '' : (string) $value;
        }
        unset($value);
        foreach ($fields as &$field) {
            foreach ($field as &$value) {
                $value = $value === null ? '' : (string) $value;
            } unset($value);
            ksort($field);
        } unset($field);
        ksort($method);
        return hash('sha256', json_encode([$method, $fields], JSON_THROW_ON_ERROR));
    }
    public static function text(mixed $value, int $length, bool $required = false): string
    {
        if (!is_string($value) || ($required && trim($value) === '') || mb_strlen($value) > $length || preg_match('//u', $value) !== 1 || str_contains($value, "\0")) {
            throw new \InvalidArgumentException('Invalid data input value.');
        }
        return $value;
    }
    public static function method(array $data): array
    {
        $name = self::text($data['name'] ?? null, 200, true);
        $input = self::text($data['input_string'] ?? '', 512);
        $type = filter_var($data['type_id'] ?? null, FILTER_VALIDATE_INT);
        if (!in_array($type, [1, 2, 3, 4, 5, 6], true)) {
            throw new \InvalidArgumentException('Invalid data input type.');
        }
        return ['name' => $name, 'input_string' => $input, 'type_id' => $type];
    }
    public static function field(array $data): array
    {
        $result = [];
        foreach (['name' => 200, 'data_name' => 50, 'type_code' => 40, 'regexp_match' => 200] as $key => $length) {
            $result[$key] = self::text($data[$key] ?? '', $length, in_array($key, ['name', 'data_name'], true));
        }
        if (!in_array($data['input_output'] ?? null, ['in', 'out'], true)) {
            throw new \InvalidArgumentException('Invalid data input field.');
        }
        $result['input_output'] = $data['input_output'];
        foreach (['update_rra', 'allow_nulls'] as $key) {
            if (!is_bool($data[$key] ?? false)) {
                throw new \InvalidArgumentException('Invalid field option.');
            } $result[$key] = ($data[$key] ?? false) ? 'on' : '';
        }
        if ($result['input_output'] === 'out') {
            $result['type_code'] = '';
            $result['regexp_match'] = '';
            $result['allow_nulls'] = '';
        } else {
            $result['update_rra'] = '';
        }
        return $result;
    }
    public static function placeholders(string $command): array
    {
        preg_match_all('/<([_a-zA-Z0-9]+)>/', $command, $matches);
        return array_values(array_unique(array_filter($matches[1], static fn(string $name): bool => $name !== 'path_cacti')));
    }
}
