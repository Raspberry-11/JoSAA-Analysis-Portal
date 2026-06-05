<?php
declare(strict_types=1);

require __DIR__ . '/../config/Database.php';

try {
    $pdo = Database::connect();
    $pdo->exec('TRUNCATE TABLE ai_queries');
    echo "<h1> AI Cache Cleared Successfully!</h1>";
    echo "<p>All previous bad queries have been wiped. You can now test the AI chat again and it will generate fresh answers.</p>";
    echo "<a href='/josaa-portal/ai_chat.php'>Return to AI Chat</a>";
} catch (Exception $e) {
    echo "<h1> Error clearing cache</h1>";
    echo "<p>" . htmlspecialchars($e->getMessage()) . "</p>";
}
