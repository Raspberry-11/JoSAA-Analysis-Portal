<?php
declare(strict_types=1);

require __DIR__ . '/../config/Database.php';

try {
    $pdo = Database::connect();
    
    echo "Deleting non-last round data...\n";

    $deleted = $pdo->exec("
        DELETE f1 FROM fact_allotment f1
        LEFT JOIN (
            SELECT year, MAX(round_no) as max_round
            FROM fact_allotment
            GROUP BY year
        ) f2 ON f1.year = f2.year AND f1.round_no = f2.max_round
        WHERE f2.max_round IS NULL
    ");

    echo "✔ Successfully deleted $deleted rows.\n";

} catch (Throwable $e) {
    fwrite(STDERR, "❌ Migration failed: " . $e->getMessage() . PHP_EOL);
    exit(1);
}
