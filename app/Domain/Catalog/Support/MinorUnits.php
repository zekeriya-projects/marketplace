<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Support;

use InvalidArgumentException;

final class MinorUnits
{
    public static function fromDecimal(string $amount): int
    {
        $normalized = str_replace(',', '.', trim($amount));

        if (preg_match('/^(\d+)(?:\.(\d{1,2}))?$/', $normalized, $matches) !== 1) {
            throw new InvalidArgumentException('The amount must have at most two decimal places.');
        }

        $whole = (int) $matches[1];
        $fraction = str_pad($matches[2] ?? '', 2, '0');

        return ($whole * 100) + (int) $fraction;
    }

    public static function toDecimal(int $amount): string
    {
        return sprintf('%d.%02d', intdiv($amount, 100), $amount % 100);
    }
}
