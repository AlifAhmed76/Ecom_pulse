<?php

namespace App\Support;

use Carbon\Carbon;
use Throwable;

class Parse
{
    private const BN_DIGITS = ['০', '১', '২', '৩', '৪', '৫', '৬', '৭', '৮', '৯'];

    private const EN_DIGITS = ['0', '1', '2', '3', '4', '5', '6', '7', '8', '9'];

    public static function toAsciiDigits(string $input): string
    {
        return str_replace(self::BN_DIGITS, self::EN_DIGITS, $input);
    }

    public static function num(mixed $value): ?float
    {
        if ($value === null || is_bool($value)) {
            return null;
        }
        if (is_int($value) || is_float($value)) {
            return is_finite((float) $value) ? round((float) $value, 2) : null;
        }
        if (! is_string($value)) {
            return null;
        }

        $clean = self::toAsciiDigits(trim($value));
        if ($clean === '') {
            return null;
        }

        $clean = str_replace([',', ' ', "\u{00A0}", '৳', '$', '€', '£', 'BDT', 'bdt', 'USD', 'usd', 'TK', 'tk', 'Tk'], '', $clean);
        if (! is_numeric($clean)) {
            return null;
        }

        $num = (float) $clean;

        return is_finite($num) ? round($num, 2) : null;
    }

    public static function intNum(mixed $value): ?int
    {
        $n = self::num($value);

        return $n === null ? null : (int) round($n);
    }

    public static function date(mixed $value, ?string $tz = null): ?string
    {
        $dt = self::datetime($value, $tz);

        return $dt?->format('Y-m-d');
    }

    public static function datetime(mixed $value, ?string $tz = null): ?Carbon
    {
        if ($value instanceof Carbon) {
            return $value->copy()->timezone($tz ?? config('app.timezone', 'Asia/Dhaka'));
        }
        if ($value === null || ! is_scalar($value)) {
            return null;
        }

        $raw = self::toAsciiDigits(trim((string) $value));
        if ($raw === '') {
            return null;
        }

        $timezone = $tz ?? config('app.timezone', 'Asia/Dhaka');

        foreach (['d/m/Y H:i:s', 'd/m/Y H:i', 'd/m/Y', 'd-m-Y H:i:s', 'd-m-Y'] as $fmt) {
            try {
                if (preg_match('/^\d{1,2}[\/-]\d{1,2}[\/-]\d{4}/', $raw)) {
                    $parsed = Carbon::createFromFormat($fmt, $raw, $timezone);
                    if ($parsed !== false) {
                        return $parsed;
                    }
                }
            } catch (Throwable) {
                // fall through
            }
        }

        try {
            return Carbon::parse($raw, $timezone)->timezone($timezone);
        } catch (Throwable) {
            return null;
        }
    }
}
