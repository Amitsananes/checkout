<?php

declare(strict_types=1);

namespace Nezasa\Checkout\Integrations\Credit2000\Enums;

enum Credit2000CurrencyEnum: string
{
    case Ils = '1';
    case Usd = '2';
    case Eur = '3';

    public static function tryFromCurrencyCode(string $currency): ?self
    {
        return match (strtoupper($currency)) {
            'ILS' => self::Ils,
            'USD' => self::Usd,
            'EUR' => self::Eur,
            default => null,
        };
    }
}
