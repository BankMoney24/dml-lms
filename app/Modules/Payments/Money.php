<?php

declare(strict_types=1);

namespace App\Modules\Payments;

/** Amounts are stored in minor units (kobo, pesewas, cents); this formats and parses them. */
final class Money
{
    /** Currencies Paystack settles; code => [symbol, name]. */
    public const CURRENCIES = [
        'NGN' => ['₦', 'Nigerian naira'],
        'GHS' => ['GH₵', 'Ghanaian cedi'],
        'KES' => ['KSh', 'Kenyan shilling'],
        'ZAR' => ['R', 'South African rand'],
        'USD' => ['$', 'US dollar'],
    ];

    /** Paystack's minimum charge is about ₦100 / $1; anything lower is almost certainly a typo. */
    public const MIN_MINOR = 10000;
    public const MAX_MINOR = 100000000;

    public static function format(int $minor, string $currency): string
    {
        $symbol = self::CURRENCIES[$currency][0] ?? $currency . ' ';
        $major = $minor / 100;

        return $symbol . number_format($major, $minor % 100 === 0 ? 0 : 2);
    }

    /**
     * "15,000", "15000.50", "₦ 15 000" → minor units; null when it is not an amount.
     */
    public static function parse(string $text): ?int
    {
        $clean = (string) preg_replace('/[^0-9.]/', '', str_replace(',', '', $text));
        if ($clean === '' || preg_match('/^\d+(\.\d{1,2})?$/', $clean) !== 1) {
            return null;
        }

        return (int) round((float) $clean * 100);
    }

    public static function isSupported(string $currency): bool
    {
        return isset(self::CURRENCIES[$currency]);
    }
}
