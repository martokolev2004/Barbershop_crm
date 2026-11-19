<?php

declare(strict_types=1);

namespace BarberLead\Tests;

use BarberLead\Discovery\HttpClient;

/**
 * FIXTURE DATA – invented for tests. These are NOT real Sofia businesses or real boundaries.
 * Geometry: a fake "Лозенец" district square with two neighborhood squares inside it.
 */
final class Fixtures
{
    /** @return list<array{0:float,1:float}> closed square ring */
    public static function square(float $s, float $w, float $n, float $e): array
    {
        return [[$s, $w], [$s, $e], [$n, $e], [$n, $w], [$s, $w]];
    }

    /** @param list<array{0:float,1:float}> $ring @return list<array{lat:float,lon:float}> */
    private static function geom(array $ring): array
    {
        return array_map(fn ($p) => ['lat' => $p[0], 'lon' => $p[1]], $ring);
    }

    public static function districtsJson(): string
    {
        $ring = self::square(42.660, 23.300, 42.690, 23.340);
        // relation split into two ways, as OSM does
        $half1 = array_slice($ring, 0, 3);
        $half2 = array_slice($ring, 2);
        return (string) json_encode(['elements' => [
            ['type' => 'relation', 'id' => 9001, 'tags' => ['name' => 'район Лозенец', 'admin_level' => '9'], 'members' => [
                ['type' => 'way', 'role' => 'outer', 'geometry' => self::geom($half1)],
                ['type' => 'way', 'role' => 'outer', 'geometry' => self::geom($half2)],
            ]],
            ['type' => 'relation', 'id' => 9002, 'tags' => ['name' => 'район Триадица', 'admin_level' => '9'], 'members' => [
                ['type' => 'way', 'role' => 'outer', 'geometry' => self::geom(self::square(42.690, 23.300, 42.700, 23.340))],
            ]],
        ]]);
    }

    public static function neighborhoodsJson(): string
    {
        return (string) json_encode(['elements' => [
            // known neighborhood, correct district
            ['type' => 'way', 'id' => 8001, 'tags' => ['name' => 'кв. Лозенец', 'place' => 'quarter'], 'geometry' => self::geom(self::square(42.670, 23.310, 42.685, 23.335))],
            // known neighborhood seeded under Лозенец, but OSM puts it in Триадица → must be moved
            ['type' => 'way', 'id' => 8002, 'tags' => ['name' => 'Хладилника', 'place' => 'quarter'], 'geometry' => self::geom(self::square(42.691, 23.301, 42.699, 23.309))],
            // unknown neighborhood → added under the containing district
            ['type' => 'way', 'id' => 8003, 'tags' => ['name' => 'Фикстура Парк', 'place' => 'neighbourhood'], 'geometry' => self::geom(self::square(42.661, 23.301, 42.668, 23.309))],
        ]]);
    }

    public static function overpassBusinessesJson(): string
    {
        return (string) json_encode(['elements' => [
            ['type' => 'node', 'id' => 1001, 'lat' => 42.675, 'lon' => 23.320, 'tags' => ['shop' => 'hairdresser', 'hairdresser' => 'barber',
                'name' => 'FIXTURE Barber One', 'phone' => '+359 888 100 001', 'addr:street' => 'ул. Тестова', 'addr:housenumber' => '1', 'opening_hours' => 'Mo-Sa 10:00-19:00']],
            ['type' => 'way', 'id' => 1002, 'center' => ['lat' => 42.6655, 'lon' => 23.305], 'tags' => ['shop' => 'hairdresser',
                'name' => 'FIXTURE Салон Красота', 'website' => 'https://fixture-salon.example', 'contact:instagram' => 'fixture_salon']],
            ['type' => 'node', 'id' => 1003, 'lat' => 42.676, 'lon' => 23.321, 'tags' => ['shop' => 'hairdresser', 'hairdresser' => 'female', 'name' => 'FIXTURE Дамски салон']],
            ['type' => 'node', 'id' => 1004, 'lat' => 42.695, 'lon' => 23.320, 'tags' => ['shop' => 'barber', 'name' => 'FIXTURE Outside Barber']], // in Триадица
            ['type' => 'node', 'id' => 1005, 'lat' => 42.677, 'lon' => 23.322, 'tags' => ['shop' => 'hairdresser']], // unnamed → skipped
        ]]);
    }

    public static function googleJson(?string $nextToken = null): string
    {
        return (string) json_encode(array_filter(['places' => [
            // same as OSM node 1001 (same phone) → must attach, not duplicate
            ['id' => 'gFIX1', 'displayName' => ['text' => 'Fixture Barber One'], 'location' => ['latitude' => 42.67502, 'longitude' => 23.32001],
                'nationalPhoneNumber' => '0888 100 001', 'websiteUri' => 'https://www.instagram.com/fixturebarberone', 'rating' => 4.9, 'userRatingCount' => 120,
                'types' => ['barber_shop'], 'businessStatus' => 'OPERATIONAL', 'googleMapsUri' => 'https://maps.google.com/?cid=1'],
            // new one, only in Google
            ['id' => 'gFIX2', 'displayName' => ['text' => 'FIXTURE Gents Club'], 'location' => ['latitude' => 42.68, 'longitude' => 23.33],
                'nationalPhoneNumber' => '0888 100 002', 'websiteUri' => 'https://gents-fixture.example/', 'rating' => 4.5, 'userRatingCount' => 40, 'types' => ['hair_care']],
            // similar name & close to "FIXTURE Barber One" but different phone → review, not auto-merge
            ['id' => 'gFIX3', 'displayName' => ['text' => 'FIXTURE Barber One Studio'], 'location' => ['latitude' => 42.6751, 'longitude' => 23.3201],
                'nationalPhoneNumber' => '0888 100 003', 'types' => ['hair_care']],
        ], 'nextPageToken' => $nextToken]));
    }
}

/** Fake transport: answers by host from a queue; records every call. */
final class FakeHttp extends HttpClient
{
    /** @var array<string, list<array{status:int, body:string, error:?string}>> */
    public array $queue = [];
    /** @var list<array{url:string, body:string}> */
    public array $calls = [];

    public function push(string $host, int $status, string $body, ?string $error = null): self
    {
        $this->queue[$host][] = ['status' => $status, 'body' => $body, 'error' => $error];
        return $this;
    }

    public function post(string $url, string $body, array $headers = [], int $timeout = 60): array
    {
        $this->calls[] = ['url' => $url, 'body' => $body];
        $host = (string) parse_url($url, PHP_URL_HOST);
        if (!in_array($host, self::ALLOWED_HOSTS, true)) {
            return ['status' => 0, 'body' => '', 'error' => "Host not allowed: $host"];
        }
        return array_shift($this->queue[$host]) ?? ['status' => 500, 'body' => '', 'error' => null];
    }
}
