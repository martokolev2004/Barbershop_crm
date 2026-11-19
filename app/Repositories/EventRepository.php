<?php

declare(strict_types=1);

namespace BarberLead\Repositories;

use PDO;

final class EventRepository
{
    public function __construct(private PDO $db)
    {
    }

    /** @param array<string,mixed> $data */
    public function add(int $businessId, string $type, string $message, ?string $actor, array $data = []): void
    {
        $this->db->prepare('INSERT INTO business_events (business_id, type, message, data, actor) VALUES (?, ?, ?, ?, ?)')
            ->execute([$businessId, $type, mb_substr($message, 0, 500), $data ? json_encode($data, JSON_UNESCAPED_UNICODE) : null, $actor]);
    }

    /** @return list<array<string,mixed>> */
    public function forBusiness(int $businessId, int $limit = 100): array
    {
        $stmt = $this->db->prepare('SELECT * FROM business_events WHERE business_id = ? ORDER BY id DESC LIMIT ?');
        $stmt->bindValue(1, $businessId, PDO::PARAM_INT);
        $stmt->bindValue(2, $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    /** @return list<array<string,mixed>> */
    public function recent(int $limit = 15): array
    {
        $stmt = $this->db->prepare(
            'SELECT e.*, b.name AS business_name FROM business_events e JOIN businesses b ON b.id = e.business_id
             ORDER BY e.id DESC LIMIT ?'
        );
        $stmt->bindValue(1, $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }
}
