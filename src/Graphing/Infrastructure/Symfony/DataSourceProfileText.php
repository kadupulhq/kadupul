<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Graphing\Infrastructure\Symfony;

use Kadupul\Graphing\Domain\DataSourceProfileSizing;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Attribute\AsTwigFunction;

/** Interval, retention and size wording of the legacy profile pages, in the request locale. */
final readonly class DataSourceProfileText
{
    private const string DOMAIN = 'data_source_profile';

    public function __construct(private TranslatorInterface $translator, private RequestStack $requests) {}

    /** get_span(): comma-separated calendar parts, empty below one hour. */
    #[AsTwigFunction('profile_span')]
    public function span(int $seconds): string
    {
        return implode(', ', array_map(fn(array $part): string => $this->unit($part['unit'], $part['count']), DataSourceProfileSizing::span($seconds)));
    }

    /** Data Retention of an archive; a product too large for an integer reads N/A. */
    #[AsTwigFunction('profile_retention')]
    public function retention(int $step, int $steps, int $rows): string
    {
        $seconds = DataSourceProfileSizing::retention($step, $steps, $rows);
        return $seconds === null ? $this->translator->trans('N/A', [], self::DOMAIN) : $this->span($seconds);
    }

    #[AsTwigFunction('profile_size')]
    public function profileSize(int $rows, int $functions): string
    {
        return $this->translator->trans('%size% KBytes per Data Sources and %header% Bytes for the Header', [
            '%size%' => $this->number(DataSourceProfileSizing::profileKilobytes($rows, $functions)),
            '%header%' => DataSourceProfileSizing::FILE_HEADER_BYTES,
        ], self::DOMAIN);
    }

    #[AsTwigFunction('profile_archive_size')]
    public function archiveSize(int $rows, int $functions): string
    {
        if ($rows <= 0) {
            return $this->translator->trans('Enter a valid number of Rows to obtain the RRA size.', [], self::DOMAIN);
        }
        return $this->translator->trans('%size% KBytes per Data Source', ['%size%' => $this->number(DataSourceProfileSizing::archiveKilobytes($rows, $functions))], self::DOMAIN);
    }

    /** Polling Interval labels, such as "Every 5 Minutes". */
    #[AsTwigFunction('profile_interval')]
    public function interval(int $seconds): string
    {
        return match (true) {
            $seconds === 60 => $this->translator->trans('Every Minute', [], self::DOMAIN),
            $seconds === 3600 => $this->translator->trans('Every Hour', [], self::DOMAIN),
            $seconds < 60 || $seconds % 60 !== 0 => $this->translator->trans('Every %count% Seconds', ['%count%' => $seconds], self::DOMAIN),
            $seconds < 3600 || $seconds % 3600 !== 0 => $this->translator->trans('Every %count% Minutes', ['%count%' => intdiv($seconds, 60)], self::DOMAIN),
            $seconds < 86400 || $seconds % 86400 !== 0 => $this->translator->trans('Every %count% Hours', ['%count%' => intdiv($seconds, 3600)], self::DOMAIN),
            $seconds === 86400 => $this->translator->trans('Every %count% Day', ['%count%' => 1], self::DOMAIN),
            default => $this->translator->trans('Every %count% Days', ['%count%' => intdiv($seconds, 86400)], self::DOMAIN),
        };
    }

    /** Heartbeat labels: seconds below two minutes, then minutes, hours and days, as the legacy list. */
    #[AsTwigFunction('profile_heartbeat')]
    public function heartbeat(int $seconds): string
    {
        return match (true) {
            $seconds < 120 => $this->unit('second', $seconds),
            $seconds < 3600 => $this->unit('minute', $seconds / 60),
            $seconds < 86400 => $this->unit('hour', $seconds / 3600),
            default => $this->unit('day', $seconds / 86400),
        };
    }

    /** Aggregation Level labels; 1 means one row per sample. */
    #[AsTwigFunction('profile_level')]
    public function level(int $seconds): string
    {
        return match (true) {
            $seconds === 1 => $this->translator->trans('Selected Poller Interval', [], self::DOMAIN),
            $seconds < 60 => $this->unit('second', $seconds),
            $seconds < 3600 => $this->unit('minute', $seconds / 60),
            $seconds < 86400 => $this->unit('hour', $seconds / 3600),
            $seconds < 604800 => $this->unit('day', $seconds / 86400),
            default => $this->unit('week', $seconds / 604800),
        };
    }

    /** Default Timespan labels; a value off the legacy list falls back to its span, as the RRA table did. */
    #[AsTwigFunction('profile_timespan')]
    public function timespan(int $seconds): string
    {
        return match (true) {
            $seconds >= 3600 && $seconds < 86400 && $seconds % 3600 === 0 => $this->unit('hour', $seconds / 3600),
            $seconds >= 86400 && $seconds < 604800 && $seconds % 86400 === 0 => $this->unit('day', $seconds / 86400),
            $seconds >= 604800 && $seconds <= 1814400 && $seconds % 604800 === 0 => $this->unit('week', $seconds / 604800),
            // The legacy list counts a month as 30.31 days.
            $seconds >= 2618784 && $seconds < 31536000 && $seconds % 2618784 === 0 => $this->unit('month', $seconds / 2618784),
            $seconds >= 31536000 && $seconds % 31536000 === 0 => $this->unit('year', $seconds / 31536000),
            default => $this->span($seconds),
        };
    }

    /**
     * Label => seconds for a choice field. A stored value off the legacy list
     * stays selectable, so an unchanged form still saves.
     *
     * @param list<int> $values
     * @param \Closure(int): string $label
     * @return array<string, int>
     */
    public function choices(array $values, ?int $current, \Closure $label): array
    {
        if ($current !== null && !in_array($current, $values, true)) {
            $values[] = $current;
            sort($values, SORT_NUMERIC);
        }
        $choices = [];
        foreach ($values as $value) {
            // Two values can share a label, such as 30 seconds and the 30 second interval; keep both.
            $text = $label($value);
            $choices[isset($choices[$text]) ? $text . ' (' . $value . ')' : $text] = $value;
        }
        return $choices;
    }

    private function unit(string $unit, int|float $count): string
    {
        $text = is_float($count) && floor($count) !== $count ? (string) $count : (string) (int) $count;
        $key = '%count% ' . ucfirst($unit) . ((float) $count === 1.0 ? '' : 's');
        return $this->translator->trans($key, ['%count%' => $text], self::DOMAIN);
    }

    /** number_format_i18n() with no decimals: grouped digits, half to even. */
    private function number(float $value): string
    {
        $formatter = new \NumberFormatter($this->requests->getCurrentRequest()?->getLocale() ?? 'en', \NumberFormatter::DECIMAL);
        $formatter->setAttribute(\NumberFormatter::MAX_FRACTION_DIGITS, 0);
        $formatted = $formatter->format($value);
        if ($formatted === false) {
            throw new \RuntimeException('Profile size could not be formatted.');
        }
        return $formatted;
    }
}
