<?php declare(strict_types=1);
/**
 * Standalone php script that drops the previous table of sessions in
 * background.
 *
 * Designed to be spawned via shell `&` from the config form, because dropping a
 * big table may be long on a slow disk.
 *
 * Self-contained: no Omeka bootstrap, no Composer autoload. Reads the file
 * config/database.ini directly and uses PDO. The name of the table is fixed, so
 * no other table can be dropped.
 *
 * Usage: php drop-table.php --omeka-path /path/to/omeka
 *
 * The path of Omeka is required when the module is a symbolic link, because php
 * resolves the links in __DIR__.
 *
 * Exit code 0 always (background script: non-zero would not surface).
 *
 * @see modules/Common/data/scripts/add-database-index.php
 */
openlog('omeka-noemptysession-drop-table', LOG_PID | LOG_ODELAY, LOG_USER);

$fail = function (string $msg): void {
    syslog(LOG_ERR, $msg);
    closelog();
    exit(0);
};

$opts = getopt('', ['omeka-path:']);
$omekaPath = empty($opts['omeka-path'])
    ? realpath(__DIR__ . '/../../../..')
    : realpath($opts['omeka-path']);
if (!$omekaPath) {
    $fail('Cannot resolve Omeka root.');
}
$dbIni = $omekaPath . '/config/database.ini';
if (!is_readable($dbIni)) {
    $fail("Cannot read database config: $dbIni");
}

$db = parse_ini_file($dbIni);
if (!$db) {
    $fail("Cannot parse $dbIni");
}

if (!empty($db['unix_socket'])) {
    $dsn = sprintf('mysql:unix_socket=%s;dbname=%s;charset=utf8mb4', $db['unix_socket'], $db['dbname'] ?? '');
} else {
    $dsn = sprintf('mysql:host=%s;dbname=%s;charset=utf8mb4', $db['host'] ?? 'localhost', $db['dbname'] ?? '');
    if (!empty($db['port'])) {
        $dsn .= ';port=' . $db['port'];
    }
}

try {
    $pdo = new PDO($dsn, $db['user'] ?? '', $db['password'] ?? '', [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    ]);
    $pdo->exec('DROP TABLE IF EXISTS `session_noemptysession_old`');
} catch (\Throwable $e) {
    $fail('Drop of the previous table of sessions failed: ' . $e->getMessage());
}

syslog(LOG_INFO, 'The previous table of sessions was dropped.');
closelog();
exit(0);
