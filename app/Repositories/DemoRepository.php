<?php

declare(strict_types=1);

namespace BarberLead\Repositories;

use PDO;

final class DemoRepository
{
    public const STATUSES = [
        'queued' => 'На опашка',
        'generating' => 'Генерира се',
        'deployed' => 'Качено',
        'awaiting_approval' => 'Чака одобрение',
        'approved' => 'Одобрено',
        'rejected' => 'Отхвърлено',
        'archived' => 'Архив',
    ];

    public function __construct(private PDO $db, private EventRepository $events)
    {
    }

    /** @return list<array<string,mixed>> */
    public function forBusiness(int $businessId): array
    {
        $stmt = $this->db->prepare('SELECT * FROM demos WHERE business_id = ? ORDER BY id DESC');
        $stmt->execute([$businessId]);
        return $stmt->fetchAll();
    }

    public function latestUrl(int $businessId): ?string
    {
        $stmt = $this->db->prepare("SELECT url FROM demos WHERE business_id = ? AND status <> 'rejected' ORDER BY id DESC LIMIT 1");
        $stmt->execute([$businessId]);
        $url = $stmt->fetchColumn();
        return $url === false ? null : (string) $url;
    }

    /** Manually linked demo (e.g. one built by hand). Generated demos come through the Phase 5 queue. */
    public function addManual(int $businessId, string $url, ?string $notes, string $actor): int
    {
        $this->db->prepare("INSERT INTO demos (business_id, url, status, notes) VALUES (?, ?, 'approved', ?)")
            ->execute([$businessId, $url, $notes]);
        $id = (int) $this->db->lastInsertId();
        $this->events->add($businessId, 'demo', 'Добавено демо: ' . $url, $actor, ['demo_id' => $id]);
        return $id;
    }
}
