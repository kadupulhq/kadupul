<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

namespace Kadupul\Inventory\Domain;

final readonly class DeviceTemplateDefinition
{
    public const CLASSES = ['wireless', 'application', 'cacti', 'database', 'facilities', 'general', 'hpc', 'hypervisor', 'remotemgmt', 'license', 'linux', 'loadbalancer', 'switch', 'router', 'nassan', 'firewall', 'power', 'printer', 'storage', 'telephony', 'webserver', 'windows', 'ups', 'unassigned'];
    public function __construct(public int $id, public string $name, public string $class, public array $graphs = [], public array $queries = []) {}
    public function revision(): string
    {
        return hash('sha256', json_encode([$this->id, $this->name, $this->class, $this->graphs, $this->queries], JSON_THROW_ON_ERROR));
    }
    public static function validate(array $data): array
    {
        $name = $data['name'] ?? null;
        $class = $data['class'] ?? null;
        if (!is_string($name) || trim($name) === '' || mb_strlen($name) > 255 || !mb_check_encoding($name, 'UTF-8') || str_contains($name, "\0")
            || !is_string($class) || ($class !== '' && !in_array($class, self::CLASSES, true))) {
            throw new \InvalidArgumentException('Invalid device template fields.');
        }
        return ['name' => $name, 'class' => $class];
    }
}
