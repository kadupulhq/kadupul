<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Platform\Infrastructure\Symfony;

/**
 * A gettext Plural-Forms rule ("nplurals=3; plural=n%10==1 ? 0 : ...;"),
 * parsed once and evaluated without eval().
 *
 * The grammar is GNU gettext's: the C operators ?: || && == != < > <= >=
 * + - * / % and !, parentheses, decimal integers and n. Comparisons and
 * logical operators yield 0 or 1, and ?: is right-associative. Anything else
 * is refused when the rule is parsed, not when it is evaluated.
 */
final class PluralFormsExpression
{
    /** php-gettext's rule for a catalog whose header has no Plural-Forms. */
    public const string DEFAULT_RULE = 'nplurals=2; plural=n == 1 ? 0 : 1;';

    private const int MAX_RULE_LENGTH = 512;

    private const int MAX_PLURALS = 32;

    private const array BINARY = [['||'], ['&&'], ['==', '!='], ['<', '>', '<=', '>='], ['+', '-'], ['*', '/', '%']];

    /** @var list<string> */
    private array $tokens = [];

    private int $position = 0;

    /** @param \Closure(int): int $plural */
    private function __construct(public readonly int $count, private readonly \Closure $plural) {}

    /**
     * The rule named by a catalog header (the translation of msgid ""), found
     * the way php-gettext finds it, or php-gettext's default without one.
     */
    public static function fromCatalogHeader(string $header): self
    {
        if (preg_match('/(?:^|\n)plural-forms: ([^\n]*)\n/i', $header, $match) === 1) {
            return self::fromRule($match[1]);
        }

        return self::fromRule(self::DEFAULT_RULE);
    }

    public static function fromRule(string $rule): self
    {
        if (strlen($rule) > self::MAX_RULE_LENGTH
            || preg_match('/\A\s*nplurals\s*=\s*(\d+)\s*;\s*plural\s*=\s*([^;]+);?\s*\z/', $rule, $match) !== 1) {
            throw new \InvalidArgumentException('Invalid Plural-Forms rule.');
        }
        $count = (int) $match[1];
        if ($count < 1 || $count > self::MAX_PLURALS) {
            throw new \InvalidArgumentException('Invalid Plural-Forms count.');
        }
        $parser = new self($count, static fn(int $n): int => 0);
        $plural = $parser->parse($match[2]);

        return new self($count, $plural);
    }

    /** The form for $n: the rule's value, limited to the last form as php-gettext does. */
    public function index(int $n): int
    {
        $index = ($this->plural)($n);

        return $index >= 0 && $index < $this->count ? $index : $this->count - 1;
    }

    /** @return \Closure(int): int */
    private function parse(string $expression): \Closure
    {
        if (preg_match_all('/\s*(\d+|n|&&|\|\||==|!=|<=|>=|[<>?:()%!*\/+-])/A', $expression, $matches) === false
            || implode('', $matches[0]) !== rtrim($expression)) {
            throw new \InvalidArgumentException('Invalid Plural-Forms expression.');
        }
        $this->tokens = $matches[1];
        $this->position = 0;
        $plural = $this->conditional();
        if ($this->position !== count($this->tokens)) {
            throw new \InvalidArgumentException('Invalid Plural-Forms expression.');
        }

        return $plural;
    }

    /** @return \Closure(int): int */
    private function conditional(): \Closure
    {
        $condition = $this->binary(0);
        if ($this->peek() !== '?') {
            return $condition;
        }
        $this->position++;
        $then = $this->conditional();
        $this->expect(':');
        $else = $this->conditional();

        return static fn(int $n): int => $condition($n) !== 0 ? $then($n) : $else($n);
    }

    /** @return \Closure(int): int */
    private function binary(int $level): \Closure
    {
        $operators = self::BINARY[$level] ?? null;
        if ($operators === null) {
            return $this->unary();
        }
        $left = $this->binary($level + 1);
        while (($operator = $this->peek()) !== null && in_array($operator, $operators, true)) {
            $this->position++;
            $left = self::operation($operator, $left, $this->binary($level + 1));
        }

        return $left;
    }

    /**
     * @param \Closure(int): int $left
     * @param \Closure(int): int $right
     * @return \Closure(int): int
     */
    private static function operation(string $operator, \Closure $left, \Closure $right): \Closure
    {
        return match ($operator) {
            '||' => static fn(int $n): int => (int) ($left($n) !== 0 || $right($n) !== 0),
            '&&' => static fn(int $n): int => (int) ($left($n) !== 0 && $right($n) !== 0),
            '==' => static fn(int $n): int => (int) ($left($n) === $right($n)),
            '!=' => static fn(int $n): int => (int) ($left($n) !== $right($n)),
            '<' => static fn(int $n): int => (int) ($left($n) < $right($n)),
            '>' => static fn(int $n): int => (int) ($left($n) > $right($n)),
            '<=' => static fn(int $n): int => (int) ($left($n) <= $right($n)),
            '>=' => static fn(int $n): int => (int) ($left($n) >= $right($n)),
            '+' => static fn(int $n): int => $left($n) + $right($n),
            '-' => static fn(int $n): int => $left($n) - $right($n),
            '*' => static fn(int $n): int => $left($n) * $right($n),
            // C integer division and remainder; a zero divisor is a broken rule.
            '/' => static fn(int $n): int => intdiv($left($n), self::divisor($right($n))),
            '%' => static fn(int $n): int => $left($n) % self::divisor($right($n)),
            default => throw new \LogicException('Unknown Plural-Forms operator.'),
        };
    }

    private static function divisor(int $value): int
    {
        if ($value === 0) {
            throw new \DomainException('Plural-Forms rule divides by zero.');
        }

        return $value;
    }

    /** @return \Closure(int): int */
    private function unary(): \Closure
    {
        $token = $this->tokens[$this->position++] ?? null;
        if ($token === '!') {
            $operand = $this->unary();

            return static fn(int $n): int => (int) ($operand($n) === 0);
        }
        if ($token === '(') {
            $inner = $this->conditional();
            $this->expect(')');

            return $inner;
        }
        if ($token === 'n') {
            return static fn(int $n): int => $n;
        }
        if ($token !== null && ctype_digit($token) && strlen($token) <= 9) {
            $value = (int) $token;

            return static fn(int $n): int => $value;
        }

        throw new \InvalidArgumentException('Invalid Plural-Forms expression.');
    }

    private function peek(): ?string
    {
        return $this->tokens[$this->position] ?? null;
    }

    private function expect(string $token): void
    {
        if ($this->peek() !== $token) {
            throw new \InvalidArgumentException('Invalid Plural-Forms expression.');
        }
        $this->position++;
    }
}
