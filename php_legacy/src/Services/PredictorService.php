<?php
declare(strict_types=1);

/**
 * PredictorService
 * ================
 *
 * Two prediction modes:
 *
 *   A. predictByRank(rank, seatType, gender)
 *      → ranked list of (IIT, branch) combos the user can realistically get,
 *        bucketed into Very High / High / Moderate / Low chance.
 *
 *   B. predictForPreference(rank, iit, branch, seatType, gender)
 *      → probability assessment for that exact combo,
 *        plus suggestions: same branch at similar-tier IITs, and
 *                          same IIT with similar branches at the rank level.
 *
 * The math:
 *   For each (iit, branch, seat_type, gender) historical group, we compute:
 *     - mean closing rank across years
 *     - std-dev of closing rank
 *     - latest year's closing rank (weighted heavier)
 *
 *   A "weighted threshold" = 0.6 × latest_close + 0.4 × mean_close
 *
 *   Chance bucket based on (weighted_threshold - user_rank) / std_dev:
 *     z >= 1.5  → Very High  (user well below threshold)
 *     z >= 0.5  → High
 *     z >= -0.5 → Moderate
 *     z >= -1.5 → Low
 *     else      → Very Low / Unlikely
 */
final class PredictorService
{
    public function __construct(private PDO $pdo) {}

    // =========================================================================
    //  MODE A: predict by rank only
    // =========================================================================
    public function predictByRank(
        int $userRank,
        string $seatType = 'OPEN',
        string $gender = 'Gender-Neutral',
        ?int $minYear = null
    ): array {
        $minYear = $minYear ?? 2019;  // default: use last 4 years of data

        $sql = "
            SELECT
              i.iit_id,
              i.iit_name,
              i.short_code AS iit_short,
              i.generation,
              b.branch_id,
              b.branch_name,
              b.category,
              ROUND(AVG(f.closing_rank))            AS avg_close,
              ROUND(MIN(f.closing_rank))            AS min_close,
              ROUND(MAX(f.closing_rank))            AS max_close,
              ROUND(STDDEV_POP(f.closing_rank))     AS std_close,
              MAX(f.year)                            AS latest_year,
              SUBSTRING_INDEX(GROUP_CONCAT(f.closing_rank ORDER BY f.year DESC), ',', 1) AS latest_close,
              COUNT(*) AS samples
            FROM fact_allotment f
            JOIN dim_iit i        ON f.iit_id = i.iit_id
            JOIN dim_branch b     ON f.branch_id = b.branch_id
            JOIN dim_seat_type s  ON f.seat_type_id = s.seat_type_id
            JOIN dim_gender g     ON f.gender_id = g.gender_id
            WHERE s.seat_type_code = :seat
              AND g.gender_code = :gender
              AND f.is_preparatory = 0
              AND f.year >= :min_year
              AND f.round_no = (
                  SELECT MAX(round_no) FROM fact_allotment x WHERE x.year = f.year
              )
            GROUP BY i.iit_id, b.branch_id
            HAVING samples >= 2
            ORDER BY avg_close ASC
        ";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            ':seat'     => $seatType,
            ':gender'   => $gender,
            ':min_year' => $minYear,
        ]);

        $rows = $stmt->fetchAll();

        // Annotate each row with chance bucket + score
        $annotated = [];
        foreach ($rows as $row) {
            $latest = (int) $row['latest_close'];
            $mean   = (int) $row['avg_close'];
            $std    = max((int) $row['std_close'], 50); // floor std at 50 to avoid div issues

            // Weighted threshold: trust recent data more
            $threshold = (int) round(0.6 * $latest + 0.4 * $mean);

            $z = ($threshold - $userRank) / $std;

            [$bucket, $bucketScore] = $this->bucketize($z);

            $annotated[] = array_merge($row, [
                'threshold'    => $threshold,
                'z_score'      => round($z, 2),
                'chance'       => $bucket,
                'chance_score' => $bucketScore, // for sorting
                'reasoning'    => $this->reasoningFor($userRank, $threshold, $std, $bucket),
            ]);
        }

        // Sort: most relevant first (closest threshold to user rank, meaning target schools first)
        usort($annotated, function ($a, $b) use ($userRank) {
            $diffA = abs($a['threshold'] - $userRank);
            $diffB = abs($b['threshold'] - $userRank);
            
            if ($diffA === $diffB) {
                return $a['avg_close'] <=> $b['avg_close'];
            }
            
            return $diffA <=> $diffB;
        });

        // Filter out "very low" by default — only show realistic options
        $filtered = array_filter($annotated, fn($r) => $r['chance_score'] > 1);

        return [
            'user_rank'   => $userRank,
            'seat_type'   => $seatType,
            'gender'      => $gender,
            'total_options' => count($filtered),
            'options'     => array_values($filtered),
        ];
    }

    // =========================================================================
    //  MODE B: predict for specific preference + suggest alternatives
    // =========================================================================
    public function predictForPreference(
        int $userRank,
        int $iitId,
        int $branchId,
        string $seatType = 'OPEN',
        string $gender = 'Gender-Neutral'
    ): array {
        //Get the historical performance for this exact combo
        $primary = $this->statsForCombo($iitId, $branchId, $seatType, $gender);

        if (!$primary) {
            return [
                'found'       => false,
                'message'     => 'No historical data found for this exact combination.',
                'suggestions' => $this->fallbackSuggestions($userRank, $iitId, $branchId, $seatType, $gender),
            ];
        }

        //Compute chance for the chosen combo
        $std       = max((int) $primary['std_close'], 50);
        $threshold = (int) round(0.6 * $primary['latest_close'] + 0.4 * $primary['avg_close']);
        $z         = ($threshold - $userRank) / $std;
        [$bucket, $score] = $this->bucketize($z);

        $primary = array_merge($primary, [
            'threshold'    => $threshold,
            'z_score'      => round($z, 2),
            'chance'       => $bucket,
            'chance_score' => $score,
            'reasoning'    => $this->reasoningFor($userRank, $threshold, $std, $bucket),
        ]);

        //Yearly trend for chart
        $yearlyTrend = $this->yearlyTrendForCombo($iitId, $branchId, $seatType, $gender);

        //Find suggestions: same branch at similar IITs, same IIT with similar branches
        $sameBranchOtherIITs = $this->sameBranchOtherIITs(
            $userRank, $iitId, $branchId, $seatType, $gender
        );

        $sameIITOtherBranches = $this->sameIITOtherBranches(
            $userRank, $iitId, $branchId, $seatType, $gender
        );

        return [
            'found'                    => true,
            'user_rank'                => $userRank,
            'primary'                  => $primary,
            'yearly_trend'             => $yearlyTrend,
            'same_branch_other_iits'   => $sameBranchOtherIITs,
            'same_iit_other_branches'  => $sameIITOtherBranches,
        ];
    }

    // =========================================================================
    //  Helpers
    // =========================================================================

    private function statsForCombo(int $iitId, int $branchId, string $seatType, string $gender): ?array
    {
        $sql = "
            SELECT
              i.iit_id, i.iit_name, i.short_code AS iit_short,
              b.branch_id, b.branch_name, b.category,
              ROUND(AVG(f.closing_rank))        AS avg_close,
              ROUND(MIN(f.closing_rank))        AS min_close,
              ROUND(MAX(f.closing_rank))        AS max_close,
              ROUND(STDDEV_POP(f.closing_rank)) AS std_close,
              MAX(f.year)                        AS latest_year,
              SUBSTRING_INDEX(GROUP_CONCAT(f.closing_rank ORDER BY f.year DESC), ',', 1) AS latest_close,
              COUNT(*) AS samples
            FROM fact_allotment f
            JOIN dim_iit i        ON f.iit_id = i.iit_id
            JOIN dim_branch b     ON f.branch_id = b.branch_id
            JOIN dim_seat_type s  ON f.seat_type_id = s.seat_type_id
            JOIN dim_gender g     ON f.gender_id = g.gender_id
            WHERE i.iit_id = :iit
              AND b.branch_id = :branch
              AND s.seat_type_code = :seat
              AND g.gender_code = :gender
              AND f.is_preparatory = 0
              AND f.round_no = (
                  SELECT MAX(round_no) FROM fact_allotment x WHERE x.year = f.year
              )
            GROUP BY i.iit_id, b.branch_id
        ";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            ':iit'    => $iitId,
            ':branch' => $branchId,
            ':seat'   => $seatType,
            ':gender' => $gender,
        ]);
        return $stmt->fetch() ?: null;
    }

    private function yearlyTrendForCombo(int $iitId, int $branchId, string $seatType, string $gender): array
    {
        $sql = "
            SELECT f.year, MIN(f.opening_rank) AS opening, MAX(f.closing_rank) AS closing
            FROM fact_allotment f
            JOIN dim_seat_type s ON f.seat_type_id = s.seat_type_id
            JOIN dim_gender g    ON f.gender_id    = g.gender_id
            WHERE f.iit_id = :iit
              AND f.branch_id = :branch
              AND s.seat_type_code = :seat
              AND g.gender_code = :gender
              AND f.is_preparatory = 0
            GROUP BY f.year
            ORDER BY f.year
        ";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            ':iit' => $iitId, ':branch' => $branchId,
            ':seat' => $seatType, ':gender' => $gender,
        ]);
        return $stmt->fetchAll();
    }

    /** Same branch at OTHER IITs, where user's rank is competitive */
    private function sameBranchOtherIITs(
        int $userRank, int $iitId, int $branchId, string $seatType, string $gender
    ): array {
        $similarBranchIds = $this->getSimilarBranchIds($branchId);
        $inSet = implode(',', array_fill(0, count($similarBranchIds), '?'));

        $sql = "
            SELECT
              i.iit_id, i.iit_name, i.short_code AS iit_short, i.generation,
              b.branch_name,
              ROUND(AVG(f.closing_rank))        AS avg_close,
              ROUND(STDDEV_POP(f.closing_rank)) AS std_close,
              SUBSTRING_INDEX(GROUP_CONCAT(f.closing_rank ORDER BY f.year DESC), ',', 1) AS latest_close,
              COUNT(*) AS samples
            FROM fact_allotment f
            JOIN dim_iit i        ON f.iit_id = i.iit_id
            JOIN dim_branch b     ON f.branch_id = b.branch_id
            JOIN dim_seat_type s  ON f.seat_type_id = s.seat_type_id
            JOIN dim_gender g     ON f.gender_id = g.gender_id
            WHERE b.branch_id IN ($inSet)
              AND i.iit_id != ?
              AND s.seat_type_code = ?
              AND g.gender_code = ?
              AND f.is_preparatory = 0
              AND f.year >= 2019
              AND f.round_no = (
                  SELECT MAX(round_no) FROM fact_allotment x WHERE x.year = f.year
              )
            GROUP BY i.iit_id, b.branch_id
            HAVING samples >= 2
            ORDER BY avg_close ASC
        ";
        $stmt = $this->pdo->prepare($sql);
        $params = [...$similarBranchIds, $iitId, $seatType, $gender];
        $stmt->execute($params);

        return $this->annotateAndRank($stmt->fetchAll(), $userRank);
    }

    private function getSimilarBranchIds(int $branchId): array
    {
        $stmt = $this->pdo->prepare("SELECT branch_name FROM dim_branch WHERE branch_id = :id");
        $stmt->execute([':id' => $branchId]);
        $targetName = strtolower($stmt->fetchColumn() ?: '');

        if (!$targetName) return [$branchId];

        $bucketKey = $this->getBranchBucketKey($targetName);

        $stmt = $this->pdo->query("SELECT branch_id, branch_name FROM dim_branch");
        $allBranches = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $similarIds = [];
        foreach ($allBranches as $branch) {
            if ($this->getBranchBucketKey(strtolower($branch['branch_name'])) === $bucketKey) {
                $similarIds[] = (int) $branch['branch_id'];
            }
        }

        return empty($similarIds) ? [$branchId] : $similarIds;
    }

    private function getBranchBucketKey(string $name): string
    {
        if (str_contains($name, 'computer science') || str_contains($name, '(cse)')) return 'cse';
        if (str_contains($name, 'artificial intelligence') || str_contains($name, 'data science') || str_contains($name, 'data analytics') || str_contains($name, 'data engineering')) return 'ai_data';
        if (str_contains($name, 'mathematics & computing') || str_contains($name, 'mathematics and computing') || str_contains($name, 'mathematics and scientific computing')) return 'mnc';
        if (str_contains($name, 'mathematics')) return 'math';
        if (str_contains($name, 'electronics and communication') || str_contains($name, 'electronics and electrical communication') || str_contains($name, '(ece)')) return 'ece';
        if (str_contains($name, 'electrical') || str_contains($name, '(eee)')) return 'ee';
        if (str_contains($name, 'electronics') || str_contains($name, 'vlsi') || str_contains($name, 'microelectronics') || str_contains($name, 'integrated circuit')) return 'electronics_other';
        if (str_contains($name, 'mechanical') || str_contains($name, 'mechatronics') || str_contains($name, '(me)')) return 'mech';
        if (str_contains($name, 'civil') || str_contains($name, 'structural') || str_contains($name, 'infrastructure')) return 'civil';
        if (str_contains($name, 'chemical engineer') || str_contains($name, 'chemical and biochemical') || str_contains($name, 'chemical science')) return 'chem_eng';
        if (str_contains($name, 'chemistry') || str_contains($name, 'chemical sciences')) return 'chemistry';
        if (str_contains($name, 'aerospace') || str_contains($name, 'aeronautical')) return 'aero';
        if (str_contains($name, 'bio') && (str_contains($name, 'technology') || str_contains($name, 'engineer') || str_contains($name, 'informatic') || str_contains($name, 'science'))) return 'bio';
        if (str_contains($name, 'physics') || str_contains($name, 'engineering physics')) return 'physics';
        if (str_contains($name, 'metallurg') || str_contains($name, 'materials') || str_contains($name, 'ceramic') || str_contains($name, 'polymer')) return 'metal_mat';
        if (str_contains($name, 'mining') || str_contains($name, 'mineral')) return 'mining';
        if (str_contains($name, 'textile')) return 'textile';
        if (str_contains($name, 'production') || str_contains($name, 'industrial') || str_contains($name, 'manufacturing') || str_contains($name, 'quality')) return 'production_industrial';
        if (str_contains($name, 'architecture')) return 'arch';
        if (str_contains($name, 'ocean') || str_contains($name, 'naval')) return 'ocean';
        if (str_contains($name, 'pharmaceutic')) return 'pharma';
        if (str_contains($name, 'geolog') || str_contains($name, 'geophysic') || str_contains($name, 'earth science') || str_contains($name, 'petroleum')) return 'geo';
        if (str_contains($name, 'economics')) return 'eco';
        if (str_contains($name, 'energy')) return 'energy';
        if (str_contains($name, 'instrumentation')) return 'instrumentation';

        return $name;
    }

    /** Same IIT, OTHER branches the user can realistically get */
    private function sameIITOtherBranches(
        int $userRank, int $iitId, int $branchId, string $seatType, string $gender
    ): array {
        $sql = "
            SELECT
              i.iit_name, i.short_code AS iit_short,
              b.branch_id, b.branch_name, b.category,
              ROUND(AVG(f.closing_rank))        AS avg_close,
              ROUND(STDDEV_POP(f.closing_rank)) AS std_close,
              SUBSTRING_INDEX(GROUP_CONCAT(f.closing_rank ORDER BY f.year DESC), ',', 1) AS latest_close,
              COUNT(*) AS samples
            FROM fact_allotment f
            JOIN dim_iit i        ON f.iit_id = i.iit_id
            JOIN dim_branch b     ON f.branch_id = b.branch_id
            JOIN dim_seat_type s  ON f.seat_type_id = s.seat_type_id
            JOIN dim_gender g     ON f.gender_id = g.gender_id
            WHERE i.iit_id = :iit
              AND b.branch_id != :branch
              AND s.seat_type_code = :seat
              AND g.gender_code = :gender
              AND f.is_preparatory = 0
              AND f.year >= 2019
              AND f.round_no = (
                  SELECT MAX(round_no) FROM fact_allotment x WHERE x.year = f.year
              )
            GROUP BY b.branch_id
            HAVING samples >= 2
            ORDER BY avg_close ASC
        ";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            ':iit'    => $iitId,
            ':branch' => $branchId,
            ':seat'   => $seatType,
            ':gender' => $gender,
        ]);

        return $this->annotateAndRank($stmt->fetchAll(), $userRank);
    }

    /** When the chosen combo has no history at all, suggest closest matches */
    private function fallbackSuggestions(
        int $userRank, int $iitId, int $branchId, string $seatType, string $gender
    ): array {
        return [
            'same_branch_other_iits'  => $this->sameBranchOtherIITs($userRank, $iitId, $branchId, $seatType, $gender),
            'same_iit_other_branches' => $this->sameIITOtherBranches($userRank, $iitId, $branchId, $seatType, $gender),
        ];
    }

    private function annotateAndRank(array $rows, int $userRank): array
    {
        $out = [];
        foreach ($rows as $row) {
            $latest    = (int) $row['latest_close'];
            $mean      = (int) $row['avg_close'];
            $std       = max((int) $row['std_close'], 50);
            $threshold = (int) round(0.6 * $latest + 0.4 * $mean);
            $z         = ($threshold - $userRank) / $std;
            [$bucket, $score] = $this->bucketize($z);

            $out[] = array_merge($row, [
                'threshold'    => $threshold,
                'z_score'      => round($z, 2),
                'chance'       => $bucket,
                'chance_score' => $score,
            ]);
        }

        // Filter out very-low chance (< score 2) and sort by closest threshold
        $out = array_filter($out, fn($r) => $r['chance_score'] > 1);
        usort($out, function ($a, $b) use ($userRank) {
            $diffA = abs($a['threshold'] - $userRank);
            $diffB = abs($b['threshold'] - $userRank);
            return $diffA <=> $diffB;
        });

        return array_slice(array_values($out), 0, 12);
    }

    private function bucketize(float $z): array
    {
        if ($z >= 1.5)   return ['Very High', 5];
        if ($z >= 0.5)   return ['High', 4];
        if ($z >= -0.5)  return ['Moderate', 3];
        if ($z >= -1.5)  return ['Low', 2];
        return ['Very Low', 1];
    }

    private function reasoningFor(int $userRank, int $threshold, int $std, string $bucket): string
    {
        $diff = $threshold - $userRank;
        if ($diff > 0) {
            return "Your rank ({$userRank}) is {$diff} positions better than the predicted rank ({$threshold}). Volatility ±{$std}.";
        }
        $abs = abs($diff);
        return "Your rank ({$userRank}) is {$abs} positions worse than the predicted rank ({$threshold}). Volatility ±{$std}.";
    }

    // =========================================================================
    //  Lookup helpers (for the dropdowns)
    // =========================================================================
    public function getDropdownOptions(): array
    {
        return [
            'iits' => $this->pdo->query(
                "SELECT iit_id AS id, iit_name AS label, short_code FROM dim_iit ORDER BY iit_name"
            )->fetchAll(),
            'branches' => $this->pdo->query(
                "SELECT branch_id AS id, branch_name AS label FROM dim_branch ORDER BY branch_name"
            )->fetchAll(),
            'seat_types' => $this->pdo->query(
                "SELECT seat_type_code AS value FROM dim_seat_type ORDER BY seat_type_code"
            )->fetchAll(PDO::FETCH_COLUMN),
            'genders' => $this->pdo->query(
                "SELECT gender_code AS value FROM dim_gender ORDER BY gender_code"
            )->fetchAll(PDO::FETCH_COLUMN),
        ];
    }
}
