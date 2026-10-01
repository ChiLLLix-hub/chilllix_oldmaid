<?php
// setup/install.php
header('Content-Type: text/html; charset=utf-8');

try {
    require_once __DIR__ . '/../api/db.php';
    
    $sql = file_get_contents(__DIR__ . '/sqldb.sql');
    $pdo->exec($sql);
    
    echo "<h1>✅ Old Maid Database Setup Complete!</h1>";
    echo "<p>Tables <code>oldmaid_rooms</code>, <code>oldmaid_players</code>, and <code>oldmaid_cards</code> were successfully created or verified.</p>";
    echo "<p><a href='../index.html'>Go to Game Lobby &rarr;</a></p>";
} catch (Throwable $e) {
    http_response_code(500);
    echo "<h1>❌ Database Setup Error</h1>";
    echo "<pre style='color:red;'>" . htmlspecialchars($e->getMessage()) . "</pre>";
}
