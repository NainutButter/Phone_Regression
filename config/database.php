<?php
declare(strict_types=1);

use App\Core\Database;

require_once __DIR__ . '/../app/Core/Database.php';

$localConfig = dirname(__DIR__) . '/.env.local';
if (is_file($localConfig)) {
    $values = parse_ini_file($localConfig, false, INI_SCANNER_RAW);
    if ($values === false) {
        throw new RuntimeException('Unable to parse .env.local database configuration.');
    }
    foreach ($values as $key => $value) {
        if (str_starts_with($key, 'DB_') && getenv($key) === false) {
            putenv($key . '=' . $value);
        }
    }
}

return Database::connection();
