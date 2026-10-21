<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Graphing\Domain;

/** A storage and polling profile: the step, heartbeat and archives every new RRDfile is created with. */
final readonly class DataSourceProfile
{
    public const int MAX_NAME = 255;

    /** Choices of the legacy Polling Interval drop-down, in seconds. */
    public const array SAMPLING_INTERVALS = [10, 15, 20, 30, 60, 300, 600, 900, 1200, 1800, 3600, 7200, 14400, 28800, 43200, 86400];

    /** Choices of the legacy Heartbeat drop-down, in seconds. */
    public const array HEARTBEATS = [20, 30, 40, 45, 60, 90, 120, 300, 330, 600, 660, 900, 1200, 1800, 2400, 3600, 7200, 14400, 28800, 57600, 86400, 172800];

    public const array CONSOLIDATION_FUNCTIONS = [1 => 'AVERAGE', 2 => 'MIN', 3 => 'MAX', 4 => 'LAST'];

    /**
     * @param list<int> $consolidationFunctions
     * @param list<DataSourceProfileRra> $archives in steps order
     */
    public function __construct(
        public int $id,
        public string $name,
        public int $step,
        public int $heartbeat,
        public float $xFilesFactor,
        public bool $isDefault,
        public int $dataSources = 0,
        public int $templates = 0,
        public string $revision = '',
        public array $consolidationFunctions = [],
        public array $archives = [],
    ) {}

    /** Local data sources already have RRDfiles built from the step, factor, functions and archives. */
    public function isReadOnly(): bool
    {
        return $this->dataSources > 0;
    }

    /** New data templates take the default profile, so it is kept with the in-use ones. */
    public function isDeletable(): bool
    {
        return $this->dataSources === 0 && $this->templates === 0 && !$this->isDefault;
    }

    public static function validateName(string $name): void
    {
        if (trim($name) === '' || mb_strlen($name, 'UTF-8') > self::MAX_NAME || preg_match('//u', $name) !== 1 || preg_match('/[\x00\r\n]/', $name) === 1) {
            throw new \InvalidArgumentException('Enter a profile name of at most 255 characters.');
        }
    }

    /** RRDtool refuses a factor outside [0, 1); the legacy form stored any text. */
    public static function xFilesFactor(string $value): float
    {
        if (preg_match('/\A(?:0(?:\.[0-9]{1,10})?|\.[0-9]{1,10})\z/D', $value) !== 1) {
            throw new \InvalidArgumentException('Enter an X-Files Factor from 0 up to but not including 1.');
        }
        return (float) $value;
    }

    /** The legacy duplicate action substitutes <profile_title> in the submitted format. */
    public static function duplicateName(string $titleFormat, string $name): string
    {
        $duplicate = str_replace('<profile_title>', $name, $titleFormat);
        self::validateName($duplicate);
        return $duplicate;
    }
}
