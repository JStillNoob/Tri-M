<?php

namespace App\Services;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

class Money
{
    public const int MAX_CENTAVOS = 999999999999;

    public const array RULES = ['numeric', 'decimal:0,2', 'min:0', 'max:9999999999.99'];

    public const array MARKUP_RULES = ['numeric', 'decimal:0,4', 'min:0', 'max:99999.9999'];

    public static function lineTotal(string $price, int $quantity): BigDecimal
    {
        return BigDecimal::of($price)->multipliedBy($quantity)->toScale(2, RoundingMode::HalfUp);
    }

    public static function suggestedPrice(?string $cost, ?string $markup): ?string
    {
        if ($cost === null || $markup === null) {
            return null;
        }

        $price = BigDecimal::of($cost)->multipliedBy(BigDecimal::of($markup)->plus(100))
            ->dividedBy(100, 0, RoundingMode::Ceiling);

        return $price->isPositive() && $price->isLessThanOrEqualTo('9999999999.99') ? (string) $price->toScale(2) : null;
    }

    public static function centavos(string|int|float $amount): int
    {
        [$pesos, $fraction] = array_pad(explode('.', (string) $amount, 2), 2, '');

        return (int) $pesos * 100 + (int) str_pad($fraction, 2, '0');
    }

    public static function decimal(int $centavos): string
    {
        return sprintf('%d.%02d', intdiv($centavos, 100), $centavos % 100);
    }
}
