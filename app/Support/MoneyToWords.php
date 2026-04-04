<?php

namespace App\Support;

/**
 * Converts a decimal rupee amount to English words (Indian numbering).
 */
final class MoneyToWords
{
    private const ONES = [
        '', 'one', 'two', 'three', 'four', 'five', 'six', 'seven', 'eight', 'nine',
        'ten', 'eleven', 'twelve', 'thirteen', 'fourteen', 'fifteen', 'sixteen',
        'seventeen', 'eighteen', 'nineteen',
    ];

    private const TENS = ['', '', 'twenty', 'thirty', 'forty', 'fifty', 'sixty', 'seventy', 'eighty', 'ninety'];

    public static function inr(float $amount): string
    {
        $amount = round($amount, 2);
        $rupees = (int) floor($amount);
        $paise = (int) round(($amount - $rupees) * 100);

        $parts = [];
        if ($rupees > 0) {
            $parts[] = trim(self::below10000000($rupees)).' rupee'.($rupees === 1 ? '' : 's');
        }
        if ($paise > 0) {
            $parts[] = trim(self::below100($paise)).' paise';
        }

        if ($parts === []) {
            return 'zero rupees';
        }

        return implode(' and ', $parts);
    }

    private static function below100(int $n): string
    {
        if ($n < 20) {
            return self::ONES[$n];
        }
        $t = (int) floor($n / 10);
        $o = $n % 10;

        return trim(self::TENS[$t].($o > 0 ? ' '.self::ONES[$o] : ''));
    }

    private static function below1000(int $n): string
    {
        if ($n < 100) {
            return self::below100($n);
        }
        $h = (int) floor($n / 100);
        $rest = $n % 100;

        return trim(self::ONES[$h].' hundred'.($rest > 0 ? ' '.self::below100($rest) : ''));
    }

    private static function below100000(int $n): string
    {
        if ($n < 1000) {
            return self::below1000($n);
        }
        $th = (int) floor($n / 1000);
        $rest = $n % 1000;

        return trim(self::below100($th).' thousand'.($rest > 0 ? ' '.self::below1000($rest) : ''));
    }

    private static function below10000000(int $n): string
    {
        if ($n < 100000) {
            return self::below100000($n);
        }
        $l = (int) floor($n / 100000);
        $rest = $n % 100000;

        return trim(self::below100($l).' lakh'.($rest > 0 ? ' '.self::below100000($rest) : ''));
    }
}
