<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Tests;

use Kadupul\Automation\Domain\DiscoveryRange;
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__) . '/Helpers/PhpSource.php';

final class DiscoveryRangeTest extends TestCase
{
    /** Spellings legacy handles consistently: the new parser must count exactly what poller_automation.php scans. */
    private const array SHARED = ['192.168.1.0/24', ' 10.0.0.0/8 ', '10.1.2.3/24', '10.2.0.0/22', '172.16.0.0/255.255.0.0', '192.168.1.0/255.255.255.0', '192.168.1.7/32',
        '192.168.1.6/31', '10.0.0.0/30', '0.0.0.0/1', '255.255.255.255/32', '10.0.0.0/255.255.255.255', '10.0.0.0/255.255.255.254', '10.0.0.0/128.0.0.0',
        '10.1.*.*', '10.*.*.*', '10.1.2.*', '10.*.5.6', '192.168.1.10', '0.0.0.0', '255.255.255.255', '127.0.0.1', '127.0.0.0/24', '169.254.169.254', '169.254.0.0/16', '224.0.0.0/24',
        '', ' ', '10.0.0.0/0', '10.0.0.0/33', '10.0.0.0/255.0.255.0', '10.0.0.0/0.0.0.0', '10.0.0.0/255.255.255.256', '300.1.1.1/24', '10.0.0/24',
        '*.1.2.3', '10.1*.*.0', '10.1.*', 'host.example.net', '10.0.0.1-10.0.0.9', 'a.b.c.d', '192.168.1', '01.2.3.4/24'];

    public function testCountsMatchTheLegacyNetworkCalculator(): void
    {
        $legacy = $this->legacyCounts(self::SHARED);
        foreach (self::SHARED as $range) {
            self::assertSame($legacy[$range], DiscoveryRange::addresses($range), "'$range'");
        }
        // The corpus reaches every kind of allowed range (/32, /24, /22, /16) and refuses disallowed/oversized ranges.
        self::assertSame([1, 254, 1022, 65534], array_values(array_intersect([1, 254, 1022, 65534], array_filter($legacy, 'is_int'))));
        self::assertGreaterThan(15, count(array_filter($legacy, 'is_null')));
    }

    public function testSpellingsLegacyMiscountedAreRefused(): void
    {
        // Legacy read only the first two parts of a/b/c and truncated exponent/whitespace prefix lengths.
        $refused = ['10.0.0.0/24/8', '10.0.0.0/2e1', '10.0.0.0/ 24'];
        foreach ($this->legacyCounts($refused) as $range => $count) {
            self::assertIsInt($count, "legacy counted '$range'");
            self::assertNull(DiscoveryRange::addresses($range), $range);
        }
        foreach (['::1/24', '10.0.0.0/24.5'] as $range) {
            self::assertNull(DiscoveryRange::addresses($range), $range);
        }
    }

    public function testTotalsAddEveryRangeAndNameTheFirstInvalidOne(): void
    {
        self::assertSame([254 + 1 + 65534, null], DiscoveryRange::total('192.168.1.0/24,10.0.0.1, 10.1.*.*'));
        self::assertSame([null, '10.0.0.0/33'], DiscoveryRange::total('192.168.1.0/24, 10.0.0.0/33 ,nonsense'));
        // A trailing comma leaves an empty range, which legacy also refused.
        self::assertSame([null, ''], DiscoveryRange::total('192.168.1.0/24,'));
        // Ranges exceeding TOTAL_MAX or overlapping reserved/loopback/link-local space are rejected.
        self::assertSame([null, '10.3.0.0/16'], DiscoveryRange::total('10.1.0.0/16,10.2.0.0/16,10.3.0.0/16'));
        self::assertSame([null, '0.0.0.0/1'], DiscoveryRange::total('0.0.0.0/1,128.0.0.0/1'));
        self::assertSame([null, '169.254.169.254/32'], DiscoveryRange::total('192.168.1.0/24,169.254.169.254/32'));
        self::assertSame([null, '127.0.0.1'], DiscoveryRange::total('127.0.0.1'));
    }

    /**
     * @param list<string> $ranges
     * @return array<string, ?int>
     */
    private function legacyCounts(array $ranges): array
    {
        $source = (string) file_get_contents(dirname(__DIR__, 2) . '/lib/api_automation.php');
        $functions = '';
        foreach (['automation_get_valid_ip', 'automation_get_valid_subnet_cidr', 'automation_get_valid_mask', 'automation_get_network_info', 'automation_calculate_total_ips'] as $name) {
            $functions .= test_php_function_source($source, $name) . "\n";
        }
        $stubs = 'const MESSAGE_LEVEL_ERROR = 3; function automation_debug($m) {} function raise_message(...$a) {} function __($m) { return $m; }'
            . ' function cacti_sizeof($v) { return is_array($v) || $v instanceof Countable ? count($v) : 0; }';
        $code = $stubs . $functions . '$out = []; foreach (json_decode($argv[1], true) as $r) { $c = automation_calculate_total_ips($r); $out[] = $c === false ? null : $c; }'
            . ' echo json_encode($out);';
        $result = test_php_run([PHP_BINARY, '-d', 'display_errors=stderr', '-r', $code, '--', json_encode($ranges, JSON_THROW_ON_ERROR)]);
        self::assertSame(0, $result['status'], $result['err']);
        $counts = json_decode($result['out'], true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($counts);
        self::assertCount(count($ranges), $counts);
        return array_combine($ranges, $counts);
    }
}
