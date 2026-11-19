<?php

declare(strict_types=1);

namespace BarberLead;

use BarberLead\Support\Text;
use PDO;

/** Data tasks referenced by migrations as ['task', '<name>']. */
final class Seeds
{
    private const TYPES = ['country', 'city', 'district', 'neighborhood'];

    /** Seeds the geography tree from data/areas_bg.php. Idempotent: rows with the same parent + slug are kept. */
    public static function seedAreas(PDO $db): void
    {
        $tree = require BL_ROOT . '/data/areas_bg.php';
        self::seedNodes($db, $tree, null, 0);
    }

    /** @param list<array<int,mixed>> $nodes */
    private static function seedNodes(PDO $db, array $nodes, ?int $parentId, int $depth): void
    {
        $find = $db->prepare('SELECT id FROM areas WHERE parent_id <=> ? AND slug = ?');
        $insert = $db->prepare(
            'INSERT INTO areas (parent_id, type, name_bg, name_en, slug, sort_order, geo_confidence) VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        foreach ($nodes as $i => $node) {
            $slug = Text::slug((string) $node[1]);
            $find->execute([$parentId, $slug]);
            $id = $find->fetchColumn();
            if ($id === false) {
                // Country, cities and Sofia's 24 official districts are certain; neighborhood mapping is approximate.
                $insert->execute([$parentId, self::TYPES[$depth], $node[0], $node[1], $slug, $i, $depth <= 2 ? 'verified' : 'approx']);
                $id = $db->lastInsertId();
            }
            self::seedNodes($db, $node[2] ?? [], (int) $id, $depth + 1);
        }
    }

    /** Removes seeded areas that no business references (leaves first). */
    public static function unseedAreas(PDO $db): void
    {
        for ($i = 0; $i < 5; $i++) {
            $db->exec(
                'DELETE a FROM areas a
                 LEFT JOIN areas c ON c.parent_id = a.id
                 LEFT JOIN businesses b ON b.area_id = a.id
                 WHERE c.id IS NULL AND b.id IS NULL'
            );
        }
    }

    /** Imports the existing live demo (facts from its config.php / index.html on testvane.org, 2026-11-16). */
    public static function importPrime(PDO $db): void
    {
        $exists = $db->prepare('SELECT id FROM businesses WHERE phone_normalized = ?');
        $exists->execute(['+359896610004']);
        if ($exists->fetchColumn() !== false) {
            return;
        }
        $area = $db->query(
            "SELECT n.id FROM areas n JOIN areas d ON d.id = n.parent_id
             WHERE n.type = 'neighborhood' AND n.slug = 'lyulin-7' AND d.slug = 'lyulin'"
        )->fetchColumn();
        $db->prepare(
            "INSERT INTO businesses (name, name_normalized, category, area_id, address, lat, lng, phone, phone_normalized,
                instagram_url, website_status, rating, reviews_count, working_hours, booking_method, status, notes)
             VALUES (?, ?, 'barbershop', ?, ?, ?, ?, ?, ?, ?, 'none', ?, ?, ?, ?, 'demo_approved', ?)"
        )->execute([
            'Prime Barbershop', 'prime', $area ?: null,
            'ж.к. Люлин 7, бл. 701, вх. А, 1324 София', 42.712732, 23.265956,
            '0896 610 004', '+359896610004',
            'https://www.instagram.com/primebarbershoplyulin/',
            5.0, 25,
            'Пн 10:00–18:00; Вт–Сб 10:00–19:30; Нд почивен',
            'телефон / Instagram',
            "Импортирано от съществуващото демо. Рейтингът (5,0 / 25 отзива) е от Google към 16.11.2026.\nОще липсват: имена и снимки на барберите, цени за брада и детско, имейл за известия.",
        ]);
        $id = (int) $db->lastInsertId();
        $db->prepare("INSERT INTO demos (business_id, url, status, version, notes) VALUES (?, ?, 'approved', 1, ?)")
            ->execute([$id, 'https://primebarbershop.testvane.org/', 'Ръчно направено демо с онлайн записване (PHP + SQLite).']);
        $db->prepare("INSERT INTO business_events (business_id, type, message, actor) VALUES (?, 'import', ?, 'auto')")
            ->execute([$id, 'Импортиран от съществуващото демо primebarbershop.testvane.org']);
    }

    public static function removePrime(PDO $db): void
    {
        $db->prepare('DELETE FROM businesses WHERE phone_normalized = ? AND name = ?')->execute(['+359896610004', 'Prime Barbershop']);
    }
}
