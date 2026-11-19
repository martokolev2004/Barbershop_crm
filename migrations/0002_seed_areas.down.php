<?php
// Removes seeded areas that no business references. Areas in use are kept (businesses would lose their location).
return function (PDO $db): void {
    for ($i = 0; $i < 5; $i++) { // leaves first, then their parents
        $db->exec(
            'DELETE a FROM areas a
             LEFT JOIN areas c ON c.parent_id = a.id
             LEFT JOIN businesses b ON b.area_id = a.id
             WHERE c.id IS NULL AND b.id IS NULL'
        );
    }
};
