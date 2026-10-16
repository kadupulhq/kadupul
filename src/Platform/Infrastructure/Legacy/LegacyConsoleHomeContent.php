<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Platform\Infrastructure\Legacy;

use Kadupul\IdentityAccess\Contract\Actor;
use Kadupul\Platform\Application\Port\ConsoleHomeContent;
use Kadupul\Platform\Application\ReadModel\ConsoleCollectorStatus;
use Kadupul\Platform\Application\ReadModel\ConsoleCompatibilityContent;
use Kadupul\Platform\Application\ReadModel\ConsoleHomeContent as Content;

final readonly class LegacyConsoleHomeContent implements ConsoleHomeContent
{
    public function __construct(private LegacyBrowserWorker $worker) {}

    public function forActor(Actor $actor): Content
    {
        $result = $this->worker->console($actor->id);
        if (!is_bool($result['full_page'] ?? null) || !is_bool($result['header_content'] ?? null)) {
            throw new \RuntimeException('Invalid console framing.');
        }
        if (!is_array($result['chrome'] ?? null) || array_keys($result['chrome']) !== ['title', 'tabs', 'graph_tabs', 'breadcrumb', 'login', 'menu']
            || count(array_filter($result['chrome'], 'is_string')) !== 6) {
            throw new \RuntimeException('Invalid console navigation output.');
        }
        foreach (['head', 'before', 'after', 'bottom'] as $key) {
            if (!is_string($result[$key] ?? null)) {
                throw new \RuntimeException('Invalid console extension output.');
            }
        }
        if (!is_string($result['nonce'] ?? null) || preg_match('/\A[A-Za-z0-9_-]{24}\z/D', $result['nonce']) !== 1
            || !is_bool($result['show_introduction'] ?? null) || !array_key_exists('collector', $result)
            || !is_array($result['policies'] ?? null) || $result['policies'] === []) {
            throw new \RuntimeException('Invalid console state.');
        }
        foreach ($result['policies'] as $name => $value) {
            if (!in_array($name, ['Content-Security-Policy', 'Content-Security-Policy-Report-Only'], true)
                || !is_string($value) || strlen($value) > 16384 || preg_match('/[\x00-\x1f\x7f]/', $value)) {
                throw new \RuntimeException('Invalid console security policy.');
            }
        }
        $collector = null;
        if ($result['collector'] !== null) {
            $status = $result['collector'];
            if (!is_array($status) || !is_string($status['connection'] ?? null) || !array_key_exists('queued', $status)
                || $status['queued'] !== null && !is_int($status['queued'])) {
                throw new \RuntimeException('Invalid console collector state.');
            }
            $collector = new ConsoleCollectorStatus($status['connection'], $status['queued']);
        }

        return new Content($result['show_introduction'], $collector, new ConsoleCompatibilityContent(
            $result['head'],
            $result['before'],
            $result['after'],
            $result['bottom'],
            $result['policies'],
            $result['nonce'],
            $result['chrome'],
            $result['full_page'],
            $result['header_content'],
        ));
    }
}
