<?php
// api/db.php
$config = require __DIR__ . '/config.php';

$db_host = $config['db_host'] ?? 'localhost';
$db_name = trim((string)($config['db_name'] ?? ''));
$db_user = trim((string)($config['db_user'] ?? ''));
$db_pass = (string)($config['db_pass'] ?? '');

if ($db_name === '' || $db_user === '') {
    throw new RuntimeException('Database configuration is incomplete. Create api/config.local.php or set DB_HOST, DB_NAME, DB_USER, and DB_PASS.');
}

$pdo = new PDO("mysql:host={$db_host};dbname={$db_name};charset=utf8mb4", $db_user, $db_pass, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES => false
]);

function sendResponse($data, $statusCode = 200) {
    http_response_code($statusCode);
    echo json_encode($data);
    exit;
}
