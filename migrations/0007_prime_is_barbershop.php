<?php
// Barbershops and hair salons are now shown on separate tabs; the imported Prime demo is a barbershop.
return [
    'up' => [
        "UPDATE businesses SET relevance = 'high', relevance_reason = 'Бръснарница (съществуващо демо)' WHERE phone_normalized = '+359896610004' AND relevance = 'medium'",
    ],
    'down' => [
        "UPDATE businesses SET relevance = 'medium', relevance_reason = NULL WHERE phone_normalized = '+359896610004' AND relevance_reason = 'Бръснарница (съществуващо демо)'",
    ],
];
