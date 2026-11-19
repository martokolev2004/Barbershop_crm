<?php

declare(strict_types=1);

namespace BarberLead\Support;

/** Normalisation helpers used for search, slugs and duplicate detection. */
final class Text
{
    private const CYR = [
        'а' => 'a', 'б' => 'b', 'в' => 'v', 'г' => 'g', 'д' => 'd', 'е' => 'e', 'ж' => 'zh', 'з' => 'z',
        'и' => 'i', 'й' => 'y', 'к' => 'k', 'л' => 'l', 'м' => 'm', 'н' => 'n', 'о' => 'o', 'п' => 'p',
        'р' => 'r', 'с' => 's', 'т' => 't', 'у' => 'u', 'ф' => 'f', 'х' => 'h', 'ц' => 'ts', 'ч' => 'ch',
        'ш' => 'sh', 'щ' => 'sht', 'ъ' => 'a', 'ь' => 'y', 'ю' => 'yu', 'я' => 'ya',
    ];

    /** Bulgarian streamlined transliteration (lowercase). */
    public static function translit(string $s): string
    {
        return strtr(mb_strtolower($s, 'UTF-8'), self::CYR);
    }

    public static function slug(string $s): string
    {
        $s = self::translit($s);
        $s = (string) preg_replace('/[^a-z0-9]+/', '-', $s);
        return trim($s, '-');
    }

    /**
     * Name key for duplicate detection: transliterated, lowercase, punctuation removed and
     * generic words ("barbershop", "бръснарница", "салон"...) dropped.
     */
    public static function normalizeName(string $name): string
    {
        $s = self::translit($name);
        $s = (string) preg_replace('/[^a-z0-9]+/', ' ', $s);
        $generic = ['barbershop', 'barber', 'shop', 'barbers', 'bradnarnitsa', 'brasnarnitsa', 'salon', 'frizyorski', 'studio', 'the', 'and'];
        $words = array_filter(explode(' ', $s), fn ($w) => $w !== '' && !in_array($w, $generic, true));
        $key = implode(' ', $words);
        return $key !== '' ? $key : trim((string) preg_replace('/\s+/', ' ', $s));
    }

    /** Bulgarian phone to E.164 (+359...). Returns null if it does not look like a phone number. */
    public static function normalizePhone(?string $phone): ?string
    {
        if ($phone === null) {
            return null;
        }
        $digits = (string) preg_replace('/\D+/', '', $phone);
        if ($digits === '') {
            return null;
        }
        if (str_starts_with($digits, '00359')) {
            $digits = substr($digits, 5);
        } elseif (str_starts_with($digits, '359')) {
            $digits = substr($digits, 3);
        } elseif (str_starts_with($digits, '0')) {
            $digits = substr($digits, 1);
        }
        if (strlen($digits) < 7 || strlen($digits) > 10) {
            return null;
        }
        return '+359' . $digits;
    }

    /** Registrable host of a URL without "www.", lowercase. */
    public static function domain(?string $url): ?string
    {
        if ($url === null || trim($url) === '') {
            return null;
        }
        $url = trim($url);
        if (!preg_match('~^https?://~i', $url)) {
            $url = 'https://' . $url;
        }
        $host = parse_url($url, PHP_URL_HOST);
        if (!is_string($host) || $host === '') {
            return null;
        }
        $host = strtolower($host);
        return str_starts_with($host, 'www.') ? substr($host, 4) : $host;
    }

    /** Adds https:// when the scheme is missing; returns null for empty / non-http(s) input. */
    public static function normalizeUrl(?string $url): ?string
    {
        if ($url === null || trim($url) === '') {
            return null;
        }
        $url = trim($url);
        if (!preg_match('~^[a-z][a-z0-9+.-]*://~i', $url)) {
            $url = 'https://' . $url;
        }
        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
        if (!in_array($scheme, ['http', 'https'], true) || filter_var($url, FILTER_VALIDATE_URL) === false) {
            return null;
        }
        return $url;
    }
}
