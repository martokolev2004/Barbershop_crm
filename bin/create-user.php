<?php
/** Local/dev helper: php bin/create-user.php <username>   (password read from STDIN, never from argv) */
declare(strict_types=1);

require __DIR__ . '/common.php';

use BarberLead\Auth;
use BarberLead\Database;
use BarberLead\Http\Session;

$username = $argv[1] ?? '';
if ($username === '') {
    fwrite(STDERR, "Usage: php bin/create-user.php <username>  (then type the password)\n");
    exit(1);
}
$password = trim((string) fgets(STDIN));
if ($err = Auth::validatePassword($password)) {
    fwrite(STDERR, $err . PHP_EOL);
    exit(1);
}
$id = (new Auth(Database::connect(bl_config()['db']), new Session()))->createUser($username, $password);
echo "User #$id created\n";
