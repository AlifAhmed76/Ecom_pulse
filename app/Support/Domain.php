<?php

namespace App\Support;

class Domain
{
    public static function normalize(string $input): ?string
    {
        $s = strtolower(trim($input));
        if ($s === '' || strlen($s) > 253) {
            return null;
        }

        $s = (string) preg_replace('#^[a-z][a-z0-9+.\-]*://#', '', $s);
        $s = (string) (preg_split('#[/?\#]#', $s, 2)[0] ?? '');

        if ($s === '' || str_contains($s, '@')) {
            return null;
        }

        $s = (string) preg_replace('/:\d+$/', '', $s);
        $s = rtrim($s, '.');
        $s = (string) preg_replace('/^www\./', '', $s);

        if ($s === 'localhost') {
            return $s;
        }

        if (strlen($s) > 253 || ! preg_match('/^([a-z0-9]([a-z0-9\-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/', $s)) {
            return null;
        }

        return $s;
    }
}
