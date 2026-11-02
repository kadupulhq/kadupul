<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2004-2026 The Cacti Group
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

namespace Kadupul\Platform\Infrastructure\Legacy;

use Symfony\Component\Process\Process;

/** Read bundled package metadata without importing a package or invoking a shell. */
final readonly class InstallerTemplateCatalog
{
    /** Bundled package order is part of the native selection contract. */
    private const array PACKAGES = [
        'ACME.xml.gz',
        'AKCP_Device.xml.gz',
        'APC_InfraStruXure_InRow_CRAC.xml.gz',
        'APC_InfraStruXure_PDU.xml.gz',
        'Apache_Webserver.xml.gz',
        'ArubaOS_switch.xml.gz',
        'Aruba_OSCX_switch_6x00.xml.gz',
        'Aruba_Instant_AP_Cluster.xml.gz',
        'Aruba_Wireless_Controller.xml.gz',
        'BayTech_PDU.xml.gz',
        'Cacti_Stats.xml.gz',
        'Cisco_Router.xml.gz',
        'Citrix_NetScaler_VPX.xml.gz',
        'Clearpass_policy_manager.xml.gz',
        'Dell_iDrac.xml.gz',
        'ESXi_Device.xml.gz',
        'Fortigate.xml.gz',
        'Generic_SNMP_Device.xml.gz',
        'HPE_iLO.xml.gz',
        'HPE_NimbleAlletra_storage.xml.gz',
        'Local_Linux_Machine.xml.gz',
        'MikroTik_Device.xml.gz',
        'MikroTik_Switch_SWOS.xml.gz',
        'Motorola_SB6141.xml.gz',
        'NetSNMP_Device.xml.gz',
        'PING_Advanced_Ping.xml.gz',
        'SNMP_Printer.xml.gz',
        'SNMP_UPS.xml.gz',
        'Synology_NAS.xml.gz',
        'Windows_Device.xml.gz'
    ];

    /** Keep all package paths relative to the trusted release directory. */
    public function __construct(private string $projectDir) {}

    /**
     * The historical filename/name keys are package identities, not display-name guesses.
     * @return list<array<string, mixed>>
     */
    public function templates(?string $php = null, bool $web = false): array
    {
        if ($web) {
            ini_set('zlib.output_compression', '0');
        }
        $unpack = extension_loaded('simplexml') && extension_loaded('zlib');
        $result = [];
        foreach (self::PACKAGES as $name) {
            $file = $this->projectDir . '/install/templates/' . $name;
            $info = $unpack ? $this->read($file) : $this->worker($file, $php);
            $info['filename'] = $name;
            $info['name'] = $name;
            $result[] = $info;
        }
        return $result;
    }

    /** Preserve recursive object conversion for pre-Composer package consumers. */
    public static function toArray(mixed $value): mixed
    {
        if (is_object($value)) {
            $value = get_object_vars($value);
        }
        return is_array($value) ? array_map(self::toArray(...), $value) : $value;
    }

    /** @return array<string, mixed> */
    private function read(string $file): array
    {
        $bytes = @file_get_contents('compress.zlib://' . $file);
        if ($bytes === false) {
            throw new \RuntimeException('A bundled installation package could not be read.');
        }
        $previous = libxml_use_internal_errors(true);
        try {
            $xml = simplexml_load_string($bytes, options: LIBXML_NONET);
            if ($xml === false) {
                throw new \RuntimeException('A bundled installation package is malformed.');
            }
            $data = self::toArray($xml);
            if (!is_array($data) || !is_array($data['info'] ?? null)) {
                throw new \RuntimeException('A bundled installation package has no metadata.');
            }
            $info = $data['info'];
            foreach (['author' => '1', 'email' => '2', 'description' => '3', 'homepage' => '4'] as $key => $fallback) {
                if (is_array($info[$key] ?? null)) {
                    $info[$key] = $fallback;
                }
            }
            return $this->metadata($info);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }

    /**
     * Runtimes without the optional XML reader use the fixed package-info command.
     * @return array<string, mixed>
     */
    private function worker(string $file, ?string $php): array
    {
        if ($php === null || str_contains($php, "\0")
            || !(str_starts_with($php, '/') || preg_match('/\A[A-Za-z]:[\\\\\/]/', $php) === 1)
            || !is_file($php) || !is_executable($php)) {
            throw new \RuntimeException('The configured PHP executable is required to read package metadata.');
        }
        $output = '';
        $errorBytes = 0;
        $process = new Process([$php, '-q', $this->projectDir . '/cli/import_package.php', '--filename=' . $file, '--info'], $this->projectDir, timeout: 15);
        $process->run(static function (string $type, string $chunk) use (&$output, &$errorBytes): void {
            if ($type === Process::OUT) {
                $output .= $chunk;
                if (strlen($output) > 1048576) {
                    throw new \RuntimeException('Installation package metadata exceeds its output budget.');
                }
            } else {
                $errorBytes += strlen($chunk);
                if ($errorBytes > 65536) {
                    throw new \RuntimeException('Installation package metadata exceeds its diagnostic budget.');
                }
            }
        });
        if (!$process->isSuccessful() || $errorBytes !== 0) {
            throw new \RuntimeException('Installation package metadata could not be confirmed.');
        }
        $info = json_decode($output, true, 32, JSON_THROW_ON_ERROR);
        if (!is_array($info)) {
            throw new \RuntimeException('Invalid installation package metadata response.');
        }
        return $this->metadata($info);
    }

    /** @param array<array-key, mixed> $info
     *  @return array<string, mixed>
     */
    private function metadata(array $info): array
    {
        $result = [];
        foreach ($info as $key => $value) {
            if (!is_string($key)) {
                throw new \RuntimeException('Invalid installation package metadata key.');
            }
            $result[$key] = $value;
        }
        return $result;
    }
}
