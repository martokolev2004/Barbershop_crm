<?php
/**
 * Generates the one-time installer code. Prints the code once and stores only its hash in
 * config/setup_token.php (git-ignored). Deploy that file together with the app.
 */
declare(strict_types=1);

require __DIR__ . '/common.php';

$token = implode('-', str_split(bin2hex(random_bytes(6)), 4));
$file = BL_ROOT . '/config/setup_token.php';
file_put_contents($file, "<?php\n// One-time installer code hash. Deleted by the installer after setup.\nreturn " . var_export(password_hash($token, PASSWORD_DEFAULT), true) . ";\n");
chmod($file, 0600);
echo "Installer code: $token\n";
