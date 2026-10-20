<?php
/**
 * WoodMind ERP — Password Hash Generator (CLI)
 *
 * Pemakaian:
 *   php database/make_hash.php <password>
 *
 * Salin output-nya ke kolom password_hash di seed.sql.
 */

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit('Hanya untuk CLI.');
}

if ($argc < 2) {
    fwrite(STDERR, "Pemakaian: php make_hash.php <password>\n");
    exit(1);
}

$password = $argv[1];
$hash     = password_hash($password, PASSWORD_DEFAULT);

echo $hash . PHP_EOL;
