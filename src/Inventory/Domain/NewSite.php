<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Inventory\Domain;

final readonly class NewSite
{
    public const DEFAULTS = ['name' => '', 'address1' => '', 'address2' => '', 'city' => '', 'state' => '', 'postal_code' => '', 'country' => '', 'timezone' => '', 'latitude' => '', 'longitude' => '', 'zoom' => '12', 'alternate_id' => '', 'notes' => ''];
    public array $fields;

    public function __construct(array $values)
    {
        if (array_diff_key($values, self::DEFAULTS) !== []) {
            throw new \InvalidArgumentException('Unexpected fields were submitted.');
        }
        $fields = array_replace(self::DEFAULTS, $values);
        foreach ($fields as $value) {
            if (!is_string($value) || !mb_check_encoding($value, 'UTF-8') || str_contains($value, "\0")) {
                throw new \InvalidArgumentException('Site fields must contain valid text.');
            }
        }
        // Reuse the existing name/notes invariant instead of maintaining two rules.
        $site = new Site(0, '', '');
        $site->revise($fields['name'], $fields['notes'], $site->revision());
        $fields['name'] = $site->name();
        foreach (['address1' => 100, 'address2' => 100, 'city' => 50, 'state' => 20, 'postal_code' => 20, 'country' => 30, 'timezone' => 40, 'alternate_id' => 30] as $field => $limit) {
            if (mb_strlen($fields[$field], 'UTF-8') > $limit) {
                throw new \InvalidArgumentException('A site field exceeds its maximum length.');
            }
        }
        if ($fields['timezone'] !== '' && !in_array($fields['timezone'], \DateTimeZone::listIdentifiers(\DateTimeZone::ALL_WITH_BC), true)) {
            throw new \InvalidArgumentException('Select a valid timezone.');
        }
        foreach (['latitude' => 90, 'longitude' => 180] as $field => $limit) {
            $fields[$field] = trim($fields[$field]);
            if ($fields[$field] === '') {
                $fields[$field] = '0';
            }
            if (!preg_match('/^-?\d{1,3}(?:\.\d{1,10})?$/D', $fields[$field]) || abs((float) $fields[$field]) > $limit) {
                throw new \InvalidArgumentException('Latitude must be between -90 and 90 and longitude between -180 and 180, with at most 10 decimal places.');
            }
        }
        if ($fields['zoom'] === '') {
            $fields['zoom'] = '12';
        }
        if (!ctype_digit($fields['zoom']) || strlen($fields['zoom']) > 2 || (int) $fields['zoom'] > 23) {
            throw new \InvalidArgumentException('Map zoom must be between 0 and 23.');
        }
        $this->fields = $fields;
    }
}
