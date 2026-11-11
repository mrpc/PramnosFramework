<?php

declare(strict_types=1);

namespace Pramnos\General;

/**
 * How a number, and an amount of money, is written in the language the page is written in.
 *
 * ```php
 * echo localNumber(1234.5, 2);          // 1.234,50   (el)    1,234.50   (en)
 * echo localMoney(1234.5, 'EUR');       // 1.234,50 € (el)    €1,234.50  (en)
 * echo localMoney(80, 'GBP');           // 80,00 £    (el)    £80.00     (en)
 * ```
 *
 * The convention comes from `app.php` — `'numbers' => ['en' => ['decimal' => '.', 'thousands' =>
 * ',', 'symbol' => 'before']]`, per language — or from {@see CONVENTIONS}. A language listed in
 * neither is written as the framework has always written numbers: a comma for the decimals, a
 * dot for the thousands, the symbol after. Not `NumberFormatter`, for the same reason
 * {@see DateFormat} is not `IntlDateFormatter`: `intl` is not everywhere, and a figure that reads
 * differently by which extensions a host compiled in is worse than a plain one.
 */
class NumberFormat
{
    /**
     * The framework's conventions, by language: the decimal mark, the thousands mark, and whether
     * a currency's symbol goes before the number or after it, with a space.
     *
     * @var array<string, array{decimal: string, thousands: string, symbol: string}>
     */
    public const CONVENTIONS = [
        'default' => ['decimal' => ',', 'thousands' => '.', 'symbol' => 'after'],
        'en'      => ['decimal' => '.', 'thousands' => ',', 'symbol' => 'before'],
        'english' => ['decimal' => '.', 'thousands' => ',', 'symbol' => 'before'],
        'en_gb'   => ['decimal' => '.', 'thousands' => ',', 'symbol' => 'before'],
        'en_us'   => ['decimal' => '.', 'thousands' => ',', 'symbol' => 'before'],
    ];

    /** The currencies with a symbol of their own; any other is written with its code. */
    public const SYMBOLS = ['EUR' => '€', 'GBP' => '£', 'USD' => '$', 'JPY' => '¥', 'CHF' => 'CHF', 'TRY' => '₺', 'PLN' => 'zł', 'RON' => 'lei', 'BGN' => 'лв'];

    /** A number with this many decimals, in the language's convention. */
    public static function number(float $value, int $decimals = 0, ?string $language = null): string
    {
        $c = static::convention($language);

        return number_format($value, max(0, $decimals), $c['decimal'], $c['thousands']);
    }

    /** A number with at most this many decimals, the trailing noughts left out: `1.234,5`, `12`. */
    public static function trimmed(float $value, int $decimals = 2, ?string $language = null): string
    {
        $mark   = static::convention($language)['decimal'];
        $number = static::number($value, $decimals, $language);

        // Only noughts after the decimal mark go: 120 stays 120.
        return $decimals > 0 ? rtrim(rtrim($number, '0'), $mark) : $number;
    }

    /** An amount in a currency (`EUR`), with its symbol where the language puts it. */
    public static function money(float $value, string $currency, int $decimals = 2, ?string $language = null): string
    {
        $c      = static::convention($language);
        $code   = strtoupper(trim($currency));
        $symbol = static::SYMBOLS[$code] ?? $code;
        $number = number_format(abs($value), max(0, $decimals), $c['decimal'], $c['thousands']);
        $sign   = $value < 0 ? '-' : '';

        return $c['symbol'] === 'before' && mb_strlen($symbol) === 1 ? $sign . $symbol . $number : $sign . $number . ' ' . $symbol;
    }

    /**
     * An amount that may be a fraction of a cent — what a model call costs — with two decimals,
     * or as many more as it takes not to read as nought, up to six: `1,05 $`, `0,0034 $` (el),
     * `$1.05`, `$0.0034` (en).
     */
    public static function smallMoney(float $value, string $currency, ?string $language = null): string
    {
        $size     = abs($value);
        $decimals = $size > 0 && $size < 0.01 ? (int) min(6, ceil(-log10($size)) + 1) : 2;

        return static::money($value, $currency, $decimals, $language);
    }

    /**
     * The convention of a language: the application's, else the framework's, else the default.
     *
     * @return array{decimal: string, thousands: string, symbol: string}
     */
    public static function convention(?string $language = null): array
    {
        $language = strtolower(trim($language ?? static::currentLanguage()));
        $own      = \Pramnos\Application\Application::currentInstance()->applicationInfo['numbers'][$language] ?? null;
        $base     = static::CONVENTIONS[$language] ?? static::CONVENTIONS['default'];

        return is_array($own) ? array_map('strval', array_intersect_key($own, $base)) + $base : $base;
    }

    /** The language the page is written in; `default` where it cannot be told. */
    protected static function currentLanguage(): string
    {
        try {
            return (string) \Pramnos\Framework\Factory::getLanguage()->currentlang();
        } catch (\Throwable) {
            return 'default';
        }
    }
}
