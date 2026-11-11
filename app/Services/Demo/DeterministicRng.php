<?php

namespace App\Services\Demo;

/**
 * Pure-PHP deterministic 32-bit LCG PRNG so demo seeding never mutates global mt_rand state.
 */
final class DeterministicRng
{
    private int $state;

    public function __construct(int $seed = 20261110)
    {
        $this->state = $seed & 0x7FFFFFFF;
        if ($this->state === 0) {
            $this->state = 123456789;
        }
    }

    public function nextFloat(): float
    {
        $this->state = ($this->state * 1103515245 + 12345) & 0x7FFFFFFF;

        return $this->state / 2147483648.0;
    }

    public function intRange(int $min, int $max): int
    {
        if ($max <= $min) {
            return $min;
        }

        return $min + (int) floor($this->nextFloat() * (($max - $min) + 1));
    }

    public function chance(float $probability): bool
    {
        return $this->nextFloat() < $probability;
    }

    /**
     * @template T
     * @param  non-empty-list<T>  $items
     * @return T
     */
    public function pick(array $items): mixed
    {
        $idx = $this->intRange(0, count($items) - 1);

        return $items[$idx];
    }

    /**
     * @param  non-empty-array<string, int|float>  $weights
     */
    public function weightedKey(array $weights): string
    {
        $total = 0.0;
        foreach ($weights as $w) {
            $total += max(0.0, (float) $w);
        }

        if ($total <= 0.0) {
            return (string) array_key_first($weights);
        }

        $roll = $this->nextFloat() * $total;
        $acc = 0.0;
        foreach ($weights as $key => $w) {
            $acc += max(0.0, (float) $w);
            if ($roll <= $acc) {
                return (string) $key;
            }
        }

        return (string) array_key_last($weights);
    }
}
