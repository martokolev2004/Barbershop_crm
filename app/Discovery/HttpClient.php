<?php

declare(strict_types=1);

namespace BarberLead\Discovery;

/**
 * Minimal HTTP client for data sources. Only hosts on the allow-list can be contacted (SSRF guard):
 * scraped or user-entered URLs never reach this class.
 */
class HttpClient
{
    public const ALLOWED_HOSTS = ['overpass-api.de', 'overpass.kumi.systems', 'overpass.private.coffee', 'places.googleapis.com'];
    public const USER_AGENT = 'BarberLead/0.2 (small-business CRM; +https://crm.testvane.org)';

    /**
     * @param array<string,string> $headers
     * @return array{status:int, body:string, error:?string}
     */
    public function post(string $url, string $body, array $headers = [], int $timeout = 60): array
    {
        $host = parse_url($url, PHP_URL_HOST);
        if (parse_url($url, PHP_URL_SCHEME) !== 'https' || !in_array($host, self::ALLOWED_HOSTS, true)) {
            return ['status' => 0, 'body' => '', 'error' => "Host not allowed: $host"];
        }
        $lines = ['User-Agent: ' . self::USER_AGENT];
        foreach ($headers as $k => $v) {
            $lines[] = "$k: $v";
        }
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_HTTPHEADER => $lines,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
        ]);
        $resp = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $error = $resp === false ? curl_error($ch) : null;
        curl_close($ch);
        return ['status' => $status, 'body' => is_string($resp) ? $resp : '', 'error' => $error ?: null];
    }

    /** 429, 5xx and network errors are worth retrying later. */
    public static function isTransient(int $status, ?string $error): bool
    {
        return $error !== null || $status === 0 || $status === 429 || $status >= 500;
    }
}
