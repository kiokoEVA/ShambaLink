<?php
// config/db.php : connection to the shamba_link database.

if (!defined('DB_HOST'))   define('DB_HOST', 'localhost');
if (!defined('DB_NAME'))   define('DB_NAME', 'shamba_link');
if (!defined('DB_USER'))   define('DB_USER', 'root');
if (!defined('DB_PASS'))   define('DB_PASS', '');          // XAMPP's root has no password by default
if (!defined('DB_SOCKET')) define('DB_SOCKET', '');        // leave empty unless your host tells you otherwise
if (!defined('APP_NAME'))  define('APP_NAME', 'ShambaLink');
if (!defined('POLL_MS'))   define('POLL_MS', 8000);

date_default_timezone_set('Africa/Nairobi');

/** One shared connection for the whole request. */
function db(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        $dsn = DB_SOCKET !== ''
            ? 'mysql:unix_socket=' . DB_SOCKET . ';dbname=' . DB_NAME . ';charset=utf8mb4'
            : 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4';
        try {
            $pdo = new PDO($dsn, DB_USER, DB_PASS, [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,   // errors stop the page instead of failing silently
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,                    // real prepared statements, safer against SQL injection
                PDO::MYSQL_ATTR_INIT_COMMAND => "SET time_zone = '+03:00'", // East Africa Time
            ]);
        } catch (PDOException $e) {
            error_log('DB connection failed: ' . $e->getMessage());
            http_response_code(500);
            exit('Could not connect to the database. Check the settings in config/db.php and that MySQL is running.');
        }
    }
    return $pdo;
}

if (!function_exists('fetch_one')) {
    /** First matching row, or null. */
    function fetch_one(string $sql, array $params = []): ?array {
        $st = db()->prepare($sql);
        $st->execute($params);
        $row = $st->fetch();
        return $row === false ? null : $row;
    }
}

if (!function_exists('fetch_all')) {
    /** All matching rows as a list. */
    function fetch_all(string $sql, array $params = []): array {
        $st = db()->prepare($sql);
        $st->execute($params);
        return $st->fetchAll();
    }
}