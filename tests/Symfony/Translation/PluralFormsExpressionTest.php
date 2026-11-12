<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Tests\Translation;

use Kadupul\Platform\Infrastructure\Symfony\PluralFormsExpression;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** The shipped rules are compared with php-gettext in GettextMoLoaderParityTest. */
final class PluralFormsExpressionTest extends TestCase
{
    /** @return iterable<string, array{string, array<int, int>}> */
    public static function rules(): iterable
    {
        yield 'single form' => ['nplurals=1; plural=0;', [0 => 0, 1 => 0, 7 => 0]];
        yield 'comparison yields 0 or 1' => ['nplurals=2; plural=n != 1;', [0 => 1, 1 => 0, 2 => 1]];
        yield 'multiplication before addition before comparison' => ['nplurals=3; plural=1 + 2 * n == 7 ? 2 : 0;', [3 => 2, 2 => 0]];
        yield 'conditional is right-associative' => ['nplurals=3; plural=n==0 ? 0 : n==1 ? 1 : 2;', [0 => 0, 1 => 1, 9 => 2]];
        yield 'and binds tighter than or' => ['nplurals=2; plural=n==1 || n==2 && n==3;', [1 => 1, 2 => 0, 3 => 0]];
        yield 'parentheses' => ['nplurals=2; plural=(n==1 || n==2) && n!=3;', [1 => 1, 2 => 1, 3 => 0]];
        yield 'logical not' => ['nplurals=2; plural=!(n==1);', [1 => 0, 4 => 1]];
        yield 'not of a value' => ['nplurals=2; plural=!n;', [0 => 1, 5 => 0]];
        yield 'integer division and remainder' => ['nplurals=4; plural=n/10 + n%2;', [7 => 1, 21 => 3, 30 => 3]];
        yield 'subtraction is left-associative' => ['nplurals=6; plural=9 - n - 3;', [1 => 5, 4 => 2]];
        yield 'relations' => ['nplurals=2; plural=n>=2 && n<=4 || n<1 && n>=0;', [0 => 1, 3 => 1, 5 => 0]];
        yield 'an index past the last form is the last form' => ['nplurals=2; plural=n;', [0 => 0, 1 => 1, 5 => 1]];
        yield 'a negative index is the last form' => ['nplurals=3; plural=n - 5;', [5 => 0, 2 => 2]];
        yield 'spacing and a missing final semicolon' => ["  nplurals = 2 ;\tplural = ( n > 1 )  ", [1 => 0, 2 => 1]];
    }

    /** @param array<int, int> $expected */
    #[DataProvider('rules')]
    public function testEvaluatesWithCSemantics(string $rule, array $expected): void
    {
        $expression = PluralFormsExpression::fromRule($rule);
        foreach ($expected as $n => $index) {
            self::assertSame($index, $expression->index($n), $rule . ' n=' . $n);
        }
    }

    public function testReadsTheRuleFromACatalogHeaderLikePhpGettext(): void
    {
        $header = "Language: ru\nplural-forms: nplurals=3; plural=n%10==1 && n%100!=11 ? 0 : n%10>=2 && n%10<=4 && (n%100<10 || n%100>=20) ? 1 : 2;\nX-Generator: probe\n";
        $expression = PluralFormsExpression::fromCatalogHeader($header);
        self::assertSame(3, $expression->count);
        self::assertSame([2, 0, 1, 2, 2, 0], array_map($expression->index(...), [0, 1, 2, 5, 11, 21]));
        // Without the header line php-gettext uses "n == 1 ? 0 : 1".
        $default = PluralFormsExpression::fromCatalogHeader("Language: de\n");
        self::assertSame([2, 1, 0, 1], [$default->count, $default->index(0), $default->index(1), $default->index(2)]);
        self::assertSame(1, PluralFormsExpression::fromCatalogHeader('')->index(5));
        // php-gettext needs the line to end with a newline; so does this.
        self::assertSame(2, PluralFormsExpression::fromCatalogHeader('Plural-Forms: nplurals=1; plural=0;')->count);
    }

    /** @return iterable<string, array{string}> */
    public static function invalid(): iterable
    {
        yield 'empty' => [''];
        yield 'no plural' => ['nplurals=2;'];
        yield 'no count' => ['plural=n != 1;'];
        yield 'zero forms' => ['nplurals=0; plural=0;'];
        yield 'too many forms' => ['nplurals=33; plural=n;'];
        yield 'empty expression' => ['nplurals=2; plural=;'];
        yield 'unknown operator' => ['nplurals=2; plural=n !== 1;'];
        yield 'assignment' => ['nplurals=2; plural=n = 1;'];
        yield 'bitwise operator' => ['nplurals=2; plural=n & 1;'];
        yield 'unary minus' => ['nplurals=2; plural=-n;'];
        yield 'variable' => ['nplurals=2; plural=$n;'];
        yield 'function call' => ['nplurals=2; plural=system(1);'];
        yield 'second statement' => ['nplurals=2; plural=n; exit;'];
        yield 'unclosed parenthesis' => ['nplurals=2; plural=(n != 1;'];
        yield 'extra parenthesis' => ['nplurals=2; plural=n != 1);'];
        yield 'conditional without else' => ['nplurals=2; plural=n ? 1;'];
        yield 'missing operand' => ['nplurals=2; plural=n !=;'];
        yield 'adjacent operands' => ['nplurals=2; plural=n 1;'];
        yield 'oversized number' => ['nplurals=2; plural=n > 1234567890;'];
        yield 'oversized rule' => ['nplurals=2; plural=' . str_repeat('(', 300) . 'n' . str_repeat(')', 300) . ';'];
    }

    #[DataProvider('invalid')]
    public function testRefusesAnythingButTheGettextGrammar(string $rule): void
    {
        $this->expectException(\InvalidArgumentException::class);
        PluralFormsExpression::fromRule($rule);
    }

    public function testRefusesAnInvalidRuleInAHeader(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        PluralFormsExpression::fromCatalogHeader("Plural-Forms: nplurals=2; plural=n + phpinfo();\n");
    }

    public function testDivisionByZeroIsReportedWhenEvaluated(): void
    {
        $expression = PluralFormsExpression::fromRule('nplurals=2; plural=n % (n - 1);');
        self::assertSame(0, $expression->index(2));
        $this->expectException(\DomainException::class);
        $expression->index(1);
    }
}
