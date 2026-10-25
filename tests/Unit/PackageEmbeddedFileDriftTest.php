<?php

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

namespace PackageEmbeddedFileDriftTest;

/*
 * The device template packages in install/templates embed scripts and resource
 * XML, and importing a package writes them over the tree. A package copy that
 * differs from the tracked file therefore changes the installed behavior. The
 * packages are signed, so they cannot be edited here; they have to be
 * re-exported (see #611). Until then this test records the known differences
 * and fails when a new one appears, or when a listed file has been brought back
 * in line and should leave the list.
 */

/** Tracked files whose embedded copy in at least one package differs from the tree. */
const KNOWN_DRIFT = array(
    'resource/snmp_queries/interface.xml',
    'scripts/cacti_user_stats.php',
    'scripts/ss_count_oids.php',
    'scripts/ss_cpoller.php',
    'scripts/ss_fping.php',
    'scripts/ss_gexport.php',
    'scripts/ss_host_cpu.php',
    'scripts/ss_host_disk.php',
    'scripts/ss_hstats.php',
    'scripts/ss_net_snmp_disk_bytes.php',
    'scripts/ss_net_snmp_disk_io.php',
    'scripts/ss_netsnmp_lmsensors.php',
    'scripts/ss_poller.php',
    'scripts/ss_webseer.php',
);

/**
 * Compare every script and resource file embedded in a package with the tracked file at that path.
 *
 * @return array{packages: int, compared: int, drift: array<string, list<string>>}
 */
function scan_packages()
{
    $root     = dirname(__DIR__, 2);
    $packages = glob($root . '/install/templates/*.xml.gz');
    $compared = 0;
    $drift    = array();

    foreach ($packages as $package) {
        $xml = simplexml_load_string((string) gzdecode((string) file_get_contents($package)), 'SimpleXMLElement', LIBXML_NONET);

        if ($xml === false) {
            throw new \RuntimeException('Unreadable package ' . basename($package));
        }

        foreach ($xml->xpath('//file') as $file) {
            $name = trim((string) $file->name);

            if (preg_match('#^(scripts|resource)/#', $name) !== 1 || !is_file($root . '/' . $name)) {
                continue;
            }

            $compared++;

            if (file_get_contents($root . '/' . $name) !== base64_decode((string) $file->data)) {
                $drift[$name][] = basename($package, '.xml.gz');
            }
        }
    }

    ksort($drift);

    return array('packages' => count($packages), 'compared' => $compared, 'drift' => $drift);
}

test('the scan reads the shipped packages and finds tracked files to compare', function () {
    $scan = scan_packages();

    // An empty discovery must not pass as "no drift".
    expect($scan['packages'])->toBeGreaterThanOrEqual(30)
        ->and($scan['compared'])->toBeGreaterThan(50);
});

test('no package embeds a tracked file that differs, apart from the known differences', function () {
    $new = array_values(array_diff(array_keys(scan_packages()['drift']), KNOWN_DRIFT));

    expect($new)->toBe(array());
});

test('every known difference is still a difference', function () {
    $resolved = array_values(array_diff(KNOWN_DRIFT, array_keys(scan_packages()['drift'])));

    expect($resolved)->toBe(array());
});
