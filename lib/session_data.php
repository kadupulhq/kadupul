<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-2.0-or-later

/**
 * Reads only native session framing; payload objects are never constructed.
 * @phpstan-type Token array{type:string,start:int,length:int,body:int,bytes:int,children?:int,reference?:int}
 */
final class CactiSessionData
{
    private const FIELDS = ['sess_user_id', 'sess_user_credential', 'sess_last_activity'];

    /** @return array{user_id:int,binding:array{int,?string},activity:?int,activity_start:?int,activity_length:?int}|false */
    public static function inspect(#[SensitiveParameter] string $data, string $serializer): array|false
    {
        try {
            $fields = self::fields($data, $serializer);
            $user = self::userId($data, $fields['sess_user_id'] ?? null);
            $credential = self::binding($data, $fields['sess_user_credential'] ?? null);
            $activity = $fields['sess_last_activity'] ?? null;
            if ($activity !== null && in_array($activity['type'], ['R', 'r'], true)) return false;
            $time = $activity !== null && $activity['type'] === 'i' ? self::integer($data, $activity) : null;
            return ['user_id' => $user, 'binding' => [$user, $credential], 'activity' => $time,
                'activity_start' => $time === null ? null : $activity['start'], 'activity_length' => $time === null ? null : $activity['length']];
        } catch (Throwable) {
            return false;
        }
    }

    public static function monotonic(#[SensitiveParameter] string $stored, #[SensitiveParameter] string $data, string $serializer): string|false
    {
        $old = self::inspect($stored, $serializer);
        $new = self::inspect($data, $serializer);
        if ($old === false || $new === false) return false;
        if ($old['activity'] === null || $new['activity'] === null || $old['activity'] <= $new['activity']) return $data;
        if ($new['activity_start'] === null || $new['activity_length'] === null) return false;
        return substr_replace($data, 'i:' . $old['activity'] . ';', $new['activity_start'], $new['activity_length']);
    }

    /** @return array<string,Token> */
    private static function fields(string $data, string $serializer): array
    {
        $first = self::scan($data, $serializer, []);
        $wanted = [];
        foreach ($first['fields'] as $field) {
            if ($field['type'] === 'R' && isset($field['reference'])) $wanted[$field['reference']] = true;
        }
        if ($wanted === []) return $first['fields'];
        $second = self::scan($data, $serializer, $wanted);
        foreach ($first['fields'] as &$field) {
            if ($field['type'] === 'R' && isset($field['reference'], $second['targets'][$field['reference']])) $field = $second['targets'][$field['reference']];
        }
        unset($field);
        return $first['fields'];
    }

    /**
     * @param array<int,true> $wanted
     * @return array{fields:array<string,Token>,targets:array<int,Token>}
     */
    private static function scan(string $data, string $serializer, array $wanted): array
    {
        $serializer = strtolower($serializer);
        if (!in_array($serializer, ['php', 'php_binary', 'php_serialize'], true)) throw new UnexpectedValueException('Unsupported native session serializer.');
        $fields = $targets = [];
        $offset = 0;
        $id = 0;
        if ($data === '') return ['fields' => [], 'targets' => []];
        if ($serializer === 'php_serialize') {
            $root = self::token($data, $offset);
            if ($root['type'] !== 'a' || !isset($root['children'])) throw new UnexpectedValueException('Native session root is not an array.');
            $id = 1;
            for ($remaining = $root['children']; $remaining > 0; $remaining -= 2) {
                $key = self::token($data, $offset);
                if (!in_array($key['type'], ['s', 'S', 'i'], true)) throw new UnexpectedValueException('Invalid native session key.');
                $name = $key['type'] === 'i' ? '' : self::text($data, $key);
                $value = self::value($data, $offset, $id, $wanted, $targets);
                if (in_array($name, self::FIELDS, true)) $fields[$name] = $value;
            }
            self::expect($data, $offset, '}');
        } else {
            while ($offset < strlen($data)) {
                if ($serializer === 'php') {
                    $end = strpos($data, '|', $offset);
                    if ($end === false) throw new UnexpectedValueException('Invalid native session framing.');
                    $name = substr($data, $offset, $end - $offset);
                    $offset = $end + 1;
                } else {
                    $length = ord($data[$offset++]) & 127;
                    if ($length > strlen($data) - $offset) throw new UnexpectedValueException('Invalid native session name.');
                    $name = substr($data, $offset, $length);
                    $offset += $length;
                }
                $value = self::value($data, $offset, $id, $wanted, $targets);
                if (in_array($name, self::FIELDS, true)) $fields[$name] = $value;
            }
        }
        if ($offset !== strlen($data)) throw new UnexpectedValueException('Trailing native session data.');
        return ['fields' => $fields, 'targets' => $targets];
    }

    /**
     * Iterative traversal bounds memory by nesting, not the number of values.
     * @param array<int,true> $wanted
     * @param array<int,Token> $targets
     * @return Token
     */
    private static function value(string $data, int &$offset, ?int &$id, array $wanted, array &$targets): array
    {
        $start = $offset;
        $stack = [];
        $root = null;
        do {
            $key = $stack !== [] && ($stack[count($stack) - 1] % 2) === 0;
            if ($stack !== []) --$stack[count($stack) - 1];
            $token = self::token($data, $offset);
            if ($key && !in_array($token['type'], ['s', 'S', 'i'], true)) throw new UnexpectedValueException('Invalid native collection key.');
            if (!$key) {
                $before = $id;
                if ($token['type'] !== 'R' && $id !== null) ++$id;
                if (isset($token['reference']) && $before !== null && $token['reference'] > $before) throw new UnexpectedValueException('Invalid native reference.');
                if ($id !== null && $token['type'] !== 'R' && isset($wanted[$id])) $targets[$id] = $token;
                // Serializable payloads may allocate reference IDs through
                // arbitrary class code. Never guess offsets after that code.
                if ($token['type'] === 'C') $id = null;
            }
            $root ??= $token;
            if (isset($token['children'])) $stack[] = $token['children'];
            while ($stack !== [] && $stack[count($stack) - 1] === 0) {
                self::expect($data, $offset, '}');
                array_pop($stack);
            }
        } while ($stack !== []);
        $root['length'] = $offset - $start;
        return $root;
    }

    /** @return Token */
    private static function token(string $data, int &$offset): array
    {
        $start = $offset;
        $type = $data[$offset++] ?? '';
        if ($type === 'N') {
            self::expect($data, $offset, ';');
            return ['type' => $type, 'start' => $start, 'length' => $offset - $start, 'body' => 0, 'bytes' => 0];
        }
        self::expect($data, $offset, ':');
        if (in_array($type, ['b', 'i', 'd', 'R', 'r'], true)) {
            $end = strpos($data, ';', $offset);
            if ($end === false || $end === $offset) throw new UnexpectedValueException('Invalid native scalar.');
            $number = substr($data, $offset, $end - $offset);
            $token = ['type' => $type, 'start' => $start, 'body' => $offset, 'bytes' => $end - $offset, 'length' => 0];
            if ($type === 'b' && !in_array($number, ['0', '1'], true)) throw new UnexpectedValueException('Invalid native boolean.');
            if ($type === 'i') {
                if (preg_match('/\A[+-]?[0-9]+\z/D', $number) !== 1) throw new UnexpectedValueException('Invalid native integer.');
                self::integer($data, $token);
            }
            if (in_array($type, ['R', 'r'], true)) {
                if (preg_match('/\A[1-9][0-9]*\z/D', $number) !== 1 || strlen($number) > strlen((string) PHP_INT_MAX)
                    || (strlen($number) === strlen((string) PHP_INT_MAX) && strcmp($number, (string) PHP_INT_MAX) > 0)) throw new UnexpectedValueException('Invalid native reference.');
                $token['reference'] = (int) $number;
            }
            if ($type === 'd' && preg_match('/\A(?:[+-]?(?:[0-9]+(?:\.[0-9]*)?|\.[0-9]+)(?:[eE][+-]?[0-9]+)?|NAN|[+-]?INF)\z/D', $number) !== 1) throw new UnexpectedValueException('Invalid native floating point value.');
            $offset = $end + 1;
        } elseif (in_array($type, ['s', 'S', 'E'], true)) {
            $length = self::number($data, $offset);
            self::expect($data, $offset, ':"');
            $body = $offset;
            if ($type === 'S') {
                for ($left = $length; $left > 0; --$left) {
                    if ($offset >= strlen($data)) throw new UnexpectedValueException('Truncated native string.');
                    if ($data[$offset++] === '\\') {
                        if (strlen(substr($data, $offset, 2)) !== 2 || strspn(substr($data, $offset, 2), '0123456789abcdefABCDEF') !== 2) throw new UnexpectedValueException('Invalid native escaped string.');
                        $offset += 2;
                    }
                }
            } else {
                if ($length > strlen($data) - $offset) throw new UnexpectedValueException('Truncated native string.');
                $offset += $length;
            }
            $token = ['type' => $type, 'start' => $start, 'body' => $body, 'bytes' => $offset - $body];
            self::expect($data, $offset, '";');
        } elseif ($type === 'a') {
            $count = self::number($data, $offset);
            if ($count > intdiv(strlen($data) - $offset, 2)) throw new UnexpectedValueException('Invalid native collection length.');
            self::expect($data, $offset, ':{');
            $token = ['type' => $type, 'start' => $start, 'children' => $count * 2];
        } elseif ($type === 'O' || $type === 'C') {
            $length = self::number($data, $offset);
            self::expect($data, $offset, ':"');
            if ($length > strlen($data) - $offset) throw new UnexpectedValueException('Invalid native class name.');
            $offset += $length;
            self::expect($data, $offset, '":');
            $count = self::number($data, $offset);
            self::expect($data, $offset, ':{');
            $token = ['type' => $type, 'start' => $start];
            if ($type === 'C') {
                if ($count > strlen($data) - $offset) throw new UnexpectedValueException('Invalid native object payload.');
                $offset += $count;
                self::expect($data, $offset, '}');
            } else {
                if ($count > intdiv(strlen($data) - $offset, 2)) throw new UnexpectedValueException('Invalid native property count.');
                $token['children'] = $count * 2;
            }
        } else {
            throw new UnexpectedValueException('Unknown native serialization value.');
        }
        $token += ['body' => 0, 'bytes' => 0];
        $token['length'] = $offset - $start;
        return $token;
    }

    private static function number(string $data, int &$offset): int
    {
        $start = $offset;
        $number = 0;
        $limit = strlen($data);
        while (isset($data[$offset]) && $data[$offset] >= '0' && $data[$offset] <= '9') {
            $digit = ord($data[$offset++]) - 48;
            if ($number > intdiv($limit - $digit, 10)) throw new UnexpectedValueException('Native length exceeds its payload.');
            $number = $number * 10 + $digit;
        }
        if ($offset === $start) throw new UnexpectedValueException('Missing native length.');
        return $number;
    }

    private static function expect(string $data, int &$offset, string $expected): void
    {
        if (substr($data, $offset, strlen($expected)) !== $expected) throw new UnexpectedValueException('Malformed native session data.');
        $offset += strlen($expected);
    }

    /** @param Token $field */
    private static function text(string $data, array $field): string
    {
        $text = substr($data, $field['body'], $field['bytes']);
        if ($field['type'] !== 'S') return $text;
        $out = '';
        for ($offset = 0; $offset < strlen($text); ++$offset) {
            if ($text[$offset] === '\\') {
                $out .= chr((int) hexdec(substr($text, $offset + 1, 2)));
                $offset += 2;
            } else $out .= $text[$offset];
        }
        return $out;
    }

    /** @param Token $field */
    private static function integer(string $data, array $field): int
    {
        $number = substr($data, $field['body'], $field['bytes']);
        $negative = str_starts_with($number, '-');
        $digits = ltrim($number, '+-0');
        $limit = $negative ? substr((string) PHP_INT_MIN, 1) : (string) PHP_INT_MAX;
        if (strlen($digits) > strlen($limit) || (strlen($digits) === strlen($limit) && strcmp($digits, $limit) > 0)) throw new UnexpectedValueException('Native integer exceeds its runtime range.');
        return (int) $number;
    }

    /** @param Token|null $field */
    private static function userId(string $data, ?array $field): int
    {
        if ($field === null || $field['type'] === 'N') return 0;
        if ($field['type'] === 'b' && substr($data, $field['body'], $field['bytes']) === '0') return 0;
        if ($field['type'] === 'i') $value = self::integer($data, $field);
        elseif (in_array($field['type'], ['s', 'S'], true)) {
            $text = self::text($data, $field);
            if ($text === '') return 0;
            if (preg_match('/\A[0-9]+\z/D', $text) !== 1) throw new UnexpectedValueException('Invalid native session identity.');
            $digits = ltrim($text, '0');
            $limit = PHP_INT_SIZE < 8 ? (string) PHP_INT_MAX : '4294967295';
            if (strlen($digits) > strlen($limit) || (strlen($digits) === strlen($limit) && strcmp($digits, $limit) > 0)) throw new UnexpectedValueException('Native session identity exceeds its column.');
            $value = (int) $text;
        } else throw new UnexpectedValueException('Unresolved native session identity.');
        if ($value < 0 || $value > 4294967295) throw new UnexpectedValueException('Native session identity exceeds its column.');
        return $value;
    }

    /** @param Token|null $field */
    private static function binding(string $data, ?array $field): ?string
    {
        if ($field === null || $field['type'] === 'N') return null;
        if (!in_array($field['type'], ['s', 'S'], true)) throw new UnexpectedValueException('Invalid native session binding.');
        return hash('sha256', self::text($data, $field));
    }
}
