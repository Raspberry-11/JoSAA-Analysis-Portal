<?php
declare(strict_types=1);

/**
 * Browser-based CSV importer for XAMPP users.
 *
 * Visit:  http://localhost/josaa-portal/public/import.php
 *
 * Runs the same ETL logic as scripts/import_csv.php, but with HTML output and
 * flush()-based streaming so you see progress live in the browser.
 */

// Allow long imports; XAMPP defaults to 30s which is too short for 80K+ rows
set_time_limit(0);
ini_set('memory_limit', '512M');

require_once __DIR__ . '/../config/Database.php';
require_once __DIR__ . '/../scripts/import_csv.php'; // loads the JosaaImporter class

// The required script above also runs its own bootstrap at the bottom, which
// we want to suppress here. We'll re-run the import manually with HTML output.
// (The bootstrap in import_csv.php only executes when PHP's SAPI is 'cli',
// so we need to guard it.)
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>JOSAA Importer</title>
<style>
  body { font-family: 'Courier New', monospace; background: #1e1e1e; color: #d4d4d4; padding: 24px; line-height: 1.5; }
  h1 { color: #4ec9b0; }
  .ok { color: #4ec9b0; font-weight: bold; }
  .err { color: #f48771; font-weight: bold; }
  .warn { color: #dcdcaa; }
  .muted { color: #858585; }
  pre { background: #252526; padding: 16px; border-radius: 6px; border: 1px solid #3e3e42; max-height: 500px; overflow-y: auto; }
  .actions { margin-top: 24px; }
  a { color: #4ec9b0; }
  .btn { display: inline-block; background: #4ec9b0; color: #1e1e1e; padding: 10px 20px; border-radius: 6px; text-decoration: none; font-weight: bold; margin-right: 8px; }
  .btn:hover { background: #3fb59d; }
</style>
</head>
<body>
<h1> JOSAA CSV Importer</h1>
<p class="muted">Streaming import progress below. This may take 1–3 minutes for a full dataset.</p>
<pre id="output">
<?php
// Force output to flush immediately so user sees live progress
@ob_end_flush();
@ob_implicit_flush(true);

$csvPath = __DIR__ . '/../data/josaa_2016_2022.csv';

try {
    if (!file_exists($csvPath)) {
        throw new RuntimeException(
            "CSV not found at: {$csvPath}\n" .
            "Place your JOSAA CSV file at:\n" .
            "  josaa-portal/data/josaa_2016_2022.csv"
        );
    }

    echo "✓ CSV found: " . basename($csvPath) . " (" . number_format(filesize($csvPath) / 1024, 1) . " KB)\n";
    echo "✓ Connecting to MySQL...\n";
    @flush();

    $pdo = Database::connect();

    echo "✓ Connected to database.\n";
    echo "✓ Starting import...\n\n";
    @flush();

    $importer = new JosaaImporter($pdo);
    $importer->import($csvPath);

    echo "\n</pre>";
    echo '<p class="ok"> Import succeeded!</p>';
    echo '<div class="actions">';
    echo '<a class="btn" href="index.php">Open Dashboard →</a>';
    echo '<a href="/phpmyadmin" target="_blank">Open phpMyAdmin</a>';
    echo '</div>';

} catch (Throwable $e) {
    echo "\n</pre>";
    echo '<p class="err"> Import failed: ' . htmlspecialchars($e->getMessage()) . '</p>';
    echo '<p class="muted">Stack trace:</p>';
    echo '<pre>' . htmlspecialchars($e->getTraceAsString()) . '</pre>';
    echo '<div class="actions">';
    echo '<a class="btn" href="import.php">Retry</a>';
    echo '<a href="/phpmyadmin" target="_blank">Check phpMyAdmin</a>';
    echo '</div>';
}
?>
</body>
</html>
