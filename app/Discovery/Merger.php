<?php

declare(strict_types=1);

namespace BarberLead\Discovery;

use BarberLead\Repositories\EventRepository;
use PDO;

/** Merges two business records after the owner confirmed they are the same place. */
final class Merger
{
    private const FILLABLE = ['brand', 'area_id', 'address', 'lat', 'lng', 'phone', 'phone_normalized', 'email', 'website_url', 'website_domain',
        'instagram_url', 'facebook_url', 'tiktok_url', 'booking_method', 'working_hours', 'rating', 'reviews_count', 'lead_score_override'];

    public function __construct(private PDO $db, private EventRepository $events)
    {
    }

    /** Keeps $keepId; moves everything from $removeId into it and archives $removeId. */
    public function merge(int $keepId, int $removeId, string $actor): void
    {
        if ($keepId === $removeId) {
            throw new \InvalidArgumentException('Cannot merge a business into itself');
        }
        $this->db->beginTransaction();
        try {
            $get = $this->db->prepare('SELECT * FROM businesses WHERE id = ? FOR UPDATE');
            $get->execute([$keepId]);
            $keep = $get->fetch();
            $get->execute([$removeId]);
            $remove = $get->fetch();
            if ($keep === false || $remove === false) {
                throw new \InvalidArgumentException('Business not found');
            }
            $set = [];
            $params = [];
            foreach (self::FILLABLE as $f) {
                if (($keep[$f] === null || $keep[$f] === '') && $remove[$f] !== null && $remove[$f] !== '') {
                    $set[] = "$f = ?";
                    $params[] = $remove[$f];
                }
            }
            if ($remove['notes']) {
                $set[] = 'notes = ?';
                $params[] = trim(($keep['notes'] ? $keep['notes'] . "\n\n" : '') . '[от слят запис] ' . $remove['notes']);
            }
            if ($set) {
                $params[] = $keepId;
                $this->db->prepare('UPDATE businesses SET ' . implode(', ', $set) . ' WHERE id = ?')->execute($params);
            }
            foreach (['business_sources', 'business_events', 'offers', 'demos'] as $table) {
                $this->db->prepare("UPDATE $table SET business_id = ? WHERE business_id = ?")->execute([$keepId, $removeId]);
            }
            $this->db->prepare("UPDATE businesses SET status = 'archived', merged_into_id = ? WHERE id = ?")->execute([$keepId, $removeId]);
            $this->db->prepare("UPDATE duplicate_candidates SET status = 'merged', resolved_at = NOW()
                                WHERE status = 'open' AND (business_id IN (?, ?) AND other_id IN (?, ?))")
                ->execute([$keepId, $removeId, $keepId, $removeId]);
            $this->db->prepare("UPDATE duplicate_candidates SET status = 'dismissed', resolved_at = NOW()
                                WHERE status = 'open' AND (business_id = ? OR other_id = ?)")->execute([$removeId, $removeId]);
            $this->events->add($keepId, 'merge', "Сляха се записите: „{$remove['name']}“ (#$removeId) влезе тук", $actor);
            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    public function dismiss(int $candidateId): bool
    {
        $stmt = $this->db->prepare("UPDATE duplicate_candidates SET status = 'dismissed', resolved_at = NOW() WHERE id = ? AND status = 'open'");
        $stmt->execute([$candidateId]);
        return $stmt->rowCount() === 1;
    }
}
