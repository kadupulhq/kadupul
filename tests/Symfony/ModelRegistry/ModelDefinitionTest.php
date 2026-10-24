<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Kadupul\Tests\ModelRegistry;

use Kadupul\ModelRegistry\Domain\Checksum;
use Kadupul\ModelRegistry\Domain\InvalidModelDefinition;
use Kadupul\ModelRegistry\Domain\Model;
use Kadupul\ModelRegistry\Domain\ModelFamily;
use Kadupul\ModelRegistry\Domain\ModelIdentity;
use Kadupul\ModelRegistry\Domain\ModelRevision;
use Kadupul\ModelRegistry\Domain\Provenance;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ModelDefinitionTest extends TestCase
{
    public function testUnknownFamilyIsRejectedWithTheAcceptedValues(): void
    {
        $this->expectException(InvalidModelDefinition::class);
        $this->expectExceptionMessage('yang, openconfig, redfish, snmp-mib');
        ModelIdentity::parse('netconf', 'urn:example:x', 'x');
    }

    #[DataProvider('badIdentityParts')]
    public function testMalformedNamespaceOrNameIsRejected(string $namespace, string $name): void
    {
        $this->expectException(InvalidModelDefinition::class);
        ModelIdentity::parse('yang', $namespace, $name);
    }

    public static function badIdentityParts(): iterable
    {
        yield 'empty namespace' => ['', 'ietf-interfaces'];
        yield 'empty name' => ['urn:ietf', ''];
        yield 'name with space' => ['urn:ietf', 'ietf interfaces'];
        yield 'name starting with digit' => ['urn:ietf', '1interfaces'];
        yield 'namespace with separator' => ['urn|ietf', 'x'];
        yield 'name with newline' => ['urn:ietf', "x\n"];
    }

    #[DataProvider('badRevisions')]
    public function testRevisionIsValidatedPerFamily(string $family, string $revision): void
    {
        $this->expectException(InvalidModelDefinition::class);
        ModelRevision::parse(ModelFamily::fromString($family), $revision);
    }

    public static function badRevisions(): iterable
    {
        yield 'yang not a date' => ['yang', '1.0.0'];
        yield 'yang impossible date' => ['yang', '2024-02-30'];
        yield 'yang trailing newline' => ['yang', "2024-02-01\n"];
        yield 'openconfig short date' => ['openconfig', '2024-2-1'];
        yield 'redfish empty' => ['redfish', ''];
        yield 'redfish whitespace' => ['redfish', 'v1 2'];
        yield 'snmp-mib leading dot' => ['snmp-mib', '.1'];
    }

    public function testFreeFormRevisionsAreAcceptedForRedfishAndSnmpMib(): void
    {
        self::assertSame('v1_2_0', ModelRevision::parse(ModelFamily::Redfish, 'v1_2_0')->value());
        self::assertSame('202301010000Z', ModelRevision::parse(ModelFamily::SnmpMib, '202301010000Z')->value());
        self::assertSame('2024-01-15', ModelRevision::parse(ModelFamily::OpenConfig, '2024-01-15')->value());
    }

    public function testRevisionsCompareChronologically(): void
    {
        $older = ModelFixtures::revision('2023-12-31');
        $newer = ModelFixtures::revision('2024-01-01');
        self::assertLessThan(0, $older->compareTo($newer));
        self::assertGreaterThan(0, $newer->compareTo($older));
        self::assertSame(0, $newer->compareTo(ModelFixtures::revision('2024-01-01')));
    }

    public function testNumericRevisionsCompareByVersionNotByString(): void
    {
        $nine = ModelFixtures::revision('1.9.0', 'redfish');
        $ten = ModelFixtures::revision('1.10.0', 'redfish');
        self::assertLessThan(0, $nine->compareTo($ten));
    }

    public function testRevisionsOfDifferentFamiliesAreNotComparable(): void
    {
        $this->expectException(InvalidModelDefinition::class);
        ModelFixtures::revision('2024-01-01')->compareTo(ModelFixtures::revision('2024-01-01', 'openconfig'));
    }

    #[DataProvider('badChecksums')]
    public function testChecksumMustBeLowercaseSha256Hex(string $value): void
    {
        $this->expectException(InvalidModelDefinition::class);
        Checksum::fromHex($value);
    }

    public static function badChecksums(): iterable
    {
        yield 'empty' => [''];
        yield 'too short' => [str_repeat('a', 63)];
        yield 'too long' => [str_repeat('a', 65)];
        yield 'not hex' => [str_repeat('g', 64)];
        yield 'uppercase' => [str_repeat('A', 64)];
        yield 'trailing newline' => [str_repeat('a', 64) . "\n"];
    }

    public function testProvenanceRequiresASourceAndAnAbsoluteOriginUri(): void
    {
        $at = new \DateTimeImmutable('2026-05-01T10:00:00+00:00');
        self::assertNull((new Provenance('vendor-repo', $at, null))->originUri());
        foreach ([['', null], ['vendor-repo', 'not a uri'], ['vendor-repo', '/relative/path']] as [$source, $uri]) {
            try {
                new Provenance($source, $at, $uri);
                self::fail('Expected provenance to be rejected');
            } catch (InvalidModelDefinition) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testModelRejectsBlankCapabilitiesDuplicateDependenciesAndSelfDependency(): void
    {
        $cases = [
            'blank capability' => static fn(): Model => ModelFixtures::model('a', capabilities: ['']),
            'duplicate dependency' => static fn(): Model => ModelFixtures::model('a', dependencies: [ModelFixtures::dependsOn('b'), ModelFixtures::dependsOn('b', '2020-01-01')]),
            'self dependency' => static fn(): Model => ModelFixtures::model('a', dependencies: [ModelFixtures::dependsOn('a')]),
        ];
        foreach ($cases as $label => $build) {
            try {
                $build();
                self::fail($label . ' was accepted');
            } catch (InvalidModelDefinition) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testDependencyOnAnotherFamilyRevisionKindIsRejected(): void
    {
        $this->expectException(InvalidModelDefinition::class);
        new \Kadupul\ModelRegistry\Domain\ModelDependency(ModelFixtures::identity('b'), ModelFixtures::revision('1.0.0', 'redfish'));
    }
}
