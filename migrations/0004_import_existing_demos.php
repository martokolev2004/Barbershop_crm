<?php
// Imports the existing Prime Barbershop demo as a CRM record. Idempotent by phone.
// Data-only migration: SQL strings or ['task', name] steps run by BarberLead\Migrator.
return [
    'up' => [
        ['task', 'import_prime'],
    ],
    'down' => [
        ['task', 'remove_prime'],
    ],
];
