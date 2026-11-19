<?php

declare(strict_types=1);

namespace BarberLead\Discovery;

use BarberLead\Support\Text;
use PDO;

/**
 * Imports district (admin_level 9) and neighborhood (place=suburb|quarter|neighbourhood) polygons for a city
 * from OpenStreetMap, matches them to our areas by name and marks them verified. Neighborhoods are moved
 * under the district that actually contains them; OSM neighborhoods we did not know are added.
 */
class BoundaryImporter
{
    /** Search rectangles per city slug. */
    public const CITY_BBOX = [
        'sofia' => ['south' => 42.55, 'west' => 23.10, 'north' => 42.82, 'east' => 23.56],
        'plovdiv' => ['south' => 42.09, 'west' => 24.64, 'north' => 42.20, 'east' => 24.83],
        'varna' => ['south' => 43.14, 'west' => 27.78, 'north' => 43.28, 'east' => 27.99],
        'burgas' => ['south' => 42.44, 'west' => 27.33, 'north' => 42.57, 'east' => 27.53],
    ];

    public function __construct(private PDO $db, private HttpClient $http)
    {
    }

    public static function districtQuery(array $b): string
    {
        $bb = sprintf('%.4f,%.4f,%.4f,%.4f', $b['south'], $b['west'], $b['north'], $b['east']);
        return "[out:json][timeout:90];relation[\"boundary\"=\"administrative\"][\"admin_level\"=\"9\"]($bb);out geom;";
    }

    public static function neighborhoodQuery(array $b): string
    {
        $bb = sprintf('%.4f,%.4f,%.4f,%.4f', $b['south'], $b['west'], $b['north'], $b['east']);
        return "[out:json][timeout:90];(way[\"place\"~\"^(suburb|quarter|neighbourhood)$\"]($bb);"
            . "relation[\"place\"~\"^(suburb|quarter|neighbourhood)$\"]($bb););out geom;";
    }

    /**
     * Runs one of the two steps ('districts' or 'neighborhoods') for a city.
     * @return array{ok:bool, transient:bool, message:string, matched:int, added:int, moved:int}
     */
    public function run(int $cityId, string $step, int $attempt = 0): array
    {
        $city = $this->db->prepare("SELECT * FROM areas WHERE id = ? AND type = 'city'");
        $city->execute([$cityId]);
        $c = $city->fetch();
        $bbox = $c ? (self::CITY_BBOX[$c['slug']] ?? null) : null;
        if ($bbox === null) {
            return ['ok' => false, 'transient' => false, 'message' => 'Няма зададена област за търсене за този град', 'matched' => 0, 'added' => 0, 'moved' => 0];
        }
        $query = $step === 'districts' ? self::districtQuery($bbox) : self::neighborhoodQuery($bbox);
        $resp = $this->http->post(OverpassSource::endpoint($attempt), http_build_query(['data' => $query]),
            ['Content-Type' => 'application/x-www-form-urlencoded'], 100);
        if ($resp['error'] !== null || $resp['status'] !== 200) {
            return ['ok' => false, 'transient' => HttpClient::isTransient($resp['status'], $resp['error']),
                'message' => 'OpenStreetMap: ' . ($resp['error'] ?? 'HTTP ' . $resp['status']), 'matched' => 0, 'added' => 0, 'moved' => 0];
        }
        $data = json_decode($resp['body'], true);
        unset($resp);
        if (!is_array($data) || !is_array($data['elements'] ?? null)) {
            return ['ok' => false, 'transient' => true, 'message' => 'OpenStreetMap: неразбираем отговор', 'matched' => 0, 'added' => 0, 'moved' => 0];
        }
        return $step === 'districts'
            ? $this->applyDistricts((int) $c['id'], $data['elements'])
            : $this->applyNeighborhoods((int) $c['id'], $data['elements']);
    }

    /** @param list<array<string,mixed>> $elements @return array{ok:bool, transient:bool, message:string, matched:int, added:int, moved:int} */
    public function applyDistricts(int $cityId, array $elements): array
    {
        $existing = $this->children($cityId, 'district');
        $matched = $added = 0;
        foreach ($elements as $el) {
            $name = self::cleanName((string) ($el['tags']['name'] ?? ''));
            $rings = self::rings($el);
            if ($name === '' || !$rings) {
                continue;
            }
            $key = Text::normalizeName($name);
            if (isset($existing[$key])) {
                $this->saveGeometry($existing[$key], $rings, 'osm:' . $el['type'] . '/' . $el['id'], (int) $el['id']);
                $matched++;
            } elseif ($cityId > 0 && $this->cityHasNoSeededDistricts($cityId, $existing)) {
                $this->insertArea($cityId, 'district', $name, $rings, $el);
                $added++;
            }
        }
        return ['ok' => true, 'transient' => false, 'message' => "Райони: $matched съвпаднаха, $added нови", 'matched' => $matched, 'added' => $added, 'moved' => 0];
    }

    /** @param list<array<string,mixed>> $elements @return array{ok:bool, transient:bool, message:string, matched:int, added:int, moved:int} */
    public function applyNeighborhoods(int $cityId, array $elements): array
    {
        $districts = [];
        $stmt = $this->db->prepare("SELECT id, geometry FROM areas WHERE parent_id = ? AND type = 'district' AND geometry IS NOT NULL");
        $stmt->execute([$cityId]);
        foreach ($stmt->fetchAll() as $d) {
            $districts[(int) $d['id']] = json_decode((string) $d['geometry'], true);
        }
        // All neighborhoods of the city, keyed by normalised name.
        $stmt = $this->db->prepare("SELECT n.id, n.name_bg, n.parent_id FROM areas n JOIN areas d ON d.id = n.parent_id WHERE d.parent_id = ? AND n.type = 'neighborhood'");
        $stmt->execute([$cityId]);
        $known = [];
        foreach ($stmt->fetchAll() as $n) {
            $known[Text::normalizeName($n['name_bg'])] = $n;
        }
        $matched = $added = $moved = 0;
        foreach ($elements as $el) {
            $name = self::cleanName((string) ($el['tags']['name'] ?? ''));
            $rings = self::rings($el);
            if ($name === '' || !$rings) {
                continue;
            }
            [$clat, $clng] = self::centroid($rings);
            $districtId = null;
            foreach ($districts as $id => $dRings) {
                if (is_array($dRings) && Geo::contains($dRings, $clat, $clng)) {
                    $districtId = $id;
                    break;
                }
            }
            $key = Text::normalizeName($name);
            if (isset($known[$key])) {
                $areaId = (int) $known[$key]['id'];
                $this->saveGeometry($areaId, $rings, 'osm:' . $el['type'] . '/' . $el['id'], (int) $el['id']);
                if ($districtId !== null && $districtId !== (int) $known[$key]['parent_id'] && !$this->slugTaken($districtId, $name, $areaId)) {
                    $this->db->prepare('UPDATE areas SET parent_id = ? WHERE id = ?')->execute([$districtId, $areaId]);
                    $moved++;
                }
                $matched++;
            } elseif ($districtId !== null && !$this->slugTaken($districtId, $name, 0)) {
                $this->insertArea($districtId, 'neighborhood', $name, $rings, $el);
                $known[$key] = ['id' => 0, 'name_bg' => $name, 'parent_id' => $districtId];
                $added++;
            }
        }
        return ['ok' => true, 'transient' => false, 'message' => "Квартали: $matched съвпаднаха, $added нови, $moved преместени в правилния район",
            'matched' => $matched, 'added' => $added, 'moved' => $moved];
    }

    /** @return array<string,int> normalised name => id */
    private function children(int $parentId, string $type): array
    {
        $stmt = $this->db->prepare('SELECT id, name_bg FROM areas WHERE parent_id = ? AND type = ?');
        $stmt->execute([$parentId, $type]);
        $out = [];
        foreach ($stmt->fetchAll() as $r) {
            $out[Text::normalizeName($r['name_bg'])] = (int) $r['id'];
        }
        return $out;
    }

    /** @param array<string,int> $existing */
    private function cityHasNoSeededDistricts(int $cityId, array $existing): bool
    {
        // Sofia's 24 districts are seeded; other cities get their districts from OSM.
        return $existing === [] || $this->db->query("SELECT slug FROM areas WHERE id = $cityId")->fetchColumn() !== 'sofia';
    }

    private function slugTaken(int $parentId, string $name, int $exceptId): bool
    {
        $stmt = $this->db->prepare('SELECT COUNT(*) FROM areas WHERE parent_id = ? AND slug = ? AND id <> ?');
        $stmt->execute([$parentId, Text::slug($name), $exceptId]);
        return (int) $stmt->fetchColumn() > 0;
    }

    /** @param list<list<array{0:float,1:float}>> $rings @param array<string,mixed> $el */
    private function insertArea(int $parentId, string $type, string $name, array $rings, array $el): void
    {
        $en = (string) ($el['tags']['name:en'] ?? Text::translit($name));
        $this->db->prepare(
            "INSERT INTO areas (parent_id, type, name_bg, name_en, slug, sort_order, geo_confidence, osm_id, geometry, geometry_source, geometry_updated_at)
             VALUES (?, ?, ?, ?, ?, 900, 'verified', ?, ?, ?, NOW())"
        )->execute([$parentId, $type, $name, $en, Text::slug($name), (int) $el['id'], json_encode(self::simplify($rings)), 'osm:' . $el['type'] . '/' . $el['id']]);
        $id = (int) $this->db->lastInsertId();
        $this->setBbox($id, $rings);
    }

    /** @param list<list<array{0:float,1:float}>> $rings */
    private function saveGeometry(int $areaId, array $rings, string $source, int $osmId): void
    {
        $this->db->prepare("UPDATE areas SET geometry = ?, geometry_source = ?, geometry_updated_at = NOW(), geo_confidence = 'verified', osm_id = ? WHERE id = ?")
            ->execute([json_encode(self::simplify($rings)), $source, $osmId, $areaId]);
        $this->setBbox($areaId, $rings);
    }

    /** @param list<list<array{0:float,1:float}>> $rings */
    private function setBbox(int $areaId, array $rings): void
    {
        $b = Geo::bbox($rings);
        [$lat, $lng] = self::centroid($rings);
        $this->db->prepare('UPDATE areas SET bbox_south = ?, bbox_west = ?, bbox_north = ?, bbox_east = ?, lat = ?, lng = ? WHERE id = ?')
            ->execute([$b['south'] ?? null, $b['west'] ?? null, $b['north'] ?? null, $b['east'] ?? null, $lat, $lng, $areaId]);
    }

    /** "район Лозенец" → "Лозенец", "ж.к. Младост 1" → "Младост 1" */
    public static function cleanName(string $name): string
    {
        $name = trim($name);
        $name = (string) preg_replace('/^(район|р-н|ж\.?\s?к\.?|кв\.?|квартал|жилищен комплекс|в\.?\s?з\.?|м\.)\s+/iu', '', $name);
        return trim((string) preg_replace('/\s+/', ' ', $name));
    }

    /**
     * Outer rings of an OSM way (closed) or multipolygon relation, from "out geom" output.
     * @param array<string,mixed> $el
     * @return list<list<array{0:float,1:float}>>
     */
    public static function rings(array $el): array
    {
        if (($el['type'] ?? '') === 'way' && is_array($el['geometry'] ?? null)) {
            $ring = self::toRing($el['geometry']);
            return count($ring) >= 4 ? [$ring] : [];
        }
        if (($el['type'] ?? '') === 'relation' && is_array($el['members'] ?? null)) {
            $segments = [];
            foreach ($el['members'] as $m) {
                if (($m['type'] ?? '') === 'way' && ($m['role'] ?? 'outer') !== 'inner' && is_array($m['geometry'] ?? null)) {
                    $segments[] = self::toRing($m['geometry']);
                }
            }
            return Geo::assembleRings($segments);
        }
        return [];
    }

    /** @param list<array<string,mixed>> $geom @return list<array{0:float,1:float}> */
    private static function toRing(array $geom): array
    {
        return array_map(fn ($p) => [round((float) $p['lat'], 6), round((float) $p['lon'], 6)], $geom);
    }

    /** @param list<list<array{0:float,1:float}>> $rings @return array{0:float,1:float} */
    public static function centroid(array $rings): array
    {
        $lat = $lng = 0.0;
        $n = 0;
        foreach ($rings as $ring) {
            foreach ($ring as [$a, $b]) {
                $lat += $a;
                $lng += $b;
                $n++;
            }
        }
        return $n ? [$lat / $n, $lng / $n] : [0.0, 0.0];
    }

    /**
     * Drops points closer than ~15 m to the previous kept point (keeps storage and memory small).
     * @param list<list<array{0:float,1:float}>> $rings
     * @return list<list<array{0:float,1:float}>>
     */
    public static function simplify(array $rings): array
    {
        $out = [];
        foreach ($rings as $ring) {
            $kept = [$ring[0]];
            $last = $ring[0];
            $count = count($ring);
            for ($i = 1; $i < $count - 1; $i++) {
                if (abs($ring[$i][0] - $last[0]) + abs($ring[$i][1] - $last[1]) > 0.00015) {
                    $kept[] = $ring[$i];
                    $last = $ring[$i];
                }
            }
            $kept[] = $ring[$count - 1];
            if (count($kept) >= 4) {
                $out[] = $kept;
            }
        }
        return $out;
    }
}
