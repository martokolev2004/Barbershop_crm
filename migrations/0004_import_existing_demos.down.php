<?php
return function (PDO $db): void {
    $db->prepare('DELETE FROM businesses WHERE phone_normalized = ? AND name = ?')
        ->execute(['+359896610004', 'Prime Barbershop']);
};
