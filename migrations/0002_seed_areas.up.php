<?php
// Seeds the geography tree. Idempotent: existing rows (same parent + slug) are kept.
use BarberLead\Support\Text;

return function (PDO $db): void {
    $tree = require __DIR__ . '/../data/areas_bg.php';
    $types = ['country', 'city', 'district', 'neighborhood'];

    $find = $db->prepare('SELECT id FROM areas WHERE parent_id <=> ? AND slug = ?');
    $insert = $db->prepare(
        'INSERT INTO areas (parent_id, type, name_bg, name_en, slug, sort_order, geo_confidence)
         VALUES (?, ?, ?, ?, ?, ?, ?)'
    );

    $walk = function (array $nodes, ?int $parentId, int $depth) use (&$walk, $find, $insert, $types, $db): void {
        foreach ($nodes as $i => $node) {
            [$bg, $en] = $node;
            $children = $node[2] ?? [];
            $type = $types[$depth];
            $slug = Text::slug($en);
            $find->execute([$parentId, $slug]);
            $id = $find->fetchColumn();
            if ($id === false) {
                // Country and city names are certain; district list of Sofia is official.
                $confidence = $depth <= 2 ? 'verified' : 'approx';
                $insert->execute([$parentId, $type, $bg, $en, $slug, $i, $confidence]);
                $id = (int) $db->lastInsertId();
            }
            $walk($children, (int) $id, $depth + 1);
        }
    };
    $walk($tree, null, 0);
};
