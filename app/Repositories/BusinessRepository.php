<?php

declare(strict_types=1);

namespace BarberLead\Repositories;

use BarberLead\Status;
use InvalidArgumentException;
use PDO;

final class BusinessRepository
{
    public const SORTS = [
        'updated' => 'b.updated_at',
        'name' => 'b.name',
        'score' => 'COALESCE(b.lead_score_override, b.lead_score)',
        'rating' => 'b.rating',
        'created' => 'b.created_at',
    ];

    private const FIELD_LABELS = [
        'name' => 'име', 'brand' => 'марка', 'area_id' => 'квартал', 'address' => 'адрес', 'phone' => 'телефон',
        'email' => 'имейл', 'website_url' => 'сайт', 'website_status' => 'оценка на сайта',
        'instagram_url' => 'Instagram', 'facebook_url' => 'Facebook', 'tiktok_url' => 'TikTok',
        'booking_method' => 'начин на записване', 'working_hours' => 'работно време', 'rating' => 'рейтинг',
        'reviews_count' => 'брой отзиви', 'lead_score_override' => 'ръчна оценка', 'notes' => 'бележки',
        'lat' => 'ширина', 'lng' => 'дължина',
    ];

    public function __construct(private PDO $db, private EventRepository $events)
    {
    }

    /** @return array<string,mixed>|null */
    public function find(int $id): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM businesses WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    /**
     * @param array{q?:string, area_ids?:list<int>, status?:string, website?:string, relevance?:string, sort?:string, dir?:string, page?:int, per_page?:int} $f
     * @return array{rows: list<array<string,mixed>>, total: int, page: int, pages: int}
     */
    public function search(array $f): array
    {
        [$where, $params] = $this->where($f);
        $sort = self::SORTS[$f['sort'] ?? 'updated'] ?? self::SORTS['updated'];
        $dir = strtolower($f['dir'] ?? '') === 'asc' ? 'ASC' : 'DESC';
        $perPage = max(10, min(200, $f['per_page'] ?? 50));
        $page = max(1, $f['page'] ?? 1);

        $count = $this->db->prepare("SELECT COUNT(*) FROM businesses b WHERE $where");
        $count->execute($params);
        $total = (int) $count->fetchColumn();
        $pages = max(1, (int) ceil($total / $perPage));
        $page = min($page, $pages);

        $stmt = $this->db->prepare(
            "SELECT b.*, COALESCE(b.lead_score_override, b.lead_score) AS score,
                    (SELECT MAX(o.sent_at) FROM offers o WHERE o.business_id = b.id) AS last_offer_at
             FROM businesses b WHERE $where
             ORDER BY $sort IS NULL, $sort $dir, b.id DESC
             LIMIT $perPage OFFSET " . (($page - 1) * $perPage)
        );
        $stmt->execute($params);
        return ['rows' => $stmt->fetchAll(), 'total' => $total, 'page' => $page, 'pages' => $pages];
    }

    /**
     * @param array<string,mixed> $f
     * @return array{0:string, 1:list<mixed>}
     */
    private function where(array $f): array
    {
        $where = ['1=1'];
        $params = [];
        if (!empty($f['area_ids'])) {
            $where[] = 'b.area_id IN (' . implode(',', array_map('intval', $f['area_ids'])) . ')';
        }
        if (!empty($f['status'])) {
            $where[] = 'b.status = ?';
            $params[] = $f['status'];
        } else {
            $where[] = "b.status NOT IN ('archived','rejected','researching')";
        }
        if (($f['relevance'] ?? '') === 'relevant') {
            $where[] = "b.relevance IN ('high','medium')";
        } elseif (in_array($f['relevance'] ?? '', ['high', 'medium', 'low'], true)) {
            $where[] = 'b.relevance = ?';
            $params[] = $f['relevance'];
        }
        if (!empty($f['website'])) {
            $where[] = 'b.website_status = ?';
            $params[] = $f['website'];
        }
        if (!empty($f['q'])) {
            $q = (string) $f['q'];
            $like = '%' . addcslashes($q, '%_\\') . '%';
            $digits = preg_replace('/\D+/', '', $q);
            $parts = ['b.name LIKE ?', 'b.brand LIKE ?', 'b.address LIKE ?', 'b.website_url LIKE ?',
                'b.instagram_url LIKE ?', 'b.facebook_url LIKE ?', 'b.email LIKE ?',
                'b.area_id IN (SELECT id FROM areas WHERE name_bg LIKE ? OR name_en LIKE ?)'];
            array_push($params, $like, $like, $like, $like, $like, $like, $like, $like, $like);
            if (is_string($digits) && strlen($digits) >= 4) {
                // Match phone numbers regardless of spaces / +359 prefix.
                $parts[] = 'b.phone_normalized LIKE ?';
                $params[] = '%' . ltrim($digits, '0') . '%';
            }
            $where[] = '(' . implode(' OR ', $parts) . ')';
        }
        return [implode(' AND ', $where), $params];
    }

    /** @param array<string,mixed> $data validated by BusinessInput */
    public function create(array $data, string $actor): int
    {
        $cols = array_keys($data);
        $this->db->prepare(
            'INSERT INTO businesses (' . implode(',', $cols) . ') VALUES (' . implode(',', array_fill(0, count($cols), '?')) . ')'
        )->execute(array_values($data));
        $id = (int) $this->db->lastInsertId();
        $this->events->add($id, 'created', 'Записът е създаден ръчно', $actor);
        return $id;
    }

    /**
     * Updates only changed fields and writes one history event listing them.
     * @param array<string,mixed> $data validated by BusinessInput
     * @return list<string> changed field names
     */
    public function update(int $id, array $data, string $actor): array
    {
        $current = $this->find($id);
        if ($current === null) {
            throw new InvalidArgumentException('Business not found');
        }
        $changed = [];
        foreach ($data as $k => $v) {
            $old = $current[$k] ?? null;
            if ((string) ($old ?? '') !== (string) ($v ?? '') && !(is_numeric($old) && is_numeric($v) && (float) $old === (float) $v)) {
                $changed[$k] = $v;
            }
        }
        if (!$changed) {
            return [];
        }
        $set = implode(',', array_map(fn ($c) => "$c = ?", array_keys($changed)));
        $this->db->prepare("UPDATE businesses SET $set WHERE id = ?")->execute([...array_values($changed), $id]);
        $labels = array_values(array_filter(array_map(fn ($k) => self::FIELD_LABELS[$k] ?? null, array_keys($changed))));
        if ($labels) {
            $this->events->add($id, 'edited', 'Редактирано: ' . implode(', ', $labels), $actor);
        }
        return array_keys($changed);
    }

    public function setStatus(int $id, string $status, string $actor): bool
    {
        if (!Status::isValid($status)) {
            throw new InvalidArgumentException("Invalid status $status");
        }
        $current = $this->find($id);
        if ($current === null || $current['status'] === $status) {
            return false;
        }
        $this->db->prepare('UPDATE businesses SET status = ? WHERE id = ?')->execute([$status, $id]);
        $this->events->add($id, 'status', Status::label($current['status']) . ' → ' . Status::label($status), $actor,
            ['from' => $current['status'], 'to' => $status]);
        return true;
    }

    /** Owner rejects a lead (not a fit). Kept for history; rescans never bring it back. */
    public function reject(int $id, string $reason, string $actor): bool
    {
        if (!$this->setStatus($id, 'rejected', $actor)) {
            return false;
        }
        if ($reason !== '') {
            $this->events->add($id, 'note', 'Причина за отхвърляне: ' . $reason, $actor);
        }
        return true;
    }

    /**
     * How many businesses each tab (barbershops / salons / unsuitable) would show with the other filters applied.
     * @param array<string,mixed> $f
     * @return array<string,int>
     */
    public function countsByRelevance(array $f): array
    {
        $f['relevance'] = 'all';
        [$where, $params] = $this->where($f);
        $stmt = $this->db->prepare("SELECT b.relevance, COUNT(*) c FROM businesses b WHERE $where GROUP BY b.relevance");
        $stmt->execute($params);
        $out = ['high' => 0, 'medium' => 0, 'low' => 0];
        foreach ($stmt->fetchAll() as $r) {
            $out[$r['relevance']] = (int) $r['c'];
        }
        $out['all'] = array_sum($out);
        return $out;
    }

    /** Owner's manual call: barbershop / salon / unsuitable. Rescans never change it. */
    public function setRelevance(int $id, string $relevance, string $actor): bool
    {
        $labels = ['high' => 'Бръснарници', 'medium' => 'Салони', 'low' => 'Неподходящи'];
        if (!isset($labels[$relevance])) {
            throw new InvalidArgumentException('Invalid relevance');
        }
        $stmt = $this->db->prepare('UPDATE businesses SET relevance = ?, relevance_reason = ? WHERE id = ? AND relevance <> ?');
        $stmt->execute([$relevance, 'Ръчно: ' . $labels[$relevance], $id, $relevance]);
        if ($stmt->rowCount() === 0) {
            return false;
        }
        $this->events->add($id, 'edited', 'Преместен в ' . $labels[$relevance], $actor);
        return true;
    }

    public function countRejected(): int
    {
        return (int) $this->db->query("SELECT COUNT(*) FROM businesses WHERE status = 'rejected'")->fetchColumn();
    }

    public function countResearching(): int
    {
        return (int) $this->db->query("SELECT COUNT(*) FROM businesses WHERE status = 'researching'")->fetchColumn();
    }

    public function addNote(int $id, string $note, string $actor): void
    {
        $this->events->add($id, 'note', $note, $actor);
    }

    /** Possible duplicates of a new/edited record (Phase 2 adds scoring; this is the cheap exact check). @return list<array<string,mixed>> */
    public function likelyDuplicates(?string $phoneNormalized, ?string $domain, string $nameNormalized, ?int $exceptId = null): array
    {
        $parts = ['name_normalized = ?'];
        $params = [$nameNormalized];
        if ($phoneNormalized) {
            $parts[] = 'phone_normalized = ?';
            $params[] = $phoneNormalized;
        }
        if ($domain) {
            $parts[] = 'website_domain = ?';
            $params[] = $domain;
        }
        $sql = 'SELECT id, name, address FROM businesses WHERE merged_into_id IS NULL AND (' . implode(' OR ', $parts) . ')';
        if ($exceptId) {
            $sql .= ' AND id <> ?';
            $params[] = $exceptId;
        }
        $stmt = $this->db->prepare($sql . ' LIMIT 5');
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    /**
     * Figures for a set of areas (or everything when null). All numbers come straight from the database.
     * @param list<int>|null $areaIds
     * @return array<string,int>
     */
    public function stats(?array $areaIds = null): array
    {
        $where = "status NOT IN ('archived','rejected') AND relevance IN ('high','medium')";
        if ($areaIds !== null) {
            $where .= $areaIds ? ' AND area_id IN (' . implode(',', array_map('intval', $areaIds)) . ')' : ' AND 0';
        }
        $row = $this->db->query(
            "SELECT COUNT(*) AS total,
                    COALESCE(SUM(website_status = 'none'), 0) AS no_website,
                    COALESCE(SUM(website_status = 'weak'), 0) AS weak_website,
                    COALESCE(SUM(website_status = 'unknown'), 0) AS unknown_website,
                    COALESCE(SUM(status IN ('qualified','demo_generating','demo_awaiting_approval','demo_approved','ready_for_outreach')), 0) AS qualified,
                    COALESCE(SUM(status = 'offer_sent'), 0) AS offer_sent,
                    COALESCE(SUM(status IN ('replied','negotiation')), 0) AS talking,
                    COALESCE(SUM(status = 'won'), 0) AS won,
                    COALESCE(SUM(created_at >= NOW() - INTERVAL 15 DAY), 0) AS new_recent,
                    COALESCE(SUM(relevance = 'high'), 0) AS barbershops,
                    COALESCE(SUM(relevance = 'medium'), 0) AS salons
                    , (SELECT COUNT(*) FROM businesses WHERE " . str_replace("relevance IN ('high','medium')", "relevance = 'low'", $where) . ") AS low_relevance
             FROM businesses WHERE $where"
        )->fetch();
        return array_map('intval', $row ?: []);
    }

    /** @return array<string,int> status => count (archived included) */
    public function countByStatus(): array
    {
        $out = array_fill_keys(array_keys(Status::LABELS), 0);
        foreach ($this->db->query('SELECT status, COUNT(*) c FROM businesses GROUP BY status') as $r) {
            $out[$r['status']] = (int) $r['c'];
        }
        return $out;
    }

    /** @param list<string> $statuses @return list<array<string,mixed>> */
    public function byStatuses(array $statuses, int $limitPerStatus = 200): array
    {
        if (!$statuses) {
            return [];
        }
        $in = implode(',', array_fill(0, count($statuses), '?'));
        $stmt = $this->db->prepare(
            "SELECT id, name, status, area_id, website_status, COALESCE(lead_score_override, lead_score) AS score, updated_at
             FROM businesses WHERE status IN ($in) ORDER BY updated_at DESC LIMIT " . (int) $limitPerStatus * count($statuses)
        );
        $stmt->execute($statuses);
        return $stmt->fetchAll();
    }
}
