<?php
// Owner's request (2026-11-20): scan every district of Sofia automatically every 15 days.
// District scans cover all their neighborhoods; first runs are staggered 5 minutes apart (Лозенец first).
return [
    'up' => [
        "INSERT INTO scan_schedules (area_id, interval_days, next_run_at, enabled)
         SELECT d.id, 15, NOW() + INTERVAL (d.sort_order * 5) MINUTE, 1
         FROM areas d JOIN areas c ON c.id = d.parent_id
         WHERE c.type = 'city' AND c.slug = 'sofia' AND d.type = 'district'
         ON DUPLICATE KEY UPDATE interval_days = 15, enabled = 1",
    ],
    'down' => [
        "DELETE s FROM scan_schedules s JOIN areas d ON d.id = s.area_id JOIN areas c ON c.id = d.parent_id
         WHERE c.type = 'city' AND c.slug = 'sofia' AND d.type = 'district'",
    ],
];
