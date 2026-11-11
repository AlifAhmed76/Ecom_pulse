<?php

namespace App\Support;

class ColumnMatcher
{
    public static function normalize(string $header): string
    {
        return (string) preg_replace('/[^a-z0-9]/', '', strtolower(trim($header)));
    }

    /**
     * @param  list<string>  $headers
     * @param  array<string, list<string>>  $fieldAliases
     * @return array<string, ?string>
     */
    public static function match(array $headers, array $fieldAliases): array
    {
        $normalizedHeaders = [];
        foreach ($headers as $index => $h) {
            $normalizedHeaders[$index] = [
                'raw' => $h,
                'norm' => self::normalize($h),
            ];
        }

        $used = [];
        $mapping = [];
        foreach (array_keys($fieldAliases) as $field) {
            $mapping[$field] = null;
        }

        // Pass 1: Exact normalized alias matches
        foreach ($fieldAliases as $field => $aliases) {
            $normAliases = array_map(self::normalize(...), $aliases);
            foreach ($normalizedHeaders as $idx => $hInfo) {
                if (isset($used[$idx]) || $hInfo['norm'] === '') {
                    continue;
                }
                if (in_array($hInfo['norm'], $normAliases, true)) {
                    $mapping[$field] = $hInfo['raw'];
                    $used[$idx] = true;
                    break;
                }
            }
        }

        // Pass 2: Contains matches for aliases with length >= 5 (prefer longest alias to avoid prefix collisions)
        $candidates = [];
        $fieldOrder = 0;
        foreach ($fieldAliases as $field => $aliases) {
            if ($mapping[$field] !== null) {
                $fieldOrder++;
                continue;
            }
            $longAliases = array_values(array_filter(
                array_map(self::normalize(...), $aliases),
                fn (string $a): bool => strlen($a) >= 5
            ));
            if ($longAliases === []) {
                $fieldOrder++;
                continue;
            }

            foreach ($normalizedHeaders as $idx => $hInfo) {
                if (isset($used[$idx]) || $hInfo['norm'] === '') {
                    continue;
                }
                $bestLen = 0;
                foreach ($longAliases as $alias) {
                    if (str_contains($hInfo['norm'], $alias)) {
                        $bestLen = max($bestLen, strlen($alias));
                    }
                }
                if ($bestLen > 0) {
                    $candidates[] = [
                        'field' => $field,
                        'idx' => $idx,
                        'len' => $bestLen,
                        'order' => $fieldOrder,
                    ];
                }
            }
            $fieldOrder++;
        }

        usort($candidates, static function (array $a, array $b): int {
            return [$b['len'], $a['order'], $a['idx']] <=> [$a['len'], $b['order'], $b['idx']];
        });

        foreach ($candidates as $cand) {
            $field = $cand['field'];
            $idx = $cand['idx'];
            if ($mapping[$field] !== null || isset($used[$idx])) {
                continue;
            }
            $mapping[$field] = $normalizedHeaders[$idx]['raw'];
            $used[$idx] = true;
        }

        return $mapping;
    }
}
