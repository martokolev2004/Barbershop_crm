<?php

declare(strict_types=1);

namespace BarberLead\Discovery;

use PDO;

/** Finds the most specific area (neighborhood before district) whose OSM polygon contains a point. */
final class AreaLocator
{
    /** @var list<array{id:int, depth:int, rings:list<list<array{0:float,1:float}>>, bbox:array{south:float,west:float,north:float,east:float}}> */
    private array $areas = [];

    public function __construct(PDO $db)
    {
        $depth = ['country' => 0, 'city' => 1, 'district' => 2, 'neighborhood' => 3];
        foreach ($db->query('SELECT id, type, geometry FROM areas WHERE geometry IS NOT NULL') as $row) {
            $rings = json_decode((string) $row['geometry'], true);
            $bbox = is_array($rings) ? Geo::bbox($rings) : null;
            if ($bbox !== null) {
                $this->areas[] = ['id' => (int) $row['id'], 'depth' => $depth[$row['type']] ?? 0, 'rings' => $rings, 'bbox' => $bbox];
            }
        }
        usort($this->areas, fn ($a, $b) => $b['depth'] <=> $a['depth']);
    }

    /** @return array{0: ?int, 1: string} [area id, 'verified'|'unknown'] */
    public function locate(float $lat, float $lng): array
    {
        foreach ($this->areas as $a) {
            $b = $a['bbox'];
            if ($lat < $b['south'] || $lat > $b['north'] || $lng < $b['west'] || $lng > $b['east']) {
                continue;
            }
            if (Geo::contains($a['rings'], $lat, $lng)) {
                return [$a['id'], 'verified'];
            }
        }
        return [null, 'unknown'];
    }

    public function hasGeometry(): bool
    {
        return $this->areas !== [];
    }
}
