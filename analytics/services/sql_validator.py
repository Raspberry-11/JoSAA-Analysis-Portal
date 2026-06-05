import re


class SqlValidator:
    MAX_ROWS = 1000

    ALLOWED_TABLES = {
        'fact_allotment',
        'dim_iit',
        'dim_branch',
        'dim_quota',
        'dim_seat_type',
        'dim_gender',
    }

    FORBIDDEN = [
        'INSERT', 'UPDATE', 'DELETE', 'DROP', 'ALTER', 'CREATE', 'TRUNCATE',
        'RENAME', 'GRANT', 'REVOKE', 'REPLACE', 'MERGE', 'CALL', 'EXEC',
        'EXECUTE', 'PREPARE', 'DEALLOCATE', 'HANDLER', 'LOCK', 'UNLOCK',
        'LOAD', 'OUTFILE', 'DUMPFILE', r'INTO\s+OUTFILE', r'INTO\s+DUMPFILE',
        r'SET\s+GLOBAL', r'SET\s+SESSION', 'SHOW', 'DESCRIBE', r'DESC\s',
        'EXPLAIN', r'USE\s+', 'BENCHMARK', 'SLEEP',
    ]

    @classmethod
    def validate_and_prepare(cls, sql: str) -> str:
        sql = sql.strip().rstrip('; \t\n\r\x00')

        if not sql:
            raise ValueError('Empty SQL')

        if cls._contains_unquoted_semicolon(sql):
            raise ValueError('Multiple statements not allowed')

        stripped = cls._strip_comments(sql)

        if not re.match(r'^\s*(SELECT|WITH)\b', stripped, re.IGNORECASE):
            raise ValueError('Only SELECT / WITH queries are allowed')

        for kw in cls.FORBIDDEN:
            if re.search(r'\b' + kw + r'\b', stripped, re.IGNORECASE):
                raise ValueError(f'Forbidden keyword detected: {kw}')

        tables = cls._extract_tables(stripped)
        for t in tables:
            if t not in cls.ALLOWED_TABLES:
                raise ValueError(f'Disallowed table: {t}')

        return cls._enforce_limit(sql, cls.MAX_ROWS)

    @staticmethod
    def _contains_unquoted_semicolon(sql: str) -> bool:
        in_single = in_double = in_backtick = False
        prev = ''
        for ch in sql:
            if ch == "'" and prev != '\\' and not in_double and not in_backtick:
                in_single = not in_single
            elif ch == '"' and prev != '\\' and not in_single and not in_backtick:
                in_double = not in_double
            elif ch == '`' and not in_single and not in_double:
                in_backtick = not in_backtick
            elif ch == ';' and not in_single and not in_double and not in_backtick:
                return True
            prev = ch
        return False

    @staticmethod
    def _strip_comments(sql: str) -> str:
        sql = re.sub(r'/\*.*?\*/', ' ', sql, flags=re.DOTALL)
        sql = re.sub(r'--[^\n]*', ' ', sql)
        sql = re.sub(r'#[^\n]*', ' ', sql)
        return sql

    @classmethod
    def _extract_tables(cls, sql: str) -> list:
        # Collect CTE alias names — they are not real tables
        cte_names = set()
        for with_body in re.findall(r'\bWITH\s+(.+?)\s+SELECT\b', sql, re.IGNORECASE | re.DOTALL):
            for name in re.findall(r'\b([a-zA-Z_][a-zA-Z0-9_]*)\s+AS\s*\(', with_body, re.IGNORECASE):
                cte_names.add(name.lower())

        tables = []
        for t in re.findall(r'\b(?:FROM|JOIN)\s+`?([a-zA-Z_][a-zA-Z0-9_]*)`?', sql, re.IGNORECASE):
            lower = t.lower()
            if lower not in cte_names:
                tables.append(lower)
        return list(set(tables))

    @staticmethod
    def _enforce_limit(sql: str, max_rows: int) -> str:
        m = re.search(
            r'\bLIMIT\s+(\d+)(?:\s*(?:,|OFFSET)\s*(\d+))?\s*$',
            sql, re.IGNORECASE
        )
        if m:
            full_match = m.group(0)
            is_offset = 'OFFSET' in full_match.upper()
            has_comma = ',' in full_match

            count = int(m.group(1)) if not has_comma else int(m.group(2) or m.group(1))
            if is_offset:
                count = int(m.group(1))

            if count > max_rows:
                if is_offset:
                    sql = re.sub(
                        r'\bLIMIT\s+(\d+)\s+OFFSET\s+(\d+)\s*$',
                        f'LIMIT {max_rows} OFFSET \\2',
                        sql, flags=re.IGNORECASE
                    )
                elif has_comma:
                    sql = re.sub(
                        r'\bLIMIT\s+(\d+)\s*,\s*(\d+)\s*$',
                        f'LIMIT \\1, {max_rows}',
                        sql, flags=re.IGNORECASE
                    )
                else:
                    sql = re.sub(
                        r'\bLIMIT\s+(\d+)\s*$',
                        f'LIMIT {max_rows}',
                        sql, flags=re.IGNORECASE
                    )
            return sql

        return sql + f' LIMIT {max_rows}'
