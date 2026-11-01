<?php

declare(strict_types=1);

namespace Larena\Layout\StructuredContent;

use NumberFormatter;
use Throwable;

/**
 * How a stored file reads to people, as the SIMAI Framework's file-size.mjs says: its size in the page's language and
 * its name when nobody gave it one. The number is formatted for the locale as CLDR says, through ICU's
 * NumberFormatter (PHP intl), the way Intl.NumberFormat formats it in a browser.
 */
final class FileSize
{
    private const UNITS = [
        'en' => ['B', 'KB', 'MB', 'GB'],
        'ru' => ['Б', 'КБ', 'МБ', 'ГБ'],
    ];

    /** @return list<string> the four unit words for a locale: its language's, else English */
    public static function units(string $locale = 'en'): array
    {
        $language = explode('-', strtolower($locale === '' ? 'en' : $locale))[0];

        return self::UNITS[$language] ?? self::UNITS['en'];
    }

    /** A size as people read it: «2 КБ», «1,4 МБ»; '' for anything that is not a size. */
    public static function format(int $size, string $locale = 'en'): string
    {
        if ($size < 0) {
            return '';
        }
        $units = self::units($locale);
        $amount = (float) $size;
        $index = 0;
        while ($amount >= 1024 && $index < count($units) - 1) {
            $amount /= 1024;
            $index++;
        }
        $digits = $index === 0 || $amount >= 10 ? 0 : 1;

        return self::number($amount, $digits, $locale).' '.$units[$index];
    }

    /** The last part of an address, decoded, without its query and fragment. */
    public static function nameFromAddress(string $href): string
    {
        $path = (string) preg_split('/[?#]/', $href)[0];
        $parts = explode('/', $path);
        $tail = (string) end($parts);
        // decodeURIComponent throws on a broken escape or bytes that are not UTF-8; the tail then stays as it is.
        if (preg_match('/%(?![0-9A-Fa-f]{2})/', $tail) === 1) {
            return $tail;
        }
        $decoded = rawurldecode($tail);

        return mb_check_encoding($decoded, 'UTF-8') ? $decoded : $tail;
    }

    private static function number(float $amount, int $digits, string $locale): string
    {
        if (class_exists(NumberFormatter::class)) {
            try {
                $formatter = new NumberFormatter($locale, NumberFormatter::DECIMAL);
                $formatter->setAttribute(NumberFormatter::MIN_FRACTION_DIGITS, 0);
                $formatter->setAttribute(NumberFormatter::MAX_FRACTION_DIGITS, $digits);
                // Intl.NumberFormat rounds half away from zero; ICU's default is half even.
                $formatter->setAttribute(NumberFormatter::ROUNDING_MODE, NumberFormatter::ROUND_HALFUP);
                $formatted = $formatter->format($amount);
                if (is_string($formatted)) {
                    return $formatted;
                }
            } catch (Throwable) {
                // As the reference does when Intl refuses the locale: the plain number.
            }
        }

        return number_format($amount, $digits, '.', '');
    }
}
