<?php

declare(strict_types=1);

namespace BarberLead\Discovery;

final class Geo
{
    /** Great-circle distance in metres. */
    public static function distance(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $r = 6371000.0;
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);
        $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;
        return 2 * $r * asin(min(1.0, sqrt($a)));
    }

    /**
     * Even-odd point-in-polygon over outer rings (each ring: list of [lat, lng]).
     * @param list<list<array{0:float,1:float}>> $rings
     */
    public static function contains(array $rings, float $lat, float $lng): bool
    {
        foreach ($rings as $ring) {
            $inside = false;
            $n = count($ring);
            for ($i = 0, $j = $n - 1; $i < $n; $j = $i++) {
                [$yi, $xi] = $ring[$i];
                [$yj, $xj] = $ring[$j];
                if ((($yi > $lat) !== ($yj > $lat)) && ($lng < ($xj - $xi) * ($lat - $yi) / (($yj - $yi) ?: 1e-12) + $xi)) {
                    $inside = !$inside;
                }
            }
            if ($inside) {
                return true;
            }
        }
        return false;
    }

    /**
     * @param list<list<array{0:float,1:float}>> $rings
     * @return array{south:float,west:float,north:float,east:float}|null
     */
    public static function bbox(array $rings): ?array
    {
        $s = $w = INF;
        $n = $e = -INF;
        foreach ($rings as $ring) {
            foreach ($ring as [$lat, $lng]) {
                $s = min($s, $lat);
                $n = max($n, $lat);
                $w = min($w, $lng);
                $e = max($e, $lng);
            }
        }
        return is_finite($s) ? ['south' => $s, 'west' => $w, 'north' => $n, 'east' => $e] : null;
    }

    /**
     * Joins way segments (each a list of [lat,lng]) into closed rings, as OSM multipolygon relations need.
     * @param list<list<array{0:float,1:float}>> $segments
     * @return list<list<array{0:float,1:float}>>
     */
    public static function assembleRings(array $segments): array
    {
        $segments = array_values(array_filter($segments, fn ($s) => count($s) >= 2));
        $rings = [];
        while ($segments) {
            $ring = array_shift($segments);
            $guard = 0;
            while (!self::same($ring[0], $ring[count($ring) - 1]) && $guard++ < 10000) {
                $end = $ring[count($ring) - 1];
                $found = false;
                foreach ($segments as $k => $seg) {
                    if (self::same($seg[0], $end)) {
                        array_pop($ring);
                        $ring = array_merge($ring, $seg);
                    } elseif (self::same($seg[count($seg) - 1], $end)) {
                        array_pop($ring);
                        $ring = array_merge($ring, array_reverse($seg));
                    } else {
                        continue;
                    }
                    unset($segments[$k]);
                    $found = true;
                    break;
                }
                if (!$found) {
                    break; // open ring: OSM data incomplete; keep what we have
                }
            }
            if (count($ring) >= 4) {
                $rings[] = $ring;
            }
            $segments = array_values($segments);
        }
        return $rings;
    }

    /** @param array{0:float,1:float} $a @param array{0:float,1:float} $b */
    private static function same(array $a, array $b): bool
    {
        return abs($a[0] - $b[0]) < 1e-7 && abs($a[1] - $b[1]) < 1e-7;
    }
}
