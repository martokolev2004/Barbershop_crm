<?php

declare(strict_types=1);

namespace BarberLead\Discovery;

use BarberLead\Repositories\EventRepository;
use BarberLead\Support\Text;
use PDO;

/**
 * Identity resolution + idempotent upsert of source candidates.
 *  - same (source, id) seen before            → update that business
 *  - strong evidence (phone / domain / name+close) → attach to the existing business
 *  - partial evidence                         → new business + duplicate review entry (never silently merged)
 *  - nothing similar                           → new business
 * Existing values are never overwritten: empty fields are filled, differing ones are logged as conflicts.
 */
final class Importer
{
    public const AUTO_MATCH = 0.85;
    public const REVIEW = 0.5;
    private const SOCIAL_HOSTS = ['instagram.com', 'facebook.com', 'fb.com', 'tiktok.com', 'linktr.ee', 'google.com', 'goo.gl', 'booksy.com', 'fresha.com'];

    public function __construct(private PDO $db, private EventRepository $events, private ?AreaLocator $locator = null)
    {
    }

    /**
     * @return array{action:string, business_id:int, score?:float, reasons?:string}
     *         action: new | updated | unchanged | review
     */
    public function import(Candidate $c, ?int $fallbackAreaId, string $context): array
    {
        $stmt = $this->db->prepare('SELECT business_id, data FROM business_sources WHERE source = ? AND source_id = ?');
        $stmt->execute([$c->source, $c->sourceId]);
        $known = $stmt->fetch();
        if ($known !== false) {
            $bid = (int) $known['business_id'];
            $bid = $this->resolveMerged($bid);
            $changed = $this->saveSource($bid, $c, null, (string) $known['data']);
            $filled = $this->fillAndCompare($bid, $c, $changed);
            return ['action' => ($filled || $changed) ? 'updated' : 'unchanged', 'business_id' => $bid];
        }

        [$best, $score, $reasons] = $this->bestMatch($c);
        if ($best !== null && $score >= self::AUTO_MATCH) {
            $this->saveSource($best, $c, $reasons, null);
            $this->fillAndCompare($best, $c, true);
            $this->events->add($best, 'source', 'Свързан с ' . self::sourceLabel($c->source) . " ($reasons)", 'auto');
            return ['action' => 'updated', 'business_id' => $best, 'score' => $score, 'reasons' => $reasons];
        }

        $id = $this->create($c, $fallbackAreaId, $context);
        if ($best !== null && $score >= self::REVIEW) {
            $this->db->prepare('INSERT IGNORE INTO duplicate_candidates (business_id, other_id, score, reasons) VALUES (?, ?, ?, ?)')
                ->execute([$id, $best, round($score, 3), mb_substr($reasons, 0, 500)]);
            return ['action' => 'review', 'business_id' => $id, 'score' => $score, 'reasons' => $reasons];
        }
        return ['action' => 'new', 'business_id' => $id];
    }

    /** @return array{0: ?int, 1: float, 2: string} */
    public function bestMatch(Candidate $c): array
    {
        $phone = Text::normalizePhone($c->phone);
        $domain = self::ownDomain($c->website);
        $insta = self::handle($c->instagram);
        $name = Text::normalizeName($c->name);

        $where = ['(phone_normalized = ? )', '(website_domain = ?)', '(name_normalized = ?)'];
        $params = [$phone ?? '-', $domain ?? '-', $name];
        if ($c->lat !== null && $c->lng !== null) {
            $where[] = '(lat BETWEEN ? AND ? AND lng BETWEEN ? AND ?)';
            array_push($params, $c->lat - 0.01, $c->lat + 0.01, $c->lng - 0.014, $c->lng + 0.014);
        }
        if ($insta !== null) {
            $where[] = '(instagram_url LIKE ?)';
            $params[] = '%' . addcslashes($insta, '%_') . '%';
        }
        $stmt = $this->db->prepare(
            'SELECT id, name_normalized, phone_normalized, website_domain, instagram_url, lat, lng FROM businesses
             WHERE merged_into_id IS NULL AND (' . implode(' OR ', $where) . ') LIMIT 200'
        );
        $stmt->execute($params);

        $best = null;
        $bestScore = 0.0;
        $bestReasons = '';
        foreach ($stmt->fetchAll() as $b) {
            [$score, $reasons] = self::score($c, $phone, $domain, $insta, $name, $b);
            if ($score > $bestScore) {
                [$best, $bestScore, $bestReasons] = [(int) $b['id'], $score, $reasons];
            }
        }
        return [$best, $bestScore, $bestReasons];
    }

    /**
     * @param array<string,mixed> $b existing business row
     * @return array{0: float, 1: string}
     */
    public static function score(Candidate $c, ?string $phone, ?string $domain, ?string $insta, string $name, array $b): array
    {
        $score = 0.0;
        $why = [];
        if ($phone !== null && $phone === $b['phone_normalized']) {
            $score += 0.6;
            $why[] = 'същият телефон';
        }
        if ($domain !== null && $domain === $b['website_domain']) {
            $score += 0.55;
            $why[] = 'същият сайт';
        }
        if ($insta !== null && $insta === self::handle($b['instagram_url'])) {
            $score += 0.55;
            $why[] = 'същият Instagram';
        }
        similar_text($name, (string) $b['name_normalized'], $pct);
        $sim = $pct / 100;
        if ($sim >= 0.6) {
            $score += 0.4 * $sim;
            $why[] = 'сходно име (' . round($pct) . '%)';
        }
        if ($c->lat !== null && $b['lat'] !== null) {
            $d = Geo::distance($c->lat, $c->lng ?? 0.0, (float) $b['lat'], (float) $b['lng']);
            if ($d < 40) {
                $score += 0.35;
            } elseif ($d < 120) {
                $score += 0.25;
            } elseif ($d < 400) {
                $score += 0.05;
            } elseif ($d > 1500) {
                $score -= 0.3; // probably another branch of the same brand
            }
            $why[] = 'на ' . round($d) . ' м';
        }
        // Name + location alone must be really convincing to merge automatically.
        if ($phone === null && $domain === null && $insta === null && !($sim >= 0.85)) {
            $score = min($score, 0.8);
        }
        return [max(0.0, min(1.0, $score)), implode(', ', $why)];
    }

    private function create(Candidate $c, ?int $fallbackAreaId, string $context): int
    {
        [$relevance, $category, $reason] = Relevance::classify($c);
        $areaId = null;
        $geo = 'unknown';
        if ($c->lat !== null && $c->lng !== null && $this->locator !== null) {
            [$areaId, $geo] = $this->locator->locate($c->lat, $c->lng);
        }
        if ($areaId === null) {
            [$areaId, $geo] = [$fallbackAreaId, $fallbackAreaId !== null ? 'approx' : 'unknown'];
        }
        $website = self::ownDomain($c->website) !== null ? Text::normalizeUrl($c->website) : null;
        $phoneN = Text::normalizePhone($c->phone);
        $this->db->prepare(
            'INSERT INTO businesses (name, name_normalized, category, relevance, relevance_reason, area_id, address, lat, lng, geo_confidence,
                phone, phone_normalized, email, website_url, website_domain, instagram_url, facebook_url, working_hours, rating, reviews_count, status)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([
            mb_substr($c->name, 0, 200), Text::normalizeName($c->name), $category, $relevance, $reason, $areaId,
            $c->address !== null ? mb_substr($c->address, 0, 255) : null, $c->lat, $c->lng, $geo,
            $phoneN !== null ? $c->phone : null, $phoneN,
            $c->email !== null && filter_var($c->email, FILTER_VALIDATE_EMAIL) ? $c->email : null,
            $website, self::ownDomain($website),
            Text::normalizeUrl($c->instagram), Text::normalizeUrl($c->facebook),
            $c->openingHours !== null ? mb_substr($c->openingHours, 0, 500) : null,
            $c->rating, $c->reviewsCount, $c->closed ? 'archived' : 'new',
        ]);
        $id = (int) $this->db->lastInsertId();
        $this->saveSource($id, $c, 'нов запис', null);
        $this->events->add($id, 'discovered', 'Открит в ' . self::sourceLabel($c->source) . " ($context)" . ($c->closed ? ' — отбелязан като затворен' : ''), 'auto');
        return $id;
    }

    /** @return bool whether the stored source data changed */
    private function saveSource(int $businessId, Candidate $c, ?string $reason, ?string $oldData): bool
    {
        $data = (string) json_encode($c->toArray(), JSON_UNESCAPED_UNICODE);
        $this->db->prepare(
            'INSERT INTO business_sources (business_id, source, source_id, url, data, match_reason) VALUES (?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE business_id = VALUES(business_id), url = VALUES(url), data = VALUES(data), last_seen_at = NOW()'
        )->execute([$businessId, $c->source, $c->sourceId, $c->url, $data, $reason]);
        return $oldData !== null && $oldData !== $data;
    }

    /** Fills empty business fields from the candidate; logs differing values once (when the source data changed). */
    private function fillAndCompare(int $id, Candidate $c, bool $logConflicts): bool
    {
        $stmt = $this->db->prepare('SELECT * FROM businesses WHERE id = ?');
        $stmt->execute([$id]);
        $b = $stmt->fetch();
        if ($b === false) {
            return false;
        }
        $website = self::ownDomain($c->website) !== null ? Text::normalizeUrl($c->website) : null;
        $phoneN = Text::normalizePhone($c->phone);
        $map = [
            'phone' => [$phoneN !== null ? $c->phone : null, 'phone_normalized', $phoneN],
            'website_url' => [$website, 'website_domain', self::ownDomain($website)],
            'instagram_url' => [Text::normalizeUrl($c->instagram), null, null],
            'facebook_url' => [Text::normalizeUrl($c->facebook), null, null],
            'address' => [$c->address !== null ? mb_substr($c->address, 0, 255) : null, null, null],
            'working_hours' => [$c->openingHours !== null ? mb_substr($c->openingHours, 0, 500) : null, null, null],
            'email' => [$c->email !== null && filter_var($c->email, FILTER_VALIDATE_EMAIL) ? $c->email : null, null, null],
        ];
        $set = [];
        $params = [];
        $conflicts = [];
        foreach ($map as $field => [$value, $keyField, $keyValue]) {
            if ($value === null) {
                continue;
            }
            if ($b[$field] === null || $b[$field] === '') {
                $set[] = "$field = ?";
                $params[] = $value;
                if ($keyField !== null) {
                    $set[] = "$keyField = ?";
                    $params[] = $keyValue;
                }
            } elseif ($keyField !== null ? $b[$keyField] !== $keyValue : mb_strtolower(trim((string) $b[$field])) !== mb_strtolower(trim($value))) {
                $conflicts[] = "$field: „{$value}“";
            }
        }
        foreach (['lat' => $c->lat, 'lng' => $c->lng] as $f => $v) {
            if ($v !== null && $b[$f] === null) {
                $set[] = "$f = ?";
                $params[] = $v;
            }
        }
        // Ratings change over time: always take the latest reported value.
        if ($c->rating !== null && (string) $b['rating'] !== (string) $c->rating) {
            $set[] = 'rating = ?';
            $params[] = $c->rating;
        }
        if ($c->reviewsCount !== null && (int) $b['reviews_count'] !== $c->reviewsCount) {
            $set[] = 'reviews_count = ?';
            $params[] = $c->reviewsCount;
        }
        if ($set) {
            $params[] = $id;
            $this->db->prepare('UPDATE businesses SET ' . implode(', ', $set) . ' WHERE id = ?')->execute($params);
        }
        if ($conflicts && $logConflicts) {
            $this->events->add($id, 'conflict', 'Различни данни в ' . self::sourceLabel($c->source) . ': ' . implode('; ', $conflicts), 'auto');
        }
        return (bool) $set;
    }

    private function resolveMerged(int $id): int
    {
        for ($i = 0; $i < 5; $i++) {
            $stmt = $this->db->prepare('SELECT merged_into_id FROM businesses WHERE id = ?');
            $stmt->execute([$id]);
            $next = $stmt->fetchColumn();
            if (!$next) {
                return $id;
            }
            $id = (int) $next;
        }
        return $id;
    }

    /** Own website domain; social profiles and map links do not count as a website. */
    public static function ownDomain(?string $url): ?string
    {
        $d = Text::domain($url);
        if ($d === null) {
            return null;
        }
        foreach (self::SOCIAL_HOSTS as $h) {
            if ($d === $h || str_ends_with($d, '.' . $h)) {
                return null;
            }
        }
        return $d;
    }

    public static function handle(?string $instagramUrl): ?string
    {
        if ($instagramUrl === null || !preg_match('~instagram\.com/([A-Za-z0-9._]+)~i', $instagramUrl, $m)) {
            return null;
        }
        return strtolower($m[1]);
    }

    public static function sourceLabel(string $source): string
    {
        return ['osm' => 'OpenStreetMap', 'google' => 'Google Places', 'manual' => 'ръчно'][$source] ?? $source;
    }
}
