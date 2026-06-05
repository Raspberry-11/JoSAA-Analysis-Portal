<?php
declare(strict_types=1);

/**
 * JOSAA CSV Importer
 *
 * Usage:  php scripts/import_csv.php [path/to/file.csv]
 *
 * - Filters to IITs only
 * - Normalizes preparatory ranks ("1234P") and flags them
 * - Caches dim lookups in memory to avoid round-trips
 * - Batches fact inserts (1000 rows per INSERT) inside one transaction
 */

require_once __DIR__ . '/../config/Database.php';

final class JosaaImporter
{
    private PDO $pdo;
    private array $iitCache    = [];
    private array $branchCache = [];
    private array $quotaCache  = [];
    private array $seatCache   = [];
    private array $genderCache = [];

    private const BATCH_SIZE = 1000;

    private const NEW_AGE_KEYWORDS = [
        'Artificial Intelligence', 'Data Science',
        'Mathematics and Computing', 'Mathematics & Computing',
        'AI and Data',
    ];
    private const CORE_KEYWORDS = [
        'Mechanical', 'Civil', 'Chemical', 'Metallurgical',
    ];
    private const CSE_KEYWORDS = ['Computer Science'];

    private const OLD_IITS = [
        'Indian Institute of Technology Bombay',
        'Indian Institute of Technology Delhi',
        'Indian Institute of Technology Kanpur',
        'Indian Institute of Technology Kharagpur',
        'Indian Institute of Technology Madras',
        'Indian Institute of Technology Roorkee',
        'Indian Institute of Technology (BHU) Varanasi',
        'Indian Institute of Technology Guwahati',
    ];

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    public function import(string $csvPath): void
    {
        if (!is_readable($csvPath)) {
            throw new RuntimeException("CSV not readable: {$csvPath}");
        }

        $fh = fopen($csvPath, 'r');
        if (!$fh) {
            throw new RuntimeException("Could not open CSV: {$csvPath}");
        }

        $header = fgetcsv($fh);
        if (!$header) {
            throw new RuntimeException("CSV appears to be empty");
        }

        $colIndex = array_flip(array_map(
            static fn($h) => strtolower(trim($h)),
            $header
        ));

        foreach (['iit','branch','quota','seat_type','gender','or','cr','year','round'] as $required) {
            if (!isset($colIndex[$required])) {
                throw new RuntimeException("Missing required column: {$required}");
            }
        }

        $this->pdo->beginTransaction();

        try {
            $batch = [];
            $rowCount = 0;
            $skipped  = 0;

            while (($row = fgetcsv($fh)) !== false) {
                $iitName = trim($row[$colIndex['iit']]);

                // Keep IITs only
                if (stripos($iitName, 'Indian Institute of Technology') === false) {
                    $skipped++;
                    continue;
                }

                [$openRank, $openPrep] = $this->normalizeRank($row[$colIndex['or']]);
                [$closeRank, $closePrep] = $this->normalizeRank($row[$colIndex['cr']]);

                if ($openRank === 0 || $closeRank === 0) {
                    $skipped++;
                    continue;
                }

                $batch[] = [
                    'iit_id'         => $this->getOrInsertIit($iitName),
                    'branch_id'      => $this->getOrInsertBranch(trim($row[$colIndex['branch']])),
                    'quota_id'       => $this->getOrInsertDim('dim_quota', 'quota_code', 'quota_id',
                                        trim($row[$colIndex['quota']]), $this->quotaCache),
                    'seat_type_id'   => $this->getOrInsertDim('dim_seat_type', 'seat_type_code', 'seat_type_id',
                                        trim($row[$colIndex['seat_type']]), $this->seatCache),
                    'gender_id'      => $this->getOrInsertDim('dim_gender', 'gender_code', 'gender_id',
                                        trim($row[$colIndex['gender']]), $this->genderCache),
                    'year'           => (int) $row[$colIndex['year']],
                    'round_no'       => (int) $row[$colIndex['round']],
                    'opening_rank'   => $openRank,
                    'closing_rank'   => $closeRank,
                    'is_preparatory' => ($openPrep || $closePrep) ? 1 : 0,
                ];

                if (count($batch) >= self::BATCH_SIZE) {
                    $this->flushBatch($batch);
                    $rowCount += count($batch);
                    $batch = [];
                    echo "Inserted {$rowCount} rows...\n";
                }
            }

            if (!empty($batch)) {
                $this->flushBatch($batch);
                $rowCount += count($batch);
            }

            $this->pdo->commit();
            fclose($fh);

            // Delete non-last round data as requested
            $this->pdo->exec("
                DELETE f1 FROM fact_allotment f1
                LEFT JOIN (
                    SELECT year, MAX(round_no) as max_round
                    FROM fact_allotment
                    GROUP BY year
                ) f2 ON f1.year = f2.year AND f1.round_no = f2.max_round
                WHERE f2.max_round IS NULL
            ");

            echo "\n✔ Import complete.\n";
            echo "  Rows inserted: {$rowCount}\n";
            echo "  Rows skipped:  {$skipped}\n";
        } catch (Throwable $e) {
            $this->pdo->rollBack();
            fclose($fh);
            throw $e;
        }
    }

    /**
     * Returns [int $rank, bool $isPreparatory].
     * A rank suffixed with "P" (e.g. "1234P") is a preparatory rank.
     */
    private function normalizeRank(string $raw): array
    {
        $raw = trim($raw);
        $prep = (bool) preg_match('/P/i', $raw);
        $rank = (int) preg_replace('/[^0-9]/', '', $raw);
        return [$rank, $prep];
    }

    private function getOrInsertIit(string $name): int
    {
        if (isset($this->iitCache[$name])) {
            return $this->iitCache[$name];
        }

        $stmt = $this->pdo->prepare("SELECT iit_id FROM dim_iit WHERE iit_name = ?");
        $stmt->execute([$name]);
        $id = $stmt->fetchColumn();

        if (!$id) {
            $generation = in_array($name, self::OLD_IITS, true) ? 'old' : 'new';
            $shortCode  = $this->deriveShortCode($name);
            $ins = $this->pdo->prepare(
                "INSERT INTO dim_iit (iit_name, short_code, generation) VALUES (?, ?, ?)"
            );
            $ins->execute([$name, $shortCode, $generation]);
            $id = (int) $this->pdo->lastInsertId();
        }

        return $this->iitCache[$name] = (int) $id;
    }

    private function deriveShortCode(string $fullName): string
    {
        $clean = preg_replace('/Indian Institute of Technology/i', 'IIT', $fullName);
        return trim(preg_replace('/\s+/', ' ', $clean ?? $fullName));
    }

    private function getOrInsertBranch(string $name): int
    {
        if (isset($this->branchCache[$name])) {
            return $this->branchCache[$name];
        }

        $stmt = $this->pdo->prepare("SELECT branch_id FROM dim_branch WHERE branch_name = ?");
        $stmt->execute([$name]);
        $id = $stmt->fetchColumn();

        if (!$id) {
            $category = $this->classifyBranch($name);
            $ins = $this->pdo->prepare(
                "INSERT INTO dim_branch (branch_name, category) VALUES (?, ?)"
            );
            $ins->execute([$name, $category]);
            $id = (int) $this->pdo->lastInsertId();
        }

        return $this->branchCache[$name] = (int) $id;
    }

    private function classifyBranch(string $name): string
    {
        foreach (self::CSE_KEYWORDS as $kw) {
            if (stripos($name, $kw) !== false) return 'cse_family';
        }
        foreach (self::NEW_AGE_KEYWORDS as $kw) {
            if (stripos($name, $kw) !== false) return 'new_age';
        }
        foreach (self::CORE_KEYWORDS as $kw) {
            if (stripos($name, $kw) !== false) return 'core';
        }
        return 'other';
    }

    private function getOrInsertDim(
        string $table,
        string $col,
        string $idCol,
        string $val,
        array &$cache
    ): int {
        if (isset($cache[$val])) {
            return $cache[$val];
        }

        $stmt = $this->pdo->prepare("SELECT {$idCol} FROM {$table} WHERE {$col} = ?");
        $stmt->execute([$val]);
        $id = $stmt->fetchColumn();

        if (!$id) {
            $ins = $this->pdo->prepare("INSERT INTO {$table} ({$col}) VALUES (?)");
            $ins->execute([$val]);
            $id = (int) $this->pdo->lastInsertId();
        }

        return $cache[$val] = (int) $id;
    }

    private function flushBatch(array $batch): void
    {
        $placeholders = implode(',', array_fill(0, count($batch), '(?,?,?,?,?,?,?,?,?,?)'));
        $sql = "INSERT INTO fact_allotment
                (iit_id, branch_id, quota_id, seat_type_id, gender_id,
                 year, round_no, opening_rank, closing_rank, is_preparatory)
                VALUES {$placeholders}";

        $flat = [];
        foreach ($batch as $r) {
            array_push($flat,
                $r['iit_id'], $r['branch_id'], $r['quota_id'], $r['seat_type_id'],
                $r['gender_id'], $r['year'], $r['round_no'],
                $r['opening_rank'], $r['closing_rank'], $r['is_preparatory']
            );
        }

        $this->pdo->prepare($sql)->execute($flat);
    }
}

// -------- CLI Bootstrap --------
// Only auto-run when called directly from the command line.
// When included by public/import.php (browser version), this block is skipped.
if (PHP_SAPI === 'cli' && isset($argv) && realpath($argv[0]) === __FILE__) {
    $csvPath = $argv[1] ?? __DIR__ . '/../data/josaa_2016_2022.csv';

    try {
        $pdo = Database::connect();
        (new JosaaImporter($pdo))->import($csvPath);
    } catch (Throwable $e) {
        fwrite(STDERR, "❌ Import failed: " . $e->getMessage() . PHP_EOL);
        exit(1);
    }
}
