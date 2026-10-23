<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Kadupul\Tests\Graphing\Fakes;

use Kadupul\Graphing\Application\Port\RrdFileInfo;
use Kadupul\Graphing\Application\Port\RrdTarget;
use Kadupul\Graphing\Application\Port\RrdTargetInspector;
use Kadupul\Graphing\Application\Port\RrdTargetLocator;
use Kadupul\Graphing\Application\Port\RrdUpdater;
use Kadupul\Graphing\Application\Port\StorageRejected;
use Kadupul\Graphing\Domain\RrdCommand;
use Kadupul\Monitoring\Contract\StorageReference;
use Kadupul\Telemetry\Contract\RejectionReason;

/**
 * In-memory RRD files behind all three storage ports. Like rrdtool, an
 * update applies rows in order and stops at the first one that is not newer
 * than the last, keeping the rows before it. Every port call is logged so a
 * test can prove storage was never touched.
 */
final class FakeRrdFiles implements RrdTargetLocator, RrdTargetInspector, RrdUpdater
{
    /** @var array<string, array{path: string, lastUpdate: int, types: array<string, string>}> keyed by storage reference */
    private array $files = [];

    /** @var array<string, StorageRejected> keyed by storage reference */
    private array $locateRefusals = [];

    /** @var array<string, array{int, StorageRejected}> keyed by path: row index that fails, and the failure */
    private array $updateFailures = [];

    /** @var list<string> */
    public array $log = [];

    /** @var array<string, list<list<string>>> update arguments by path */
    public array $updates = [];

    /** @param array<string, string> $types data-source name to RRD type */
    public function add(string $reference, array $types, int $lastUpdate = 999999000): void
    {
        $path = '/srv/rrd-test/' . str_replace(':', '_', $reference) . '.rrd';
        $this->files[$reference] = ['path' => $path, 'lastUpdate' => $lastUpdate, 'types' => $types];
    }

    public function refuseLocation(string $reference, StorageRejected $refusal): void
    {
        $this->locateRefusals[$reference] = $refusal;
    }

    public function failUpdate(string $reference, int $rowIndex, StorageRejected $failure): void
    {
        $this->updateFailures[$this->files[$reference]['path']] = [$rowIndex, $failure];
    }

    public function lastUpdate(string $reference): int
    {
        return $this->files[$reference]['lastUpdate'];
    }

    public function locate(StorageReference $reference): RrdTarget
    {
        $this->log[] = 'locate ' . $reference->toString();
        if (isset($this->locateRefusals[$reference->toString()])) {
            throw $this->locateRefusals[$reference->toString()];
        }
        if (!isset($this->files[$reference->toString()])) {
            throw new StorageRejected(RejectionReason::TargetMissing, 'No RRD file exists for this storage reference.');
        }

        return new RrdTarget($this->files[$reference->toString()]['path']);
    }

    public function inspect(RrdTarget $target): RrdFileInfo
    {
        $this->log[] = 'inspect ' . $target->path;
        $file = $this->byPath($target->path);

        return new RrdFileInfo($file['lastUpdate'], $file['types']);
    }

    public function update(RrdTarget $target, RrdCommand $command): void
    {
        $this->log[] = 'update ' . $target->path;
        $this->updates[$target->path][] = $command->arguments;
        $reference = $this->referenceFor($target->path);
        $rows = array_slice($command->arguments, 2);
        foreach ($rows as $index => $row) {
            if (isset($this->updateFailures[$target->path]) && $this->updateFailures[$target->path][0] === $index) {
                throw $this->updateFailures[$target->path][1];
            }
            $second = (int) explode(':', $row, 2)[0];
            if ($second <= $this->files[$reference]['lastUpdate']) {
                throw new StorageRejected(RejectionReason::OutOfOrder, 'RRDtool refused a row at or before the last row in the file.');
            }
            $this->files[$reference]['lastUpdate'] = $second;
        }
    }

    /** @return array{path: string, lastUpdate: int, types: array<string, string>} */
    private function byPath(string $path): array
    {
        return $this->files[$this->referenceFor($path)];
    }

    private function referenceFor(string $path): string
    {
        foreach ($this->files as $reference => $file) {
            if ($file['path'] === $path) {
                return $reference;
            }
        }
        throw new StorageRejected(RejectionReason::TargetMissing, 'The RRD file has gone away.');
    }
}
