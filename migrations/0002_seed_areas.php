<?php
// Geography seed (data/areas_bg.php). Idempotent; rollback keeps areas used by businesses.
// Data-only migration: SQL strings or ['task', name] steps run by BarberLead\Migrator.
return [
    'up' => [
        ['task', 'seed_areas'],
    ],
    'down' => [
        ['task', 'unseed_areas'],
    ],
];
