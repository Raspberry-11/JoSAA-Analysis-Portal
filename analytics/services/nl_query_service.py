import re
import json
import hashlib
from datetime import datetime

from django.db import connection

from analytics.services.sql_validator import SqlValidator
from analytics.services.rag_service import RagService


class NLQueryService:

    def __init__(self, llm, rag: RagService = None):
        self.llm = llm
        self.rag = rag

    def ask(self, question: str, conversation: list = None) -> dict:
        question = question.strip()
        if not question:
            raise ValueError('Question is empty')

        conversation = conversation or []
        cache_key = self._cache_key(question, conversation)

        cached = self._fetch_cache(cache_key)
        if cached:
            return {**cached, 'cached': True, 'model': self.llm.get_model_name()}

        augmented = self.rag.augment_question(question) if self.rag else question
        golden = self.rag.get_relevant_golden_queries(question) if self.rag else []

        messages = [{'role': 'system', 'content': self._system_prompt(golden)}]
        for turn in conversation:
            if 'role' in turn and 'content' in turn:
                messages.append(turn)
        messages.append({'role': 'user', 'content': augmented})

        max_retries = 2
        attempt = 0
        parsed = None
        safe_sql = None
        rows = []

        while attempt <= max_retries:
            raw = self.llm.complete(messages, {
                'temperature': 0.1,
                'json_mode': True,
                'max_tokens': 1500,
            })

            try:
                parsed = self._parse_llm_response(raw)
                sql = parsed.get('sql')
                if sql and isinstance(sql, str) and sql.strip() and sql.strip().upper() != 'NULL':
                    safe_sql = SqlValidator.validate_and_prepare(sql)
                    rows = self._execute_safely(safe_sql)
                break
            except Exception as e:
                last_error = str(e)
                messages.append({'role': 'assistant', 'content': raw})
                messages.append({
                    'role': 'user',
                    'content': f'Your SQL failed with this error: {last_error}. Please fix the query and return the corrected JSON object.',
                })
                attempt += 1
                if attempt > max_retries:
                    raise RuntimeError(f'Query failed after auto-correction retries. Last error: {last_error}')

        if parsed is None:
            raise RuntimeError('LLM failed to produce a parseable response after retries.')

        response_type = parsed.get('response_type', 'chart')
        chart = parsed.get('chart')
        response_type, chart = self._choose_response_type(response_type, chart, rows)

        answer = self._interpolate_answer(
            parsed.get('answer') or parsed.get('explanation') or '',
            rows
        )

        result = {
            'question':          question,
            'sql':               safe_sql,
            'data':              rows,
            'response_type':     response_type,
            'chart':             chart,
            'answer':            answer,
            'row_count':         len(rows),
            'suggested_followups': parsed.get('suggested_followups', []),
            'cache_key':         cache_key,
        }

        self._store_cache(cache_key, question, result)
        return {**result, 'cached': False, 'model': self.llm.get_model_name()}

    def delete_cache(self, cache_key: str) -> bool:
        with connection.cursor() as c:
            c.execute("DELETE FROM ai_queries WHERE cache_key = %s", [cache_key])
            return c.rowcount > 0

    def recent_history(self, limit: int = 20) -> list:
        with connection.cursor() as c:
            c.execute(
                "SELECT question, created_at, hit_count FROM ai_queries "
                "ORDER BY last_accessed_at DESC LIMIT %s",
                [int(limit)]
            )
            cols = [col[0] for col in c.description]
            return [dict(zip(cols, row)) for row in c.fetchall()]

    # =========================================================================
    #  Response type selector
    # =========================================================================
    @staticmethod
    def _choose_response_type(llm_choice: str, chart, rows: list):
        row_count = len(rows)
        if row_count == 0:
            return 'text', None

        col_count = len(rows[0])

        if row_count == 1 and col_count == 1:
            return 'text', None

        if row_count <= 2 and col_count >= 2:
            return 'text', None

        if llm_choice == 'table':
            return 'table', None

        if llm_choice == 'text' and row_count >= 3:
            return 'table', None

        if chart and chart.get('y'):
            y_col = chart['y']
            numeric_found = any(
                y_col in row and row[y_col] is not None and str(row[y_col]).replace('.', '', 1).lstrip('-').isdigit()
                for row in rows
            )
            if not numeric_found:
                return 'table', None

        if not chart or not chart.get('type') or not chart.get('x') or not chart.get('y'):
            return 'table', None

        return 'chart', chart

    # =========================================================================
    #  Answer interpolation
    # =========================================================================
    @staticmethod
    def _interpolate_answer(answer: str, rows: list) -> str:
        if not rows:
            return re.sub(r'\{[a-zA-Z_][a-zA-Z0-9_]*\}', '[No Data Found]', answer)
        if not answer:
            return answer

        first_row = rows[0]

        def replacer(m):
            key = m.group(1)
            if key in first_row and first_row[key] is not None:
                val = first_row[key]
                try:
                    return f'{float(val):,.0f}' if float(val) == int(float(val)) else f'{float(val):,.2f}'
                except (ValueError, TypeError):
                    return str(val)
            return '[Data Missing]'

        return re.sub(r'\{([a-zA-Z_][a-zA-Z0-9_]*)\}', replacer, answer)

    # =========================================================================
    #  Prompt construction
    # =========================================================================
    def _system_prompt(self, golden_queries: list = None) -> str:
        schema = self._schema_context()
        examples = self._few_shot_examples(golden_queries or [])
        return f"""You are a senior data analyst specializing in the JOSAA IIT seat allotment database (2016-2024).
Your job: answer natural-language questions by writing **MySQL SELECT queries** AND providing a clear written interpretation of the results.

# DATABASE SCHEMA
{schema}

# BUSINESS RULES
- The database has a star schema. `fact_allotment` is the fact table.
- `is_preparatory = 1` rows are preparatory ranks. By default, EXCLUDE them unless the user explicitly asks.
- `seat_type_code` common values: 'OPEN', 'OBC-NCL', 'SC', 'ST', 'EWS', 'OPEN (PwD)'. When users say "General" they mean 'OPEN'.
- `gender_code` values: 'Gender-Neutral', 'Female-only (including Supernumerary)'.
- `quota_code` values: 'AI' (All India), 'HS' (Home State), 'OS' (Other State), 'GO', 'JK', 'LA'.
- Lower closing_rank = more competitive / preferred.
- `dim_iit.generation` is pre-tagged: 'old' (first 8 IITs) vs 'new' (rest).
- `dim_branch.category` is pre-tagged: 'cse_family', 'new_age', 'core', 'interdisciplinary', 'other'.

# CRITICAL: ALWAYS INCLUDE IDENTIFYING CONTEXT IN RESULTS
When your query returns branch names → ALSO include `i.short_code AS iit`.
When returning aggregated ranks → ALSO include `f.year` if multiple years are involved.
For "top 10 toughest branches", group by BOTH iit AND branch: `CONCAT(i.short_code, ' · ', SUBSTRING_INDEX(b.branch_name, '(', 1)) AS label`.

# OUTPUT FORMAT
Return ONLY a JSON object with these keys:
{{
  "sql": "<a single MySQL SELECT query, or null if no database query is needed>",
  "answer": "<your written answer. To embed data from the FIRST row, wrap the exact column alias in curly braces {{column_name}}.>",
  "response_type": "text" | "table" | "chart",
  "chart": null OR {{
    "type": "line" | "bar" | "horizontalBar" | "scatter" | "pie",
    "x": "<column name for x-axis>",
    "y": "<column name for y-axis (must be numeric)>",
    "series": "<column name to split into multiple series, or null>",
    "title": "<chart title>",
    "y_reverse": true | false
  }},
  "suggested_followups": ["<question 1>", "<question 2>", "<question 3>"]
}}

# SQL CONSTRAINTS
- SELECT or WITH only. No DDL, no DML, no SHOW/DESCRIBE.
- Single statement only (no semicolons in the middle).
- Only reference tables: fact_allotment, dim_iit, dim_branch, dim_quota, dim_seat_type, dim_gender.
- Always alias tables short (f, i, b, q, s, g).
- Always ORDER BY when returning trends or rankings.
- Include LIMIT appropriate to the question (default 20 for rankings).
- Exclude preparatory ranks by default: `f.is_preparatory = 0`
- For aggregations, use ROUND() to return clean integer ranks.
- Branch names are very long. Use `SUBSTRING_INDEX(b.branch_name, '(', 1)` to trim the parentheses suffix.

# FEW-SHOT EXAMPLES
{examples}

Remember: respond with ONLY the JSON object. No markdown fences, no prose outside the JSON."""

    @staticmethod
    def _schema_context() -> str:
        return """TABLE dim_iit (
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
  quota_code VARCHAR   -- AI, HS, OS, GO, JK, LA. IITs ONLY use All India (AI) quota.
)

TABLE dim_seat_type (
  seat_type_id TINYINT PK,
  seat_type_code VARCHAR   -- OPEN, OBC-NCL, SC, ST, EWS, OPEN (PwD), etc. Default filter: seat_type_code='OPEN'
)

TABLE dim_gender (
  gender_id TINYINT PK,
  gender_code VARCHAR   -- 'Gender-Neutral', 'Female-only (including Supernumerary)', or literal 'NULL' for older years.
  -- WARNING: Older years (2016-2017) store gender as literal string 'NULL'. Filter: (g.gender_code='Gender-Neutral' OR g.gender_code='NULL')
)

TABLE fact_allotment (
  id BIGINT PK,
  iit_id, branch_id, quota_id, seat_type_id, gender_id  (FK to dimension tables)
  year SMALLINT,          -- 2016..2024
  round_no TINYINT,       -- Varies by year! Use subquery: WHERE (f.year, f.round_no) IN (SELECT year, MAX(round_no) FROM fact_allotment GROUP BY year)
  opening_rank INT,
  closing_rank INT,       -- LOWER = more competitive. Use CAST(x AS SIGNED) when subtracting two ranks!
  is_preparatory TINYINT  -- 1 = preparatory rank. Always filter: is_preparatory=0
)"""

    @staticmethod
    def _few_shot_examples(golden_queries: list) -> str:
        if not golden_queries:
            return """# EXAMPLE 1 — Time series (CHART)
Q: "Show me CSE closing ranks at IIT Bombay over the years"
A: {"sql": "SELECT f.year, ROUND(AVG(f.closing_rank)) AS avg_closing_rank FROM fact_allotment f JOIN dim_iit i ON f.iit_id=i.iit_id JOIN dim_branch b ON f.branch_id=b.branch_id JOIN dim_seat_type s ON f.seat_type_id=s.seat_type_id JOIN dim_gender g ON f.gender_id=g.gender_id WHERE quota_id=(SELECT quota_id FROM dim_quota WHERE quota_code='AI') AND i.iit_name='Indian Institute of Technology Bombay' AND b.category='cse_family' AND s.seat_type_code='OPEN' AND (g.gender_code='Gender-Neutral' OR g.gender_code='NULL') AND (f.year, f.round_no) IN (SELECT year, MAX(round_no) FROM fact_allotment GROUP BY year) AND f.is_preparatory=0 GROUP BY f.year ORDER BY f.year", "answer": "CSE closing ranks at IIT Bombay have steadily tightened over the years.", "response_type": "chart", "chart": {"type": "line", "x": "year", "y": "avg_closing_rank", "series": null, "title": "CSE Closing Rank at IIT Bombay", "y_reverse": true}, "suggested_followups": ["Compare with IIT Delhi", "Show female-only ranks for CSE at IIT Bombay", "What about other branches at IIT Bombay?"]}

# EXAMPLE 2 — Single fact (TEXT)
Q: "What was the closing rank for CSE at IIT Bombay in 2022?"
A: {"sql": "SELECT ROUND(AVG(f.closing_rank)) AS closing_rank FROM fact_allotment f JOIN dim_iit i ON f.iit_id=i.iit_id JOIN dim_branch b ON f.branch_id=b.branch_id JOIN dim_seat_type s ON f.seat_type_id=s.seat_type_id JOIN dim_gender g ON f.gender_id=g.gender_id WHERE quota_id=(SELECT quota_id FROM dim_quota WHERE quota_code='AI') AND i.iit_name='Indian Institute of Technology Bombay' AND b.category='cse_family' AND s.seat_type_code='OPEN' AND (g.gender_code='Gender-Neutral' OR g.gender_code='NULL') AND f.year=2022 AND f.is_preparatory=0 AND f.round_no=(SELECT MAX(round_no) FROM fact_allotment WHERE year=2022)", "answer": "In 2022, the average closing rank for CSE at IIT Bombay (OPEN category) was approximately {closing_rank}.", "response_type": "text", "chart": null, "suggested_followups": ["What was it in 2021?", "Compare with IIT Delhi in 2022", "Show all years for this branch"]}"""

        parts = []
        for i, gq in enumerate(golden_queries, 1):
            obj = {
                'sql': gq.get('sql'),
                'answer': gq.get('answer'),
                'response_type': gq.get('response_type', 'text'),
                'chart': gq.get('chart'),
                'suggested_followups': ['Compare with another branch', 'Show trends over time', 'Show female cutoffs'],
            }
            parts.append(f'# EXAMPLE {i}\nQ: "{gq["q"]}"\nA: {json.dumps(obj)}')
        return '\n\n'.join(parts)

    # =========================================================================
    #  LLM response parsing
    # =========================================================================
    @staticmethod
    def _parse_llm_response(raw: str) -> dict:
        start = raw.find('{')
        end = raw.rfind('}')
        if start != -1 and end != -1 and end >= start:
            json_str = raw[start:end + 1]
            decoded = json.loads(json_str)
            if isinstance(decoded, dict) and 'sql' in decoded:
                return decoded
        raise RuntimeError(f'LLM returned malformed response. Raw: {raw[:500]}')

    # =========================================================================
    #  SQL execution with timeout hint
    # =========================================================================
    @staticmethod
    def _execute_safely(sql: str) -> list:
        hinted = re.sub(
            r'^\s*SELECT\b',
            'SELECT /*+ MAX_EXECUTION_TIME(5000) */',
            sql,
            count=1,
            flags=re.IGNORECASE
        )
        try:
            with connection.cursor() as c:
                c.execute(hinted)
                cols = [col[0] for col in c.description]
                return [dict(zip(cols, row)) for row in c.fetchall()]
        except Exception as e:
            raise RuntimeError(f'Query execution failed: {e}')

    # =========================================================================
    #  Cache
    # =========================================================================
    @staticmethod
    def _cache_key(question: str, conversation: list) -> str:
        normalized = re.sub(r'\s+', ' ', question.lower().strip())
        user_context = [
            re.sub(r'\s+', ' ', t.get('content', '').lower().strip())
            for t in conversation if t.get('role') == 'user'
        ]
        combined = normalized + '||' + json.dumps(user_context)
        return hashlib.sha256(combined.encode()).hexdigest()

    @staticmethod
    def _fetch_cache(key: str):
        with connection.cursor() as c:
            c.execute(
                "SELECT response_json FROM ai_queries WHERE cache_key = %s LIMIT 1",
                [key]
            )
            row = c.fetchone()
        if not row:
            return None
        with connection.cursor() as c:
            c.execute(
                "UPDATE ai_queries SET hit_count = hit_count + 1, last_accessed_at = %s WHERE cache_key = %s",
                [datetime.now(), key]
            )
        return json.loads(row[0])

    @staticmethod
    def _store_cache(key: str, question: str, response: dict) -> None:
        now = datetime.now()
        with connection.cursor() as c:
            c.execute(
                """INSERT INTO ai_queries (cache_key, question, response_json, created_at, last_accessed_at)
                   VALUES (%s, %s, %s, %s, %s)
                   ON DUPLICATE KEY UPDATE response_json = VALUES(response_json), last_accessed_at = %s""",
                [key, question, json.dumps(response, default=str), now, now, now]
            )
