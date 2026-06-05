<?php
declare(strict_types=1);

/**
 * SqlValidator
 * ============
 *
 * Multi-layer safety gate for SQL produced by an LLM.
 * LLM output is NEVER trusted raw — this validator must pass before exec.
 *
 * Guardrails (fail closed):
 *   1. Non-empty after trim
 *   2. Single statement only (no stacked queries via ';')
 *   3. SELECT / WITH (CTE) only — no DDL or DML
 *   4. No forbidden keywords (DROP, ALTER, INSERT, UPDATE, DELETE,
 *      TRUNCATE, RENAME, GRANT, REVOKE, CREATE, LOAD, HANDLER, LOCK)
 *   5. No multi-statement tricks (comments, /*... *\/, -- )
 *   6. Only whitelisted tables referenced
 *   7. LIMIT injected if missing, capped at MAX_ROWS
 *
 * Any failure throws InvalidArgumentException with a reason suitable for
 * logging but NOT for direct display to end users (information leak risk).
 */
final class SqlValidator
{
    public const MAX_ROWS = 1000;

    /** Whitelist of tables the LLM is allowed to reference. */
    private const ALLOWED_TABLES = [
        'fact_allotment',
        'dim_iit',
        'dim_branch',
        'dim_quota',
        'dim_seat_type',
        'dim_gender',
    ];

    /**
     * Forbidden keywords, compiled as word-boundary regex.
     * We treat even read-only `SHOW`/`DESCRIBE` as forbidden — the API is
     * meant for data questions, not metadata introspection.
     */
    private const FORBIDDEN = [
        'INSERT', 'UPDATE', 'DELETE', 'DROP', 'ALTER', 'CREATE', 'TRUNCATE',
        'RENAME', 'GRANT', 'REVOKE', 'REPLACE', 'MERGE', 'CALL', 'EXEC',
        'EXECUTE', 'PREPARE', 'DEALLOCATE', 'HANDLER', 'LOCK', 'UNLOCK',
        'LOAD', 'OUTFILE', 'DUMPFILE', 'INTO\s+OUTFILE', 'INTO\s+DUMPFILE',
        'SET\s+GLOBAL', 'SET\s+SESSION', 'SHOW', 'DESCRIBE', 'DESC\s',
        'EXPLAIN', 'USE\s+', 'BENCHMARK', 'SLEEP',
    ];

    /**
     * Validate and rewrite SQL. Returns the safe SQL to execute.
     *
     * @throws InvalidArgumentException if any guardrail fails
     */
    public static function validateAndPrepare(string $sql): string
    {
        $sql = trim($sql);

        if ($sql === '') {
            throw new InvalidArgumentException('Empty SQL');
        }

        // Strip trailing semicolons (single one is fine, multiple = stacking)
        $sql = rtrim($sql, "; \t\n\r\0\x0B");

        // ---------- Layer 1: no stacked statements ----------
        // After trimming the final semicolon, if there's still a ';' anywhere
        // outside a quoted string, it's a stacked-statement attack.
        if (self::containsUnquotedSemicolon($sql)) {
            throw new InvalidArgumentException('Multiple statements not allowed');
        }

        // ---------- Layer 2: remove comments, then re-check ----------
        $stripped = self::stripComments($sql);

        // ---------- Layer 3: must START with SELECT or WITH ----------
        if (!preg_match('/^\s*(SELECT|WITH)\b/i', $stripped)) {
            throw new InvalidArgumentException('Only SELECT / WITH queries are allowed');
        }

        // ---------- Layer 4: forbidden keywords ----------
        foreach (self::FORBIDDEN as $kw) {
            if (preg_match('/\b' . $kw . '\b/i', $stripped)) {
                throw new InvalidArgumentException("Forbidden keyword detected: {$kw}");
            }
        }

        // ---------- Layer 5: table whitelist ----------
        // Grab every token following FROM or JOIN, lowercase it, check against whitelist.
        $tables = self::extractTables($stripped);
        foreach ($tables as $t) {
            if (!in_array($t, self::ALLOWED_TABLES, true)) {
                throw new InvalidArgumentException("Disallowed table: {$t}");
            }
        }

        // ---------- Layer 6: LIMIT injection ----------
        // If LLM provided a LIMIT, cap it; if not, append one.
        $final = self::enforceLimit($sql, self::MAX_ROWS);

        return $final;
    }

    private static function containsUnquotedSemicolon(string $sql): bool
    {
        $len = strlen($sql);
        $inSingle = false; $inDouble = false; $inBacktick = false;

        for ($i = 0; $i < $len; $i++) {
            $ch = $sql[$i];
            $prev = $i > 0 ? $sql[$i - 1] : '';

            if ($ch === "'" && $prev !== '\\' && !$inDouble && !$inBacktick) {
                $inSingle = !$inSingle;
            } elseif ($ch === '"' && $prev !== '\\' && !$inSingle && !$inBacktick) {
                $inDouble = !$inDouble;
            } elseif ($ch === '`' && !$inSingle && !$inDouble) {
                $inBacktick = !$inBacktick;
            } elseif ($ch === ';' && !$inSingle && !$inDouble && !$inBacktick) {
                return true;
            }
        }
        return false;
    }

    private static function stripComments(string $sql): string
    {
        // Remove /* ... */ comments (non-greedy)
        $sql = preg_replace('!/\*.*?\*/!s', ' ', $sql);
        // Remove -- line comments
        $sql = preg_replace('/--[^\n]*/', ' ', $sql);
        // Remove # line comments (MySQL-specific)
        $sql = preg_replace('/#[^\n]*/', ' ', $sql);
        return $sql;
    }

    /**
     * Extract table names after FROM / JOIN, excluding:
     *   - subquery aliases:  FROM (SELECT ...) t
     *   - CTE aliases:       WITH foo AS (SELECT ...) ... FROM foo
     *
     * Strips backticks and schema prefixes.
     */
    private static function extractTables(string $sql): array
    {
        // 1. Collect CTE names declared in WITH clauses. They're aliases, not real tables.
        $cteNames = [];
        if (preg_match_all('/\bWITH\s+(.+?)\s+SELECT\b/is', $sql, $withMatches)) {
            foreach ($withMatches[1] as $withBody) {
                // Match each "name AS (" occurrence
                if (preg_match_all('/\b([a-zA-Z_][a-zA-Z0-9_]*)\s+AS\s*\(/i', $withBody, $cteMatches)) {
                    foreach ($cteMatches[1] as $name) {
                        $cteNames[] = strtolower($name);
                    }
                }
            }
        }

        // 2. Find tables after FROM/JOIN, but skip if followed by '(' (i.e. subquery)
        $tables = [];
        $pattern = '/\b(?:FROM|JOIN)\s+`?([a-zA-Z_][a-zA-Z0-9_]*)`?/i';

        if (preg_match_all($pattern, $sql, $matches)) {
            foreach ($matches[1] as $t) {
                $lower = strtolower($t);
                // Skip CTE aliases — they're not real tables
                if (in_array($lower, $cteNames, true)) continue;
                $tables[] = $lower;
            }
        }
        return array_unique($tables);
    }

    private static function enforceLimit(string $sql, int $max): string
    {
        // Check for LIMIT with optional OFFSET
        // Matches: LIMIT 10
        // Matches: LIMIT 10, 20
        // Matches: LIMIT 10 OFFSET 20
        if (preg_match('/\bLIMIT\s+(\d+)(?:\s*(?:,|OFFSET)\s*(\d+))?\s*$/i', $sql, $m)) {
            $isOffsetSyntax = stripos($m[0], 'OFFSET') !== false;
            $hasComma = strpos($m[0], ',') !== false;

            if ($isOffsetSyntax) {
                $count = (int) $m[1];
            } elseif ($hasComma) {
                $count = (int) $m[2];
            } else {
                $count = (int) $m[1];
            }

            if ($count > $max) {
                if ($isOffsetSyntax) {
                    return preg_replace(
                        '/\bLIMIT\s+(\d+)\s+OFFSET\s+(\d+)\s*$/i',
                        "LIMIT {$max} OFFSET $2",
                        $sql
                    );
                } elseif ($hasComma) {
                    return preg_replace(
                        '/\bLIMIT\s+(\d+)\s*,\s*(\d+)\s*$/i',
                        "LIMIT $1, {$max}",
                        $sql
                    );
                } else {
                    return preg_replace(
                        '/\bLIMIT\s+(\d+)\s*$/i',
                        "LIMIT {$max}",
                        $sql
                    );
                }
            }
            return $sql;
        }

        // No LIMIT → append one
        return $sql . " LIMIT {$max}";
    }
}
