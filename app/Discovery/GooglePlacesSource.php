<?php

declare(strict_types=1);

namespace BarberLead\Discovery;

/**
 * Google Places API (New) Text Search, restricted to the scanned rectangle.
 * Every HTTP request is counted against a monthly cap so usage stays inside Google's free tier.
 * Terms: only the place id is kept long-term; other Google fields in business_sources are purged
 * after 30 days by the worker's housekeeping.
 */
class GooglePlacesSource
{
    public const ENDPOINT = 'https://places.googleapis.com/v1/places:searchText';
    public const QUERIES = ['барбершоп', 'бръснарница', 'barber shop', 'мъжко подстригване', 'мъжки фризьор', 'фризьорски салон'];
    public const MAX_PAGES = 3;
    private const FIELDS = 'places.id,places.displayName,places.formattedAddress,places.shortFormattedAddress,places.location,'
        . 'places.nationalPhoneNumber,places.websiteUri,places.rating,places.userRatingCount,places.types,'
        . 'places.businessStatus,places.googleMapsUri,places.regularOpeningHours.weekdayDescriptions,nextPageToken';

    public function __construct(private HttpClient $http, private string $apiKey)
    {
    }

    /**
     * One page of one query. The caller keeps the cursor (query index + page token) between worker slices.
     * @param array{south:float,west:float,north:float,east:float} $bbox
     * @return array{0: SourceResult, 1: ?string} [result, next page token]
     */
    public function searchPage(array $bbox, string $query, ?string $pageToken): array
    {
        $body = [
            'textQuery' => $query,
            'languageCode' => 'bg',
            'regionCode' => 'BG',
            'pageSize' => 20,
            'locationRestriction' => ['rectangle' => [
                'low' => ['latitude' => $bbox['south'], 'longitude' => $bbox['west']],
                'high' => ['latitude' => $bbox['north'], 'longitude' => $bbox['east']],
            ]],
        ];
        if ($pageToken !== null) {
            $body['pageToken'] = $pageToken;
        }
        $resp = $this->http->post(self::ENDPOINT, (string) json_encode($body), [
            'Content-Type' => 'application/json',
            'X-Goog-Api-Key' => $this->apiKey,
            'X-Goog-FieldMask' => self::FIELDS,
        ], 30);
        if ($resp['error'] !== null || $resp['status'] !== 200) {
            $msg = $resp['error'] ?? 'HTTP ' . $resp['status'];
            $json = json_decode($resp['body'], true);
            if (is_array($json) && isset($json['error']['message']) && is_string($json['error']['message'])) {
                $msg .= ': ' . mb_substr($json['error']['message'], 0, 200);
            }
            return [new SourceResult([], 'Google Places: ' . $msg, HttpClient::isTransient($resp['status'], $resp['error']), 1), null];
        }
        $data = json_decode($resp['body'], true);
        if (!is_array($data)) {
            return [new SourceResult([], 'Google Places: неразбираем отговор', true, 1), null];
        }
        $next = isset($data['nextPageToken']) && is_string($data['nextPageToken']) ? $data['nextPageToken'] : null;
        return [new SourceResult(self::parse(is_array($data['places'] ?? null) ? $data['places'] : []), null, false, 1), $next];
    }

    /**
     * Rectangle Google uses for a place name such as "Младост, София" (fallback when OSM has no boundary).
     * @return array{0: array{south:float,west:float,north:float,east:float}|null, 1: ?string, 2: bool} [bbox, error, transient]
     */
    public function areaViewport(string $query): array
    {
        $resp = $this->http->post(self::ENDPOINT, (string) json_encode(['textQuery' => $query, 'languageCode' => 'bg', 'regionCode' => 'BG', 'pageSize' => 1]), [
            'Content-Type' => 'application/json',
            'X-Goog-Api-Key' => $this->apiKey,
            'X-Goog-FieldMask' => 'places.viewport,places.displayName',
        ], 30);
        if ($resp['error'] !== null || $resp['status'] !== 200) {
            return [null, 'Google Places: ' . ($resp['error'] ?? 'HTTP ' . $resp['status']), HttpClient::isTransient($resp['status'], $resp['error'])];
        }
        $v = json_decode($resp['body'], true)['places'][0]['viewport'] ?? null;
        if (!is_array($v) || !isset($v['low']['latitude'], $v['high']['latitude'])) {
            return [null, 'Google не намери „' . $query . '“', false];
        }
        $b = ['south' => (float) $v['low']['latitude'], 'west' => (float) $v['low']['longitude'],
            'north' => (float) $v['high']['latitude'], 'east' => (float) $v['high']['longitude']];
        // a neighborhood/district is at most a few km across; anything bigger is a wrong match (e.g. the whole city)
        if ($b['north'] - $b['south'] > 0.12 || $b['east'] - $b['west'] > 0.16 || $b['north'] <= $b['south']) {
            return [null, 'Google върна твърде голяма област за „' . $query . '“', false];
        }
        return [$b, null, false];
    }

    /**
     * @param list<array<string,mixed>> $places
     * @return list<Candidate>
     */
    public static function parse(array $places): array
    {
        $out = [];
        foreach ($places as $p) {
            $name = trim((string) ($p['displayName']['text'] ?? ''));
            if ($name === '' || !isset($p['id'])) {
                continue;
            }
            $website = $p['websiteUri'] ?? null;
            $instagram = $facebook = null;
            // Google often lists a social profile as the "website".
            if (is_string($website) && preg_match('~(instagram\.com|facebook\.com|fb\.com)~i', $website, $m)) {
                if (stripos($m[1], 'instagram') !== false) {
                    $instagram = $website;
                } else {
                    $facebook = $website;
                }
                $website = null;
            }
            $hours = $p['regularOpeningHours']['weekdayDescriptions'] ?? null;
            $out[] = new Candidate(
                source: 'google',
                sourceId: (string) $p['id'],
                name: $name,
                lat: isset($p['location']['latitude']) ? (float) $p['location']['latitude'] : null,
                lng: isset($p['location']['longitude']) ? (float) $p['location']['longitude'] : null,
                address: $p['shortFormattedAddress'] ?? $p['formattedAddress'] ?? null,
                phone: $p['nationalPhoneNumber'] ?? null,
                website: is_string($website) ? $website : null,
                instagram: $instagram,
                facebook: $facebook,
                openingHours: is_array($hours) ? implode('; ', array_map('strval', $hours)) : null,
                rating: isset($p['rating']) ? (float) $p['rating'] : null,
                reviewsCount: isset($p['userRatingCount']) ? (int) $p['userRatingCount'] : null,
                url: $p['googleMapsUri'] ?? null,
                types: array_values(array_map('strval', is_array($p['types'] ?? null) ? $p['types'] : [])),
                closed: in_array($p['businessStatus'] ?? '', ['CLOSED_PERMANENTLY', 'CLOSED_TEMPORARILY'], true),
            );
        }
        return $out;
    }
}
