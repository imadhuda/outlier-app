<?php
/* Database access. utf8mb4 is forced on the connection AND on every table,
   because this account's MariaDB default is latin1 and would mangle Hinglish. */

function outlier_config() {
    static $cfg = null;
    if ($cfg === null) {
        $path = __DIR__ . '/../config.php';
        if (!is_file($path)) {
            throw new RuntimeException('config.php is missing. Copy config.sample.php to config.php and fill it in.');
        }
        $cfg = require $path;
    }
    return $cfg;
}

function db() {
    static $pdo = null;
    if ($pdo === null) {
        $c = outlier_config()['db'];
        $pdo = new PDO(
            "mysql:host={$c['host']};dbname={$c['name']};charset=utf8mb4",
            $c['user'], $c['pass'],
            [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
                PDO::ATTR_TIMEOUT            => 10,
            ]
        );
        $pdo->exec("SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci");
    }
    return $pdo;
}

function q($sql, $params = []) { $s = db()->prepare($sql); $s->execute($params); return $s; }
function one($sql, $params = []) { $r = q($sql, $params)->fetch(); return $r === false ? null : $r; }
function all($sql, $params = []) { return q($sql, $params)->fetchAll(); }
function lastId() { return (int)db()->lastInsertId(); }
