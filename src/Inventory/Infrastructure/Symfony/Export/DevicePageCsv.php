<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Inventory\Infrastructure\Symfony\Export;

use Kadupul\Inventory\Application\ReadModel\DevicePage;

/**
 * Encodes one page of the device list as CSV.
 */
final class DevicePageCsv
{
    /**
     * Returns the page as UTF-8 CSV with a byte order mark and CRLF line endings.
     * Text columns are prefixed with an apostrophe so spreadsheet programs treat them as literal text rather than formulas. Nothing is written to disk.
     */
    public function encode(DevicePage $page): string
    {
        // The query bounds the page to 100 rows. No export files are persisted.
        $stream = new \SplTempFileObject();
        $stream->fwrite("\xEF\xBB\xBF");
        $stream->fputcsv(['ID', 'Name', 'Hostname', 'Status', 'Location', 'External ID'], ',', '"', '', "\r\n");
        foreach ($page->devices as $device) {
            // Mark all operator-controlled text as literal spreadsheet text,
            // including formulas preceded by whitespace or control characters.
            $stream->fputcsv([
                $device->id,
                "'" . $device->description,
                "'" . $device->hostname,
                $device->disabled ? 'Disabled' : $device->status,
                "'" . $device->location,
                "'" . $device->externalId,
            ], ',', '"', '', "\r\n");
        }
        $stream->rewind();
        $content = '';
        while (!$stream->eof()) {
            $content .= $stream->fgets();
        }

        return $content;
    }
}
