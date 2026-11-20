<?php
// Fixes a bug where the Settings page showed "settings" instead of the outreach template: if it was saved
// that way, the default template from 0003 is put back. Templates the owner wrote himself are left alone.
$defaults = require __DIR__ . '/0003_default_settings.php';
return [
    'up' => [
        "DELETE FROM settings WHERE name = 'outreach_template' AND TRIM(value) IN ('settings', '')",
        $defaults['up'][0], // INSERT IGNORE: only re-creates what is missing
    ],
    'down' => [],
];
