<?php

declare(strict_types=1);

namespace BarberLead\Repositories;

use PDO;

final class AuditLog
{
    public function __construct(private PDO $db)
    {
    }

    /** @param array<string,mixed> $data */
    public function record(?string $actor, string $action, ?string $entity = null, ?int $entityId = null, array $data = [], ?string $ip = null): void
    {
        $this->db->prepare(
            'INSERT INTO audit_log (actor, action, entity, entity_id, ip, data) VALUES (?, ?, ?, ?, ?, ?)'
        )->execute([$actor, $action, $entity, $entityId, $ip, $data ? json_encode($data, JSON_UNESCAPED_UNICODE) : null]);
    }

    /** @return list<array<string,mixed>> */
    public function recent(int $limit = 50): array
    {
        $stmt = $this->db->prepare('SELECT * FROM audit_log ORDER BY id DESC LIMIT ?');
        $stmt->bindValue(1, $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }
}
