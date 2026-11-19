<?php

declare(strict_types=1);

namespace BarberLead\Tests\Discovery;

use BarberLead\Discovery\BoundaryImporter;
use BarberLead\Discovery\Candidate;
use BarberLead\Discovery\Geo;
use BarberLead\Discovery\GooglePlacesSource;
use BarberLead\Discovery\HttpClient;
use BarberLead\Discovery\OverpassSource;
use BarberLead\Discovery\Relevance;
use BarberLead\Tests\Fixtures;
use PHPUnit\Framework\TestCase;

final class ParsingTest extends TestCase
{
    public function testOverpassParsing(): void
    {
        $c = OverpassSource::parse(json_decode(Fixtures::overpassBusinessesJson(), true)['elements']);
        $this->assertCount(4, $c, 'unnamed shop is skipped');
        $this->assertSame('osm', $c[0]->source);
        $this->assertSame('node/1001', $c[0]->sourceId);
        $this->assertSame('+359 888 100 001', $c[0]->phone);
        $this->assertSame('ул. Тестова 1', $c[0]->address);
        $this->assertSame(42.6655, $c[1]->lat, 'way center is used');
        $this->assertSame('https://www.instagram.com/fixture_salon', $c[1]->instagram);
        $this->assertSame('https://www.openstreetmap.org/node/1001', $c[0]->url);
    }

    public function testGoogleParsingMovesSocialLinksOutOfWebsite(): void
    {
        $c = GooglePlacesSource::parse(json_decode(Fixtures::googleJson(), true)['places']);
        $this->assertCount(3, $c);
        $this->assertNull($c[0]->website);
        $this->assertSame('https://www.instagram.com/fixturebarberone', $c[0]->instagram);
        $this->assertSame('https://gents-fixture.example/', $c[1]->website);
        $this->assertSame(4.9, $c[0]->rating);
        $this->assertSame(120, $c[0]->reviewsCount);
    }

    public function testRelevance(): void
    {
        $this->assertSame('high', Relevance::classify(new Candidate('osm', '1', 'Gentlemen Cuts'))[0]);
        $this->assertSame('high', Relevance::classify(new Candidate('osm', '1', 'Бръснарница Иван'))[0]);
        $this->assertSame('high', Relevance::classify(new Candidate('osm', '1', 'Studio X', types: ['shop=hairdresser', 'hairdresser=barber']))[0]);
        $this->assertSame('high', Relevance::classify(new Candidate('google', '1', 'Studio X', types: ['barber_shop']))[0]);
        $this->assertSame('medium', Relevance::classify(new Candidate('osm', '1', 'Салон Мария', types: ['shop=hairdresser']))[0]);
        $this->assertSame('low', Relevance::classify(new Candidate('osm', '1', 'Салон Мария', types: ['shop=hairdresser', 'hairdresser=female']))[0]);
        $this->assertSame('low', Relevance::classify(new Candidate('google', '1', 'Nails & Lashes Studio', types: ['beauty_salon']))[0]);
        $this->assertSame('low', Relevance::classify(new Candidate('google', '1', 'Дамски салон Роза', types: ['hair_care']))[0]);
    }

    public function testGeometry(): void
    {
        $sq = Fixtures::square(42.0, 23.0, 42.1, 23.1);
        $this->assertTrue(Geo::contains([$sq], 42.05, 23.05));
        $this->assertFalse(Geo::contains([$sq], 42.15, 23.05));
        $this->assertSame(['south' => 42.0, 'west' => 23.0, 'north' => 42.1, 'east' => 23.1], Geo::bbox([$sq]));
        $rings = Geo::assembleRings([array_slice($sq, 0, 3), array_reverse(array_slice($sq, 2))]);
        $this->assertCount(1, $rings);
        $this->assertTrue(Geo::contains($rings, 42.05, 23.05));
        $d = Geo::distance(42.6977, 23.3219, 42.6977, 23.3229);
        $this->assertEqualsWithDelta(81.7, $d, 1.0);
    }

    public function testBoundaryNameCleaning(): void
    {
        $this->assertSame('Лозенец', BoundaryImporter::cleanName('район Лозенец'));
        $this->assertSame('Младост 1', BoundaryImporter::cleanName('ж.к. Младост 1'));
        $this->assertSame('Иван Вазов', BoundaryImporter::cleanName('кв. Иван Вазов'));
    }

    public function testOverpassQueryIsBoundedAndHttpClientRefusesOtherHosts(): void
    {
        $q = OverpassSource::query(['south' => 42.66, 'west' => 23.30, 'north' => 42.69, 'east' => 23.34]);
        $this->assertStringContainsString('(42.660000,23.300000,42.690000,23.340000)', $q);
        $r = (new HttpClient())->post('https://evil.example/x', '');
        $this->assertSame('Host not allowed: evil.example', $r['error']);
        $r = (new HttpClient())->post('http://overpass-api.de/api/interpreter', '');
        $this->assertNotNull($r['error'], 'plain http is refused');
    }
}
