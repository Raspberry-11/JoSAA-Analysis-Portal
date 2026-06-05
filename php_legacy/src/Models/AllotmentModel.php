<?php
declare(strict_types=1);

/**
 * AllotmentModel
 *
 * Encapsulates all SQL for the analytics portal:
 *   - Dynamic filter composition (safely parameterized)
 *   - Filter-option lookups
 *   - Filtered row fetch (for tables / export)
 *   - 10 analytical query methods (Q1 - Q10)
 */
final class AllotmentModel
{
    public function __construct(private PDO $pdo) {}

    /**
     * Build a reusable WHERE clause from a filter payload.
     * Returns: ['sql' => 'WHERE ... AND ...', 'params' => [':x_0' => 1, ...]]
     */
    public function buildFilters(array $f): array
    {
        $where  = [];
        $params = [];

        $map = [
            'years'     => ['col' => 'f.year',         'key' => 'year'],
            'iits'      => ['col' => 'f.iit_id',       'key' => 'iit'],
            'branches'  => ['col' => 'f.branch_id',    'key' => 'branch'],
            'quotas'    => ['col' => 'f.quota_id',     'key' => 'quota'],
            'seatTypes' => ['col' => 'f.seat_type_id', 'key' => 'seat'],
            'genders'   => ['col' => 'f.gender_id',    'key' => 'gender'],
            'rounds'    => ['col' => 'f.round_no',     'key' => 'round'],
        ];

        foreach ($map as $filterKey => $meta) {
            if (!empty($f[$filterKey]) && is_array($f[$filterKey])) {
                $ph = [];
                foreach ($f[$filterKey] as $i => $val) {
                    $name = ":{$meta['key']}_{$i}";
                    $ph[] = $name;
                    $params[$name] = $val;
                }
                $where[] = "{$meta['col']} IN (" . implode(',', $ph) . ")";
            }
        }

        return [
            'sql'    => $where ? 'WHERE ' . implode(' AND ', $where) : '',
            'params' => $params,
        ];
    }

    // -----------------------------------------------------------
    //  Filter Dropdown Options
    // -----------------------------------------------------------
    public function getFilterOptions(): array
    {
        return [
            'iits'      => $this->pdo->query("SELECT iit_id AS id, iit_name AS label FROM dim_iit ORDER BY iit_name")->fetchAll(),
            'branches'  => $this->pdo->query("SELECT branch_id AS id, branch_name AS label FROM dim_branch ORDER BY branch_name")->fetchAll(),
            'quotas'    => $this->pdo->query("SELECT quota_id AS id, quota_code AS label FROM dim_quota ORDER BY quota_code")->fetchAll(),
            'seatTypes' => $this->pdo->query("SELECT seat_type_id AS id, seat_type_code AS label FROM dim_seat_type ORDER BY seat_type_code")->fetchAll(),
            'genders'   => $this->pdo->query("SELECT gender_id AS id, gender_code AS label FROM dim_gender ORDER BY gender_code")->fetchAll(),
            'years'     => $this->pdo->query("SELECT DISTINCT year FROM fact_allotment ORDER BY year")->fetchAll(PDO::FETCH_COLUMN),
            'rounds'    => $this->pdo->query("SELECT DISTINCT round_no FROM fact_allotment ORDER BY round_no")->fetchAll(PDO::FETCH_COLUMN),
        ];
    }

    // -----------------------------------------------------------
    //  Filtered rows (for table view & export)
    // -----------------------------------------------------------
    public function getFilteredRows(array $f, int $limit = 5000): array
    {
        $filters = $this->buildFilters($f);
        $limit = max(1, min($limit, 20000));

        $sql = "SELECT i.iit_name, b.branch_name, q.quota_code, s.seat_type_code,
                       g.gender_code, f.year, f.round_no,
                       f.opening_rank, f.closing_rank, f.is_preparatory
                FROM fact_allotment f
                JOIN dim_iit i        ON f.iit_id = i.iit_id
                JOIN dim_branch b     ON f.branch_id = b.branch_id
                JOIN dim_quota q      ON f.quota_id = q.quota_id
                JOIN dim_seat_type s  ON f.seat_type_id = s.seat_type_id
                JOIN dim_gender g     ON f.gender_id = g.gender_id
                {$filters['sql']}
                ORDER BY f.year DESC, f.closing_rank ASC
                LIMIT {$limit}";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($filters['params']);
        return $stmt->fetchAll();
    }

    // =================================================================
    //  ANALYTICAL QUERIES  (Q1 - Q10)
    // =================================================================

    /** Q1 — CSE trend across Top 5 old IITs (Bombay, Delhi, Madras, Kanpur, Kharagpur) */
    public function cseTrendTopIITs(): array
    {
        $sql = "SELECT i.short_code, i.iit_name, f.year,
                       MIN(f.opening_rank)        AS min_open,
                       MAX(f.closing_rank)        AS max_close,
                       ROUND(AVG(f.closing_rank)) AS avg_close
                FROM fact_allotment f
                JOIN dim_iit i        ON f.iit_id = i.iit_id
                JOIN dim_branch b     ON f.branch_id = b.branch_id
                JOIN dim_seat_type s  ON f.seat_type_id = s.seat_type_id
                JOIN dim_gender g     ON f.gender_id = g.gender_id
                WHERE i.iit_name IN (
                    'Indian Institute of Technology Bombay',
                    'Indian Institute of Technology Delhi',
                    'Indian Institute of Technology Kanpur',
                    'Indian Institute of Technology Madras',
                    'Indian Institute of Technology Kharagpur'
                )
                  AND b.category = 'cse_family'
                  AND s.seat_type_code = 'OPEN'
                  AND g.gender_code = 'Gender-Neutral'
                  AND f.is_preparatory = 0
                  AND f.round_no = (
                      SELECT MAX(round_no) FROM fact_allotment f2 WHERE f2.year = f.year
                  )
                GROUP BY i.iit_id, i.short_code, i.iit_name, f.year
                ORDER BY f.year, avg_close";
        return $this->pdo->query($sql)->fetchAll();
    }

    /** Q2 — Top toughest branches (median closing rank, OPEN category) */
    public function toughestBranches(int $limit = 10): array
    {
        $limit = max(1, min($limit, 50));
        $sql = "WITH ranked AS (
                    SELECT b.branch_name, f.closing_rank,
                           ROW_NUMBER() OVER (PARTITION BY b.branch_id ORDER BY f.closing_rank) AS rn,
                           COUNT(*)     OVER (PARTITION BY b.branch_id) AS cnt
                    FROM fact_allotment f
                    JOIN dim_branch b    ON f.branch_id = b.branch_id
                    JOIN dim_seat_type s ON f.seat_type_id = s.seat_type_id
                    JOIN dim_gender g    ON f.gender_id = g.gender_id
                    WHERE s.seat_type_code = 'OPEN'
                      AND g.gender_code = 'Gender-Neutral'
                      AND f.is_preparatory = 0
                      AND f.round_no = (SELECT MAX(round_no) FROM fact_allotment x WHERE x.year = f.year)
                )
                SELECT branch_name,
                       AVG(CASE WHEN rn IN (FLOOR((cnt+1)/2), CEIL((cnt+1)/2))
                                THEN closing_rank END) AS median_close
                FROM ranked
                GROUP BY branch_name
                ORDER BY median_close ASC
                LIMIT {$limit}";
        return $this->pdo->query($sql)->fetchAll();
    }

    /** Q3 — Female-only vs Gender-Neutral closing ranks in core/CSE branches */
    public function genderSupernumeraryImpact(): array
    {
        $sql = "SELECT f.year, b.branch_name, g.gender_code,
                       ROUND(AVG(f.closing_rank)) AS avg_close,
                       COUNT(*) AS samples
                FROM fact_allotment f
                JOIN dim_branch b    ON f.branch_id = b.branch_id
                JOIN dim_gender g    ON f.gender_id = g.gender_id
                JOIN dim_seat_type s ON f.seat_type_id = s.seat_type_id
                WHERE b.category IN ('core','cse_family')
                  AND s.seat_type_code = 'OPEN'
                  AND f.is_preparatory = 0
                  AND f.round_no = (SELECT MAX(round_no) FROM fact_allotment x WHERE x.year = f.year)
                GROUP BY f.year, b.branch_name, g.gender_code
                ORDER BY b.branch_name, f.year, g.gender_code";
        return $this->pdo->query($sql)->fetchAll();
    }

    /** Q4 — New-age vs Core branch trajectories over years */
    public function newAgeVsCore(): array
    {
        $sql = "SELECT f.year, b.category,
                       ROUND(AVG(f.closing_rank)) AS avg_close,
                       COUNT(*) AS samples
                FROM fact_allotment f
                JOIN dim_branch b    ON f.branch_id = b.branch_id
                JOIN dim_seat_type s ON f.seat_type_id = s.seat_type_id
                JOIN dim_gender g    ON f.gender_id = g.gender_id
                WHERE b.category IN ('new_age','core','cse_family')
                  AND s.seat_type_code = 'OPEN'
                  AND g.gender_code = 'Gender-Neutral'
                  AND f.is_preparatory = 0
                  AND f.round_no = (SELECT MAX(round_no) FROM fact_allotment x WHERE x.year = f.year)
                GROUP BY f.year, b.category
                ORDER BY f.year, b.category";
        return $this->pdo->query($sql)->fetchAll();
    }

    /** Q5 — IIT preference hierarchy (median closing rank across all branches) */
    public function iitPreferenceRanking(): array
    {
        $sql = "WITH r AS (
                    SELECT i.iit_name, f.closing_rank, f.iit_id,
                           ROW_NUMBER() OVER (PARTITION BY f.iit_id ORDER BY f.closing_rank) AS rn,
                           COUNT(*)     OVER (PARTITION BY f.iit_id) AS cnt
                    FROM fact_allotment f
                    JOIN dim_iit i       ON f.iit_id = i.iit_id
                    JOIN dim_seat_type s ON f.seat_type_id = s.seat_type_id
                    JOIN dim_gender g    ON f.gender_id = g.gender_id
                    WHERE s.seat_type_code = 'OPEN'
                      AND g.gender_code = 'Gender-Neutral'
                      AND f.is_preparatory = 0
                      AND f.round_no = (SELECT MAX(round_no) FROM fact_allotment x WHERE x.year = f.year)
                )
                SELECT iit_name,
                       AVG(CASE WHEN rn IN (FLOOR((cnt+1)/2), CEIL((cnt+1)/2))
                                THEN closing_rank END) AS median_close
                FROM r
                GROUP BY iit_name
                ORDER BY median_close ASC";
        return $this->pdo->query($sql)->fetchAll();
    }

    /** Q6 — Average rank inflation from Round 1 to the final round per year */
    public function roundWiseDrop(): array
    {
        $sql = "WITH final_rounds AS (
                    SELECT year, MAX(round_no) AS final_round
                    FROM fact_allotment f
                    GROUP BY year
                    HAVING MAX(round_no) > 1
                ),
                round_inflation AS (
                    SELECT r1.year,
                           ROUND(AVG(CAST(rf.closing_rank AS SIGNED) - CAST(r1.closing_rank AS SIGNED))) AS avg_rank_inflation,
                           COUNT(*) AS paired_samples,
                           'round_1_to_final' AS metric_basis
                    FROM final_rounds fr
                    JOIN fact_allotment r1
                      ON r1.year = fr.year
                     AND r1.round_no = 1
                    JOIN fact_allotment rf
                      ON rf.year = r1.year
                     AND rf.iit_id = r1.iit_id
                     AND rf.branch_id = r1.branch_id
                     AND rf.quota_id = r1.quota_id
                     AND rf.seat_type_id = r1.seat_type_id
                     AND rf.gender_id = r1.gender_id
                     AND rf.round_no = fr.final_round
                    JOIN dim_seat_type s ON r1.seat_type_id = s.seat_type_id
                    JOIN dim_gender g    ON r1.gender_id = g.gender_id
                    WHERE s.seat_type_code = 'OPEN'
                      AND g.gender_code IN ('Gender-Neutral', 'NULL')
                      AND r1.is_preparatory = 0
                      AND rf.is_preparatory = 0
                    GROUP BY r1.year
                ),
                final_round_spread AS (
                    SELECT f.year,
                           ROUND(AVG(CAST(f.closing_rank AS SIGNED) - CAST(f.opening_rank AS SIGNED))) AS avg_rank_inflation,
                           COUNT(*) AS paired_samples,
                           'final_opening_to_closing' AS metric_basis
                    FROM final_rounds fr
                    JOIN fact_allotment f
                      ON f.year = fr.year
                     AND f.round_no = fr.final_round
                    JOIN dim_seat_type s ON f.seat_type_id = s.seat_type_id
                    JOIN dim_gender g    ON f.gender_id = g.gender_id
                    WHERE s.seat_type_code = 'OPEN'
                      AND g.gender_code IN ('Gender-Neutral', 'NULL')
                      AND f.is_preparatory = 0
                    GROUP BY f.year
                )
                SELECT year, avg_rank_inflation, paired_samples, metric_basis
                FROM round_inflation
                UNION ALL
                SELECT fs.year, fs.avg_rank_inflation, fs.paired_samples, fs.metric_basis
                FROM final_round_spread fs
                WHERE NOT EXISTS (
                    SELECT 1 FROM round_inflation ri WHERE ri.year = fs.year
                )
                ORDER BY year";
        return $this->pdo->query($sql)->fetchAll();
    }

    /** Q7 — Branch-vs-IIT tradeoff for top rankers (CR <= 5000) */
    public function branchVsIITTradeoff(): array
    {
        $sql = "SELECT i.generation,
                       CASE WHEN b.category = 'cse_family' THEN 'Top Branch (CSE Family)'
                            ELSE 'Other Branch' END AS branch_tier,
                       ROUND(AVG(f.closing_rank)) AS avg_close,
                       COUNT(*) AS samples
                FROM fact_allotment f
                JOIN dim_iit i       ON f.iit_id = i.iit_id
                JOIN dim_branch b    ON f.branch_id = b.branch_id
                JOIN dim_seat_type s ON f.seat_type_id = s.seat_type_id
                JOIN dim_gender g    ON f.gender_id = g.gender_id
                WHERE s.seat_type_code = 'OPEN'
                  AND g.gender_code = 'Gender-Neutral'
                  AND f.closing_rank <= 5000
                  AND f.is_preparatory = 0
                  AND f.round_no = (SELECT MAX(round_no) FROM fact_allotment x WHERE x.year = f.year)
                GROUP BY i.generation, branch_tier
                ORDER BY i.generation, branch_tier";
        return $this->pdo->query($sql)->fetchAll();
    }

    /** Q8 — Category cutoff gaps for same IIT+Branch combos */
    public function categoryCutoffGaps(): array
    {
        $sql = "SELECT i.iit_name, b.branch_name, s.seat_type_code,
                       ROUND(AVG(f.closing_rank)) AS avg_close,
                       COUNT(*) AS samples
                FROM fact_allotment f
                JOIN dim_iit i       ON f.iit_id = i.iit_id
                JOIN dim_branch b    ON f.branch_id = b.branch_id
                JOIN dim_seat_type s ON f.seat_type_id = s.seat_type_id
                JOIN dim_gender g    ON f.gender_id = g.gender_id
                WHERE s.seat_type_code IN ('OPEN','OBC-NCL','SC','ST')
                  AND g.gender_code = 'Gender-Neutral'
                  AND f.is_preparatory = 0
                  AND f.round_no = (SELECT MAX(round_no) FROM fact_allotment x WHERE x.year = f.year)
                GROUP BY i.iit_name, b.branch_name, s.seat_type_code
                HAVING COUNT(*) >= 3
                ORDER BY i.iit_name, b.branch_name, s.seat_type_code";
        return $this->pdo->query($sql)->fetchAll();
    }

    /** Q9 — Most volatile IIT-Branch combos (year-over-year stddev) */
    public function highestVolatility(int $limit = 15): array
    {
        $limit = max(1, min($limit, 50));
        $sql = "SELECT i.iit_name, b.branch_name,
                       ROUND(STDDEV_POP(yearly_avg)) AS volatility,
                       ROUND(AVG(yearly_avg))         AS mean_close,
                       COUNT(*) AS years_present
                FROM (
                    SELECT f.iit_id, f.branch_id, f.year,
                           AVG(f.closing_rank) AS yearly_avg
                    FROM fact_allotment f
                    JOIN dim_seat_type s ON f.seat_type_id = s.seat_type_id
                    JOIN dim_gender g    ON f.gender_id = g.gender_id
                    WHERE s.seat_type_code = 'OPEN'
                      AND g.gender_code = 'Gender-Neutral'
                      AND f.is_preparatory = 0
                      AND f.round_no = (SELECT MAX(round_no) FROM fact_allotment x WHERE x.year = f.year)
                    GROUP BY f.iit_id, f.branch_id, f.year
                ) t
                JOIN dim_iit i    ON t.iit_id = i.iit_id
                JOIN dim_branch b ON t.branch_id = b.branch_id
                GROUP BY i.iit_name, b.branch_name
                HAVING COUNT(*) >= 5
                ORDER BY volatility DESC
                LIMIT {$limit}";
        return $this->pdo->query($sql)->fetchAll();
    }

    /** Q10 — IITs capturing top 100 AIR students each year */
    public function top100Monopoly(): array
    {
        $sql = "SELECT i.iit_name, f.year, COUNT(*) AS top100_seats
                FROM fact_allotment f
                JOIN dim_iit i       ON f.iit_id = i.iit_id
                JOIN dim_seat_type s ON f.seat_type_id = s.seat_type_id
                JOIN dim_gender g    ON f.gender_id = g.gender_id
                WHERE s.seat_type_code = 'OPEN'
                  AND g.gender_code = 'Gender-Neutral'
                  AND f.opening_rank <= 100
                  AND f.is_preparatory = 0
                  AND f.round_no = (SELECT MAX(round_no) FROM fact_allotment x WHERE x.year = f.year)
                GROUP BY i.iit_name, f.year
                ORDER BY f.year, top100_seats DESC";
        return $this->pdo->query($sql)->fetchAll();
    }
}
