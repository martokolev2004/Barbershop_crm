<?php

declare(strict_types=1);

namespace BarberLead\Discovery;

use BarberLead\Support\Text;

/** OpenStreetMap via the public Overpass API. Free, no key; data is ODbL (attribution: © OpenStreetMap contributors). */
class OverpassSource
{
    public const ENDPOINT = 'https://overpass-api.de/api/interpreter';
    /** Public Overpass instances, tried in turn when one is overloaded (HTTP 429/502/504 are common). */
    public const ENDPOINTS = [
        'https://overpass-api.de/api/interpreter',
        'https://overpass.kumi.systems/api/interpreter',
        'https://overpass.private.coffee/api/interpreter',
    ];

    /** Endpoint for the n-th attempt (0-based), rotating through the mirrors. */
    public static function endpoint(int $attempt): string
    {
        return self::ENDPOINTS[$attempt % count(self::ENDPOINTS)];
    }

    public function __construct(private HttpClient $http)
    {
    }

    /** @param array{south:float,west:float,north:float,east:float} $bbox */
    public static function query(array $bbox): string
    {
        $b = sprintf('%.6f,%.6f,%.6f,%.6f', $bbox['south'], $bbox['west'], $bbox['north'], $bbox['east']);
        return "[out:json][timeout:50];\n("
            . "nwr[\"shop\"~\"^(hairdresser|barber)$\"]($b);"
            . "nwr[\"craft\"=\"hairdresser\"]($b);"
            . "nwr[\"name\"~\"barber|бръснар|barbershop\",i]($b);"
            . ");\nout center tags;";
    }

    /** @param array{south:float,west:float,north:float,east:float} $bbox */
    public function search(array $bbox, int $attempt = 0): SourceResult
    {
        $endpoint = self::endpoint($attempt);
        $resp = $this->http->post($endpoint, http_build_query(['data' => self::query($bbox)]),
            ['Content-Type' => 'application/x-www-form-urlencoded'], 70);
        if ($resp['error'] !== null || $resp['status'] !== 200) {
            return new SourceResult([], 'OpenStreetMap (' . parse_url($endpoint, PHP_URL_HOST) . '): ' . ($resp['error'] ?? 'HTTP ' . $resp['status']),
                HttpClient::isTransient($resp['status'], $resp['error']), 1);
        }
        $data = json_decode($resp['body'], true);
        if (!is_array($data) || !isset($data['elements']) || !is_array($data['elements'])) {
            return new SourceResult([], 'OpenStreetMap: неразбираем отговор', true, 1);
        }
        if (isset($data['remark']) && is_string($data['remark']) && str_contains($data['remark'], 'runtime error')) {
            return new SourceResult([], 'OpenStreetMap: ' . mb_substr($data['remark'], 0, 200), true, 1);
        }
        return new SourceResult(self::parse($data['elements']), null, false, 1);
    }

    /**
     * @param list<array<string,mixed>> $elements
     * @return list<Candidate>
     */
    public static function parse(array $elements): array
    {
        $out = [];
        foreach ($elements as $el) {
            $t = is_array($el['tags'] ?? null) ? $el['tags'] : [];
            $name = trim((string) ($t['name'] ?? $t['name:bg'] ?? $t['brand'] ?? ''));
            if ($name === '') {
                continue; // unnamed shop nodes cannot be contacted or recognised
            }
            $lat = $el['lat'] ?? $el['center']['lat'] ?? null;
            $lng = $el['lon'] ?? $el['center']['lon'] ?? null;
            $street = trim(($t['addr:street'] ?? '') . ' ' . ($t['addr:housenumber'] ?? ''));
            $types = [];
            foreach (['shop', 'craft', 'hairdresser', 'male', 'female', 'unisex', 'amenity', 'beauty'] as $k) {
                if (isset($t[$k])) {
                    $types[] = "$k=" . $t[$k];
                }
            }
            $out[] = new Candidate(
                source: 'osm',
                sourceId: $el['type'] . '/' . $el['id'],
                name: $name,
                lat: $lat !== null ? (float) $lat : null,
                lng: $lng !== null ? (float) $lng : null,
                address: $street !== '' ? $street : null,
                phone: self::first($t, ['phone', 'contact:phone', 'contact:mobile', 'mobile']),
                website: self::first($t, ['website', 'contact:website', 'url']),
                email: self::first($t, ['email', 'contact:email']),
                instagram: self::social(self::first($t, ['contact:instagram', 'instagram']), 'instagram.com'),
                facebook: self::social(self::first($t, ['contact:facebook', 'facebook']), 'facebook.com'),
                openingHours: self::first($t, ['opening_hours']),
                url: 'https://www.openstreetmap.org/' . $el['type'] . '/' . $el['id'],
                types: $types,
                description: self::first($t, ['description']),
                closed: isset($t['disused:shop']) || ($t['opening_hours'] ?? '') === 'off',
            );
        }
        return $out;
    }

    /** @param array<string,mixed> $tags @param list<string> $keys */
    private static function first(array $tags, array $keys): ?string
    {
        foreach ($keys as $k) {
            if (isset($tags[$k]) && is_string($tags[$k]) && trim($tags[$k]) !== '') {
                return trim(explode(';', $tags[$k])[0]);
            }
        }
        return null;
    }

    /** OSM stores social profiles as full URLs or bare handles. */
    private static function social(?string $v, string $host): ?string
    {
        if ($v === null) {
            return null;
        }
        if (Text::normalizeUrl($v) !== null && str_contains($v, '.')) {
            return Text::normalizeUrl($v);
        }
        $handle = ltrim($v, '@ ');
        return preg_match('/^[A-Za-z0-9._]{2,60}$/', $handle) ? "https://www.$host/$handle" : null;
    }
}
