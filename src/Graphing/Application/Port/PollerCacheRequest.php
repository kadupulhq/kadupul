<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Graphing\Application\Port;

/**
 * One run of cli/rebuild_poller_cache.php, as its arguments selected it. The
 * values are the trimmed text the operator typed, already checked the way the
 * script checked them, because the script passed that text on to the child
 * processes it started and to its SQL as typed.
 */
final readonly class PollerCacheRequest
{
    /** The process the script ran as when --type was not given. */
    public const string MASTER = 'rmaster';

    /** The process type the master starts for each slice of devices. */
    public const string CHILD = 'child';

    /**
     * @param ?string $hostId digits only and at least 1, or null for every device
     * @param ?string $hostTemplateId numeric, or null for every device template
     * @param ?string $dataTemplateId numeric, or null for every data template
     * @param ?int $threads at least 1, or null for the Poller Command Concurrent Processes setting
     * @param string $type rmaster, child, or any other text, which the script registered and did nothing for
     * @param ?string $child the thread number of a child as typed, or null for 0
     * @param bool $force run even while another run of the same type and thread is registered
     * @param bool $debug print the PUSHOUT: progress lines instead of one dot per device
     */
    public function __construct(
        public ?string $hostId = null,
        public ?string $hostTemplateId = null,
        public ?string $dataTemplateId = null,
        public ?int $threads = null,
        public string $type = self::MASTER,
        public ?string $child = null,
        public bool $force = false,
        public bool $debug = false,
    ) {}
}
