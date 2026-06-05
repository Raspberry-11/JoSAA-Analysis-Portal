<?php
declare(strict_types=1);

/**
 * NLQueryService
 * ==============
 *
 * Orchestrates natural-language → SQL → chart workflow:
 *
 *   1. Check cache for exact normalized question (token-thrift)
 *   2. Build system prompt with:
 *        - Schema DDL
 *        - Business rules (what to filter by default, what preparatory means)
 *        - Few-shot examples mapping NL → SQL
 *        - Output format spec (JSON with sql + chart hints + explanation)
 *   3. Call LLM, expect JSON back
 *   4. Validate SQL via SqlValidator (security gate)
 *   5. Execute and return {sql, data, chart_config, explanation, cached}
 *
 * Multi-turn:  callers can pass prior [{q, sql, result_summary}] context.
 */
final class NLQueryService
{
    public function __construct(
        private PDO $pdo,
        private LLMProvider $llm,
        private ?RagService $rag = null
    ) {}

    /**
     * @param string $question        Natural language question
     * @param array  $conversation    Prior turns: [{role, content}, ...]
     * @return array                  {sql, data, chart, explanation, cached, model}
     */
    public function ask(string $question, array $conversation = []): array
    {
        $question = trim($question);
        if ($question === '') {
            throw new InvalidArgumentException('Question is empty');
        }

        $cacheKey = $this->cacheKey($question, $conversation);
        if ($cached = $this->fetchCache($cacheKey)) {
            return $cached + ['cached' => true, 'model' => $this->llm->getModelName()];
        }

        // ── RAG Augmentation
        $augmentedQuestion = $this->rag ? $this->rag->augmentQuestion($question) : $question;
        $goldenQueries = $this->rag ? $this->rag->getRelevantGoldenQueries($question) : [];

        // ── Build messages
        $messages = [
            ['role' => 'system', 'content' => $this->systemPrompt($goldenQueries)],
        ];

        // Thread-in prior conversation for multi-turn
        foreach ($conversation as $turn) {
            if (isset($turn['role'], $turn['content'])) {
                $messages[] = $turn;
            }
        }

        $messages[] = ['role' => 'user', 'content' => $augmentedQuestion];

        // ── Call LLM with Self-Healing SQL Retry Loop
        $maxRetries = 2;
        $attempt = 0;
        $lastError = null;
        $parsed = null;
        $safeSQL = null;
        $rows = [];

        while ($attempt <= $maxRetries) {
            $raw = $this->llm->complete($messages, [
                'temperature' => 0.1,
                'json_mode'   => true,
                'max_tokens'  => 1500,
            ]);

            try {
                $parsed = $this->parseLLMResponse($raw);
                $sql = $parsed['sql'] ?? null;
                
                if (is_string($sql) && trim($sql) !== '' && strtoupper(trim($sql)) !== 'NULL') {
                    $safeSQL = SqlValidator::validateAndPrepare($sql);
                    $rows = $this->executeSafely($safeSQL);
                }
                break; // Success!
            } catch (Throwable $e) {
                $lastError = $e->getMessage();
                // Append the error to the conversation so the LLM can fix its own mistake
                $messages[] = ['role' => 'assistant', 'content' => $raw];
                $messages[] = [
                    'role' => 'user',
                    'content' => "Your SQL failed with this error: {$lastError}. Please fix the query and return the corrected JSON object."
                ];
                $attempt++;
                if ($attempt > $maxRetries) {
                    throw new RuntimeException("Query failed after auto-correction retries. Last error: " . $lastError);
                }
            }
        }

        // ── Guard: if $parsed is still null after retries, something went very wrong
        if ($parsed === null) {
            throw new RuntimeException('LLM failed to produce a parseable response after retries.');
        }

        // ── Decide final response_type with server-side fallback logic.
        $responseType = $parsed['response_type'] ?? 'chart';
        $chart        = $parsed['chart'] ?? null;
        [$responseType, $chart] = $this->chooseResponseType($responseType, $chart, $rows);

        // ── Interpolate {column_name} placeholders in the answer text.
        $answer = $this->interpolateAnswer(
            $parsed['answer'] ?? $parsed['explanation'] ?? '',
            $rows
        );

        // ── Build response
        $result = [
            'question'      => $question,
            'sql'           => $safeSQL,
            'data'          => $rows,
            'response_type' => $responseType,
            'chart'         => $chart,
            'answer'        => $answer,
            'row_count'     => count($rows),
            'suggested_followups' => $parsed['suggested_followups'] ?? [],
            'cache_key'     => $cacheKey,
        ];

        $this->storeCache($cacheKey, $question, $result);

        return $result + ['cached' => false, 'model' => $this->llm->getModelName()];
    }

    /**
     * Server-side decision on response_type.
     * Overrides the LLM's choice when the data clearly doesn't suit a chart.
     *
     *  - 0 rows                  → "text" (just the answer)
     *  - 1 row, 1 column         → "text" (single fact)
     *  - 1-2 rows, 2+ columns    → "text"
     *  - 3+ rows but no numeric  → "table"
     *  - LLM said "table"        → keep as table
     *  - Otherwise               → trust the LLM's chart choice
     */
    private function chooseResponseType(string $llmChoice, ?array $chart, array $rows): array
    {
        $rowCount = count($rows);

        if ($rowCount === 0) {
            return ['text', null];
        }

        $cols = array_keys($rows[0]);
        $colCount = count($cols);

        // Single fact: 1 row × 1 column → text
        if ($rowCount === 1 && $colCount === 1) {
            return ['text', null];
        }

        // Very few rows with multiple columns → text (LLM's answer summarizes it)
        if ($rowCount <= 2 && $colCount >= 2) {
            return ['text', null];
        }

        // LLM explicitly chose table → respect it
        if ($llmChoice === 'table') {
            return ['table', null];
        }

        // LLM explicitly chose text but we have many rows → upgrade to table
        if ($llmChoice === 'text' && $rowCount >= 3) {
            return ['table', null];
        }

        // Verify chart spec: y column must be numeric in the data
        if ($chart && !empty($chart['y'])) {
            $yCol = $chart['y'];
            $isNumericFound = false;
            foreach ($rows as $row) {
                if (array_key_exists($yCol, $row) && is_numeric($row[$yCol])) {
                    $isNumericFound = true;
                    break;
                }
            }
            if (!$isNumericFound) {
                // y isn't numeric in any row → fall back to table
                return ['table', null];
            }
        }

        // No usable chart spec → table
        if (!$chart || empty($chart['type']) || empty($chart['x']) || empty($chart['y'])) {
            return ['table', null];
        }

        return ['chart', $chart];
    }

    /**
     * Replace {column_name} placeholders in the answer with values from the first row.
     * Useful for single-fact answers like "The closing rank was {closing_rank}".
     */
    private function interpolateAnswer(string $answer, array $rows): string
    {
        if (empty($rows)) {
            // If no rows returned, replace all placeholders with [No Data Found]
            return preg_replace('/\{[a-zA-Z_][a-zA-Z0-9_]*\}/', '[No Data Found]', $answer);
        }

        if ($answer === '') {
            return $answer;
        }

        $firstRow = $rows[0];

        return preg_replace_callback(
            '/\{([a-zA-Z_][a-zA-Z0-9_]*)\}/',
            function ($matches) use ($firstRow) {
                $key = $matches[1];
                if (array_key_exists($key, $firstRow)) {
                    $val = $firstRow[$key];
                    // Format numbers with thousands separator
                    if (is_numeric($val)) {
                        return number_format((float) $val);
                    }
                    return (string) $val;
                }
                return '[Data Missing]'; // gracefully handle unmatched placeholders
            },
            $answer
        );
    }

    // =========================================================================
    //  Prompt construction
    // =========================================================================
    private function systemPrompt(array $goldenQueries = []): string
    {
        $schema = $this->schemaContext();
        $examples = $this->fewShotExamples($goldenQueries);

        return <<<PROMPT
You are a senior data analyst specializing in the JOSAA IIT seat allotment database (2016-2024).
Your job: answer natural-language questions by writing **MySQL SELECT queries** AND providing a clear written interpretation of the results.

# DATABASE SCHEMA
{$schema}

# BUSINESS RULES
- The database has a star schema. `fact_allotment` is the fact table.
- `is_preparatory = 1` rows are preparatory ranks (students taking a prep year). By default, EXCLUDE them unless the user explicitly asks about preparatory ranks.
- `seat_type_code` common values: 'OPEN', 'OBC-NCL', 'SC', 'ST', 'EWS', 'OPEN (PwD)', etc. When users say "General" they mean 'OPEN'.
- `gender_code` values typically: 'Gender-Neutral', 'Female-only (including Supernumerary)'.
- `quota_code` values: 'AI' (All India), 'HS' (Home State), 'OS' (Other State), 'GO', 'JK', 'LA'.
- Lower closing_rank = more competitive / preferred.
- `dim_iit.generation` is pre-tagged: 'old' (first 8 IITs) vs 'new' (rest).
- `dim_branch.category` is pre-tagged: 'cse_family', 'new_age', 'core', 'interdisciplinary', 'other'.
- Round numbers go 1 → N (typically 6 or 7) per year.

# CRITICAL: ALWAYS INCLUDE IDENTIFYING CONTEXT IN RESULTS
A result like "Computer Science, Computer Science, Computer Science" is meaningless without knowing WHICH IIT each row is from.

When your query returns:
- Branch names → ALSO include `i.short_code AS iit` (the IIT it belongs to)
- IIT names → ALSO include `b.branch_name AS branch` if branches differ across rows
- Aggregated ranks → ALSO include `f.year` if multiple years are involved
- Seat types or gender splits → label them clearly

Even for "top 10 toughest branches", group by BOTH iit AND branch (because the same branch name exists at every IIT). Then concatenate them in display: `CONCAT(i.short_code, ' · ', SUBSTRING_INDEX(b.branch_name, '(', 1)) AS label`.

# OUTPUT FORMAT — DECIDE BETWEEN TEXT, TABLE, OR CHART
Return ONLY a JSON object with these keys:

{
  "sql": "<a single MySQL SELECT query, or null if no database query is needed to answer>",
  "answer": "<your written answer. IMPORTANT: To embed data from the FIRST row of your SQL result into your text, wrap the exact column alias in curly braces. The name inside the braces MUST EXACTLY MATCH the column name in your SELECT statement! (e.g., if you SELECT branch_name AS name, use {name}.)>",
  "response_type": "text" | "table" | "chart",
  "chart": null  OR  {
    "type": "line" | "bar" | "horizontalBar" | "scatter" | "pie",
    "x": "<column name for x-axis>",
    "y": "<column name for y-axis (must be numeric)>",
    "series": "<column name to split into multiple series, or null>",
    "title": "<chart title>",
    "y_reverse": true | false
  },
  "suggested_followups": [
    "<A highly relevant follow up question based on the data>",
    "<Another suggested follow up question>",
    "<A third suggested follow up question>"
  ]
}

# WHEN TO USE EACH RESPONSE TYPE

Use **"text"** (chart=null) when:
- The question asks for a single number, fact, or simple statement
  Examples: "What was the closing rank for CSE at IIT Bombay in 2022?" → just the number
  "Who has the highest closing rank for CSE?" → name + rank, in sentence form
- The question is conversational and a chart would be overkill
- The result has 1-3 rows that can be summarized in a sentence

Use **"table"** (chart=null) when:
- The user asks "list", "show me", "what are"
- The result has multiple columns of mixed type (text + numbers) where comparison isn't visual
- A chart would obscure the detail (e.g., showing seat counts across many seat types)

Use **"chart"** (with chart spec) when:
- Trends over time → "line" with `x: "year"`
- Comparing values across categories → "bar" or "horizontalBar"
- Rankings of many items (>5) → "horizontalBar" (labels readable)
- The user explicitly says "plot", "graph", "visualize", "chart", "trend"
- LOWER values mean better (ranks!) → set `y_reverse: true`
- Categorical splits within a trend → use `series`

# RULES OF THUMB
- Single-row answer? → text
- 2-10 rows of mixed columns? → table
- 5+ rows of one numeric value across one category? → chart
- Time series? → ALWAYS chart (line)

# SQL CONSTRAINTS
- SELECT or WITH only. No DDL, no DML, no SHOW/DESCRIBE.
- Single statement only (no semicolons in the middle).
- Only reference tables: fact_allotment, dim_iit, dim_branch, dim_quota, dim_seat_type, dim_gender.
- Always alias tables short (f, i, b, q, s, g).
- Always ORDER BY when returning trends or rankings.
- Include LIMIT appropriate to the question (default 20 for rankings).
- Exclude preparatory ranks by default: `f.is_preparatory = 0`
- For aggregations, use ROUND() to return clean integer ranks.
- Branch names are very long. Use `SUBSTRING_INDEX(b.branch_name, '(', 1)` to trim the parentheses suffix when displaying.

# FEW-SHOT EXAMPLES
{$examples}

Remember: respond with ONLY the JSON object. No markdown fences, no prose outside the JSON.
PROMPT;
    }

    private function schemaContext(): string
    {
        // Compact DDL representation. Tuned for LLM readability, not SQL execution.
        return <<<DDL
TABLE dim_iit (
  iit_id SMALLINT PK,
  iit_name VARCHAR,           -- e.g. 'Indian Institute of Technology Bombay'
  short_code VARCHAR,         -- e.g. 'IIT Bombay'
  generation ENUM('old','new')  -- old = first 8 IITs
)

TABLE dim_branch (
  branch_id INT PK,
  branch_name VARCHAR,         -- e.g. 'Computer Science and Engineering (4 Years, Bachelor of Technology)'
  category ENUM('core','cse_family','new_age','interdisciplinary','other')
)

TABLE dim_quota (
  quota_id TINYINT PK,
  quota_code VARCHAR   -- AI, HS, OS, GO, JK, LA. (COMMENT 'MANDATORY: IITs ONLY use All India (AI) quota. You MUST filter for this by adding `AND quota_id = (SELECT quota_id FROM dim_quota WHERE quota_code="AI")` to your WHERE clause. Do NOT use `q.quota_code` directly without a subquery or join.')
)

TABLE dim_seat_type (
  seat_type_id TINYINT PK,
  seat_type_code VARCHAR   -- OPEN, OBC-NCL, SC, ST, EWS, OPEN (PwD), etc. (COMMENT 'IMPORTANT: If the user does not specify a category/seat type, ALWAYS filter by seat_type_code="OPEN"')
)

TABLE dim_gender (
  gender_id TINYINT PK,
  gender_code VARCHAR   -- 'Gender-Neutral', 'Female-only (including Supernumerary)', or the string 'NULL'. (COMMENT 'WARNING: Older years (2016-2017) have gender_code stored as the literal string "NULL" (not SQL NULL). If gender not specified by user, filter with: (g.gender_code = "Gender-Neutral" OR g.gender_code = "NULL"). NEVER use just gender_code="Gender-Neutral" alone — it will miss all older data!')
)

TABLE fact_allotment (
  id BIGINT PK,
  iit_id SMALLINT FK -> dim_iit,
  branch_id INT FK -> dim_branch,
  quota_id TINYINT FK -> dim_quota,
  seat_type_id TINYINT FK -> dim_seat_type,
  gender_id TINYINT FK -> dim_gender,
  year SMALLINT,           -- 2016..2024. (COMMENT 'When comparing ranks across two years, ensure the branch existed in BOTH years to avoid NULL math.')
  round_no TINYINT,        -- The last round varies by year! (2016=6, 2017=7, 2018=7, 2019=7, 2020=6, 2021=6, 2022=6, 2023=6, 2024=5). (COMMENT 'CRITICAL: Do NOT hardcode round_no=6. Instead, use a subquery to get the max round for each year: WHERE (f.year, f.round_no) IN (SELECT year, MAX(round_no) FROM fact_allotment GROUP BY year). This ensures you always get the final round regardless of year.')
  opening_rank INT,        -- (COMMENT 'The highest rank accepted.')
  closing_rank INT,        -- (COMMENT 'The lowest rank accepted. LOWER NUMBER means harder/more competitive. WARNING: This column is UNSIGNED. When subtracting two ranks (e.g. rank_2017 - rank_2022), ALWAYS use CAST(x AS SIGNED) - CAST(y AS SIGNED) to avoid underflow errors!')
  is_preparatory TINYINT   -- (COMMENT '1 if preparatory rank. EXCLUDE by default (is_preparatory=0)')
)
DDL;
    }

    private function fewShotExamples(array $goldenQueries = []): string
    {
        if (empty($goldenQueries)) {
            // Fallback to static examples if RAG is disabled/empty
            return <<<EXAMPLES
# EXAMPLE 1 — Time series (CHART)
Q: "Show me CSE closing ranks at IIT Bombay over the years"
A: {
  "sql": "SELECT f.year, ROUND(AVG(f.closing_rank)) AS avg_closing_rank FROM fact_allotment f JOIN dim_iit i ON f.iit_id=i.iit_id JOIN dim_branch b ON f.branch_id=b.branch_id JOIN dim_seat_type s ON f.seat_type_id=s.seat_type_id JOIN dim_gender g ON f.gender_id=g.gender_id WHERE quota_id=(SELECT quota_id FROM dim_quota WHERE quota_code='AI') AND i.iit_name='Indian Institute of Technology Bombay' AND b.category='cse_family' AND s.seat_type_code='OPEN' AND (g.gender_code='Gender-Neutral' OR g.gender_code='NULL') AND (f.year, f.round_no) IN (SELECT year, MAX(round_no) FROM fact_allotment GROUP BY year) AND f.is_preparatory=0 GROUP BY f.year ORDER BY f.year",
  "answer": "CSE closing ranks at IIT Bombay have steadily tightened over the years, reflecting growing demand. The chart shows the average closing rank per year (lower = more competitive).",
  "response_type": "chart",
  "chart": {"type": "line", "x": "year", "y": "avg_closing_rank", "series": null, "title": "CSE Closing Rank at IIT Bombay", "y_reverse": true},
  "suggested_followups": ["Compare with IIT Delhi", "Show female-only ranks for CSE at IIT Bombay", "What about other branches at IIT Bombay?"]
}

# EXAMPLE 2 — Toughest branches with IIT context (CHART with composite labels)
Q: "What are the top 10 toughest branches?"
A: {
  "sql": "SELECT CONCAT(i.short_code, ' · ', SUBSTRING_INDEX(b.branch_name, '(', 1)) AS iit_branch, ROUND(AVG(f.closing_rank)) AS avg_closing_rank FROM fact_allotment f JOIN dim_iit i ON f.iit_id=i.iit_id JOIN dim_branch b ON f.branch_id=b.branch_id JOIN dim_seat_type s ON f.seat_type_id=s.seat_type_id JOIN dim_gender g ON f.gender_id=g.gender_id WHERE quota_id=(SELECT quota_id FROM dim_quota WHERE quota_code='AI') AND s.seat_type_code='OPEN' AND (g.gender_code='Gender-Neutral' OR g.gender_code='NULL') AND (f.year, f.round_no) IN (SELECT year, MAX(round_no) FROM fact_allotment GROUP BY year) AND f.is_preparatory=0 GROUP BY i.iit_id, b.branch_id HAVING COUNT(*) >= 3 ORDER BY avg_closing_rank ASC LIMIT 10",
  "answer": "These are the 10 toughest IIT-branch combinations to get into, ranked by average closing rank. CSE at top old IITs dominates the list.",
  "response_type": "chart",
  "chart": {"type": "horizontalBar", "x": "iit_branch", "y": "avg_closing_rank", "series": null, "title": "Top 10 Toughest IIT-Branch Combinations", "y_reverse": false},
  "suggested_followups": ["Show the top 20 instead", "Which are the easiest branches?", "Filter this to only OBC-NCL category"]
}

# EXAMPLE 3 — Single fact (TEXT)
Q: "What was the closing rank for CSE at IIT Bombay in 2022?"
A: {
  "sql": "SELECT ROUND(AVG(f.closing_rank)) AS closing_rank FROM fact_allotment f JOIN dim_iit i ON f.iit_id=i.iit_id JOIN dim_branch b ON f.branch_id=b.branch_id JOIN dim_seat_type s ON f.seat_type_id=s.seat_type_id JOIN dim_gender g ON f.gender_id=g.gender_id WHERE quota_id=(SELECT quota_id FROM dim_quota WHERE quota_code='AI') AND i.iit_name='Indian Institute of Technology Bombay' AND b.category='cse_family' AND s.seat_type_code='OPEN' AND (g.gender_code='Gender-Neutral' OR g.gender_code='NULL') AND f.year=2022 AND f.is_preparatory=0 AND f.round_no=(SELECT MAX(round_no) FROM fact_allotment WHERE year=2022)",
  "answer": "In 2022, the average closing rank for CSE at IIT Bombay (OPEN category) was approximately {closing_rank}. This makes it one of the most competitive programs in the country.",
  "response_type": "text",
  "chart": null,
  "suggested_followups": ["What was it in 2021?", "Compare with IIT Delhi in 2022", "Show closing ranks for all years for this branch"]
}
EXAMPLES;
        }

        $str = "";
        foreach ($goldenQueries as $i => $gq) {
            $n = $i + 1;
            $jsonObj = [
                "sql" => $gq['sql'],
                "answer" => $gq['answer'],
                "response_type" => $gq['response_type'] ?? 'text',
                "chart" => $gq['chart'] ?? null,
                "suggested_followups" => ["Compare with another branch", "Show trends over time", "Show female cutoffs"]
            ];
            $jsonStr = json_encode($jsonObj, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
            $str .= "# EXAMPLE {$n}\nQ: \"{$gq['q']}\"\nA: {$jsonStr}\n\n";
        }
        return $str;
    }

    // =========================================================================
    //  LLM response parsing
    // =========================================================================
    private function parseLLMResponse(string $raw): array
    {
        // Extract JSON from anywhere in the response (robust against conversational filler)
        $start = strpos($raw, '{');
        $end = strrpos($raw, '}');

        if ($start !== false && $end !== false && $end >= $start) {
            $jsonStr = substr($raw, $start, $end - $start + 1);
            $decoded = json_decode($jsonStr, true);
            if (is_array($decoded) && isset($decoded['sql'])) {
                return $decoded;
            }
        }

        throw new RuntimeException(
            'LLM returned malformed response. Raw: ' . substr($raw, 0, 500)
        );
    }

    // =========================================================================
    //  SQL execution with timeout
    // =========================================================================
    private function executeSafely(string $sql): array
    {
        // Wrap in a MySQL-side statement timeout (5s) to protect against
        // accidentally expensive queries.
        // Note: MAX_EXECUTION_TIME hint works on MySQL 5.7.8+ and is ignored on MariaDB,
        // so also set a PDO-level timeout as belt-and-suspenders.
        $hinted = preg_replace(
            '/^\s*SELECT\b/i',
            'SELECT /*+ MAX_EXECUTION_TIME(5000) */',
            $sql,
            1
        );

        try {
            $stmt = $this->pdo->query($hinted);
            return $stmt->fetchAll();
        } catch (PDOException $e) {
            // On MariaDB the hint gets ignored — just run the original if something broke.
            // Re-throw the original error since it's meaningful (syntax / table / etc.)
            throw new RuntimeException('Query execution failed: ' . $e->getMessage());
        }
    }

    // =========================================================================
    //  Cache
    // =========================================================================
    private function cacheKey(string $question, array $conversation): string
    {
        // Include ONLY user context in the key so multi-turn follow-ups
        // don't collide with first-turn identical questions, while remaining
        // immune to minor phrasing variations in the AI's previous responses.
        $normalized = strtolower(preg_replace('/\s+/', ' ', trim($question)));
        
        $userContext = [];
        foreach ($conversation as $turn) {
            if (($turn['role'] ?? '') === 'user') {
                $userContext[] = strtolower(preg_replace('/\s+/', ' ', trim($turn['content'] ?? '')));
            }
        }
        
        $context = json_encode($userContext);
        return hash('sha256', $normalized . '||' . $context);
    }

    private function fetchCache(string $key): ?array
    {
        $stmt = $this->pdo->prepare(
            "SELECT response_json FROM ai_queries WHERE cache_key = ? LIMIT 1"
        );
        $stmt->execute([$key]);
        $row = $stmt->fetch();

        if (!$row) return null;

        // Bump hit counter asynchronously (fire-and-forget)
        $this->pdo->prepare(
            "UPDATE ai_queries SET hit_count = hit_count + 1, last_accessed_at = NOW() WHERE cache_key = ?"
        )->execute([$key]);

        return json_decode($row['response_json'], true);
    }

    private function storeCache(string $key, string $question, array $response): void
    {
        $this->pdo->prepare(
            "INSERT INTO ai_queries (cache_key, question, response_json, created_at, last_accessed_at)
             VALUES (?, ?, ?, NOW(), NOW())
             ON DUPLICATE KEY UPDATE response_json = VALUES(response_json), last_accessed_at = NOW()"
        )->execute([$key, $question, json_encode($response)]);
    }

    /**
     * Delete a cached response (e.g., when user rates it as bad).
     */
    public function deleteCache(string $cacheKey): bool
    {
        $stmt = $this->pdo->prepare("DELETE FROM ai_queries WHERE cache_key = ?");
        $stmt->execute([$cacheKey]);
        return $stmt->rowCount() > 0;
    }

    // =========================================================================
    //  Recent history (for UI sidebar)
    // =========================================================================
    public function recentHistory(int $limit = 20): array
    {
        $stmt = $this->pdo->query(
            "SELECT question, created_at, hit_count
             FROM ai_queries
             ORDER BY last_accessed_at DESC
             LIMIT " . (int) $limit
        );
        return $stmt->fetchAll();
    }
}
