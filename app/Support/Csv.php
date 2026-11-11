<?php

namespace App\Support;

final class Csv
{
    /**
     * Formula prefixes per OWASP CSV Injection guidance (SECURITY_RULES.md).
     */
    private const FORMULA_PREFIXES = ['=', '+', '-', '@', "\t", "\r"];

    /**
     * Neutralize spreadsheet formula triggers on any exported cell.
     */
    public static function sanitizeCell(mixed $value): string
    {
        if ($value === null) {
            return '';
        }
        if (is_bool($value)) {
            return $value ? '1' : '0';
        }
        if (is_array($value) || is_object($value)) {
            $encoded = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            $str = is_string($encoded) ? $encoded : '';
        } else {
            $str = (string) $value;
        }

        if ($str === '') {
            return '';
        }

        $first = $str[0];
        $trimmedLeft = ltrim($str, " \t\r\n\x0B\0");
        $trimmedFirst = $trimmedLeft !== '' ? $trimmedLeft[0] : '';

        if (
            in_array($first, self::FORMULA_PREFIXES, true)
            || in_array($trimmedFirst, ['=', '+', '-', '@'], true)
        ) {
            return "'".$str;
        }

        return $str;
    }

    /**
     * Format a single RFC-4180 CSV line with neutralized cells.
     *
     * @param  list<mixed>  $cells
     */
    public static function row(array $cells): string
    {
        $escaped = [];
        foreach ($cells as $cell) {
            $safe = self::sanitizeCell($cell);
            $safe = str_replace('"', '""', $safe);
            $escaped[] = '"'.$safe.'"';
        }

        return implode(',', $escaped)."\r\n";
    }

    /**
     * Build a complete CSV document string with optional UTF-8 header row.
     *
     * @param  list<string>  $headers
     * @param  iterable<list<mixed>>  $rows
     */
    public static function build(array $headers, iterable $rows): string
    {
        $out = self::row($headers);
        foreach ($rows as $cells) {
            $out .= self::row($cells);
        }

        return $out;
    }
}
