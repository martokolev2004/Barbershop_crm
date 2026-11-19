<?php
/**
 * Generates the one-time installer code. Prints the code once and stores only its hash in
 * config/setup_token.hash (git-ignored). Deploy that hash file together with the app.
 */
declare(strict_types=1);

require __DIR__ . '/common.php';

$token = implode('-', str_split(bin2hex(random_bytes(6)), 4));
file_put_contents(BL_ROOT . '/config/setup_token.hash', password_hash($token, PASSWORD_DEFAULT) . "\n");
chmod(BL_ROOT . '/config/setup_token.hash', 0600);
echo "Installer code: $token\n";
