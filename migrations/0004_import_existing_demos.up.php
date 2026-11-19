<?php
// Imports the existing live demo (Prime Barbershop) as a CRM record. Idempotent by phone number.
// Facts copied from the demo's config.php / index.html on testvane.org (2026-11-16).
return function (PDO $db): void {
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
        'Prime Barbershop', 'prime barbershop', $area ?: null,
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
    $db->prepare("INSERT INTO business_events (business_id, type, message, actor) VALUES (?, 'import', ?, 'system')")
        ->execute([$id, 'Импортиран от съществуващото демо primebarbershop.testvane.org']);
};
