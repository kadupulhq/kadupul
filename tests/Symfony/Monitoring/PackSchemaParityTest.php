<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Kadupul\Tests\Monitoring;

use Kadupul\Monitoring\Domain\PackValidator;
use PHPUnit\Framework\TestCase;

/**
 * PackValidator writes the schema's rules out by hand. This holds it to the
 * schema itself: on every case in PackValidatorTest, jsonschema must reject
 * what PHP rejects structurally, and accept what only the semantic rules reject.
 */
final class PackSchemaParityTest extends TestCase
{
    private const SEMANTIC_ONLY = [
        'duplicate metric id',
        'graph names an unknown metric',
        'alert names an unknown metric',
        'default interval below minimum',
        'preferred protocol nothing maps',
        'kadupul range excludes the running version',
        'kadupul range cannot be parsed',
    ];

    /**
     * JSON Schema specifies ECMA-262 patterns, where $ matches only at the end.
     * Python's re, which jsonschema uses, also matches $ before a final
     * newline, so it accepts "openconfig-interfaces\n" as a pack name.
     * PackValidator follows ECMA-262 and rejects it.
     */
    private const PYTHON_IS_LENIENT = ['trailing newline is not accepted by an anchored pattern'];

    protected function setUp(): void
    {
        $reason = JsonSchemaCheck::skipReason();
        if ($reason !== null) {
            self::markTestSkipped($reason);
        }
    }

    public function testThePhpValidatorAndJsonSchemaAgreeOnEveryCase(): void
    {
        $names = [];
        $documents = [json_encode(PackDocuments::example(), JSON_THROW_ON_ERROR)];
        foreach (PackValidatorTest::invalidDocuments() as $name => [$edit]) {
            $document = PackDocuments::example();
            $edit($document);
            $names[] = $name;
            $documents[] = json_encode($document, JSON_THROW_ON_ERROR);
        }
        $verdicts = JsonSchemaCheck::errors(PackDocuments::PACK_SCHEMA, $documents);

        self::assertSame([], $verdicts[0], 'The shipped example must satisfy its schema.');
        $validator = new PackValidator('1.2.35');
        foreach ($names as $i => $name) {
            $schemaErrors = $verdicts[$i + 1];
            self::assertNotSame([], $validator->validate(json_decode($documents[$i + 1], false, 512, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING)), $name);
            if (in_array($name, self::PYTHON_IS_LENIENT, true)) {
                self::assertSame([], $schemaErrors, $name . ': jsonschema now rejects this; move it out of PYTHON_IS_LENIENT.');
            } elseif (in_array($name, self::SEMANTIC_ONLY, true)) {
                self::assertSame([], $schemaErrors, $name . ' is a semantic rule; the schema alone must accept it.');
            } else {
                self::assertNotSame([], $schemaErrors, $name . ' was rejected by PackValidator but accepted by the schema.');
            }
        }
        self::assertCount(count(self::SEMANTIC_ONLY), array_intersect(self::SEMANTIC_ONLY, $names), 'A semantic case was renamed or removed.');
    }
}
