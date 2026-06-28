from django.db import connection


def dictfetchall(cursor):
    """Return all rows as a list of dicts (equivalent of PDO FETCH_ASSOC)."""
    columns = [col[0] for col in cursor.description]
    return [dict(zip(columns, row)) for row in cursor.fetchall()]


def dictfetchone(cursor):
    row = cursor.fetchone()
    if row is None:
        return None
    columns = [col[0] for col in cursor.description]
    return dict(zip(columns, row))


class AllotmentQueries:

    @staticmethod
    def build_filters(f: dict) -> tuple[str, list]:
        """
        Build a WHERE clause from filter payload.
        Returns (where_sql, params_list) where params_list uses %s placeholders.
        """
        where_parts = []
        params = []

        col_map = {
            'years':     'f.year',
            'iits':      'f.iit_id',
            'branches':  'f.branch_id',
            'quotas':    'f.quota_id',
            'seatTypes': 'f.seat_type_id',
            'genders':   'f.gender_id',
            'rounds':    'f.round_no',
        }

        for key, col in col_map.items():
            vals = f.get(key, [])
            if vals and isinstance(vals, list):
                placeholders = ', '.join(['%s'] * len(vals))
                where_parts.append(f"{col} IN ({placeholders})")
                params.extend(vals)

        where_sql = 'WHERE ' + ' AND '.join(where_parts) if where_parts else ''
        return where_sql, params

    @staticmethod
    def get_filter_options() -> dict:
        with connection.cursor() as c:
            c.execute("SELECT iit_id AS id, iit_name AS label FROM dim_iit ORDER BY iit_name")
            iits = dictfetchall(c)

            c.execute("SELECT branch_id AS id, branch_name AS label FROM dim_branch ORDER BY branch_name")
            branches = dictfetchall(c)

            c.execute("SELECT quota_id AS id, quota_code AS label FROM dim_quota ORDER BY quota_code")
            quotas = dictfetchall(c)

            c.execute("SELECT seat_type_id AS id, seat_type_code AS label FROM dim_seat_type ORDER BY seat_type_code")
            seat_types = dictfetchall(c)

            c.execute("SELECT gender_id AS id, gender_code AS label FROM dim_gender ORDER BY gender_code")
            genders = dictfetchall(c)

            c.execute("SELECT DISTINCT year FROM fact_allotment ORDER BY year")
            years = [row[0] for row in c.fetchall()]

            c.execute("SELECT DISTINCT round_no FROM fact_allotment ORDER BY round_no")
            rounds = [row[0] for row in c.fetchall()]

        return {
            'iits': iits,
            'branches': branches,
            'quotas': quotas,
            'seatTypes': seat_types,
            'genders': genders,
            'years': years,
            'rounds': rounds,
        }

    @staticmethod
    def get_cascading_filter_options(f: dict) -> dict:
        """
        Return the valid options for every filter GIVEN the current selection.
        For each dimension, options = distinct values still present in
        fact_allotment after applying all the OTHER active filters (so a user
        can still widen/multi-select within the same dimension).
        e.g. selecting an IIT narrows Branch to branches offered at that IIT.
        """
        dims = {
            'iits':      ('JOIN dim_iit d ON f.iit_id = d.iit_id',
                          'd.iit_id AS id, d.iit_name AS label', 'd.iit_name'),
            'branches':  ('JOIN dim_branch d ON f.branch_id = d.branch_id',
                          'd.branch_id AS id, d.branch_name AS label', 'd.branch_name'),
            'quotas':    ('JOIN dim_quota d ON f.quota_id = d.quota_id',
                          'd.quota_id AS id, d.quota_code AS label', 'd.quota_code'),
            'seatTypes': ('JOIN dim_seat_type d ON f.seat_type_id = d.seat_type_id',
                          'd.seat_type_id AS id, d.seat_type_code AS label', 'd.seat_type_code'),
            'genders':   ('JOIN dim_gender d ON f.gender_id = d.gender_id',
                          'd.gender_id AS id, d.gender_code AS label', 'd.gender_code'),
        }
        result = {}
        with connection.cursor() as c:
            for key, (join, select, order) in dims.items():
                others = {k: v for k, v in f.items() if k != key}
                where_sql, params = AllotmentQueries.build_filters(others)
                c.execute(
                    f"SELECT DISTINCT {select} FROM fact_allotment f {join} {where_sql} ORDER BY {order}",
                    params,
                )
                result[key] = dictfetchall(c)

            for key, col in (('years', 'f.year'), ('rounds', 'f.round_no')):
                others = {k: v for k, v in f.items() if k != key}
                where_sql, params = AllotmentQueries.build_filters(others)
                c.execute(
                    f"SELECT DISTINCT {col} FROM fact_allotment f {where_sql} ORDER BY {col}",
                    params,
                )
                result[key] = [row[0] for row in c.fetchall()]
        return result

    @staticmethod
    def get_filtered_rows(f: dict, limit: int = 5000) -> list:
        where_sql, params = AllotmentQueries.build_filters(f)
        limit = max(1, min(int(limit), 20000))

        sql = f"""SELECT i.iit_name, b.branch_name, q.quota_code, s.seat_type_code,
                         g.gender_code, f.year, f.round_no,
                         f.opening_rank, f.closing_rank, f.is_preparatory
                  FROM fact_allotment f
                  JOIN dim_iit i        ON f.iit_id = i.iit_id
                  JOIN dim_branch b     ON f.branch_id = b.branch_id
                  JOIN dim_quota q      ON f.quota_id = q.quota_id
                  JOIN dim_seat_type s  ON f.seat_type_id = s.seat_type_id
                  JOIN dim_gender g     ON f.gender_id = g.gender_id
                  {where_sql}
                  ORDER BY f.year DESC, f.closing_rank ASC
                  LIMIT {limit}"""

        with connection.cursor() as c:
            c.execute(sql, params)
            return dictfetchall(c)

    # ==========================================================================
    #  ANALYTICAL QUERIES  (Q1 - Q10)
    # ==========================================================================

    @staticmethod
    def cse_trend_top_iits() -> list:
        sql = """SELECT i.short_code, i.iit_name, f.year,
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
                 ORDER BY f.year, avg_close"""
        with connection.cursor() as c:
            c.execute(sql)
            return dictfetchall(c)

    @staticmethod
    def common_branch_preference(min_iits: int = 10) -> list:
        """Suggested general branch preference order: branches offered widely across
        IITs (offered at >= min_iits IITs, e.g. CSE/Mech/Civil/Electrical), ranked by
        overall average OPEN closing rank (lower = more preferred)."""
        min_iits = max(1, min(int(min_iits), 30))
        sql = f"""SELECT SUBSTRING_INDEX(b.branch_name, '(', 1) AS branch,
                         COUNT(DISTINCT f.iit_id)   AS iit_coverage,
                         ROUND(AVG(f.closing_rank)) AS avg_close
                  FROM fact_allotment f
                  JOIN dim_branch b    ON f.branch_id = b.branch_id
                  JOIN dim_seat_type s ON f.seat_type_id = s.seat_type_id
                  JOIN dim_gender g    ON f.gender_id = g.gender_id
                  WHERE s.seat_type_code = 'OPEN'
                    AND g.gender_code IN ('Gender-Neutral', 'NULL')
                    AND f.is_preparatory = 0
                    AND f.round_no = (SELECT MAX(round_no) FROM fact_allotment x WHERE x.year = f.year)
                  GROUP BY branch
                  HAVING iit_coverage >= {min_iits}
                  ORDER BY avg_close ASC"""
        with connection.cursor() as c:
            c.execute(sql)
            return dictfetchall(c)

    @staticmethod
    def gender_supernumerary_impact() -> list:
        sql = """SELECT f.year, b.branch_name, g.gender_code,
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
                 ORDER BY b.branch_name, f.year, g.gender_code"""
        with connection.cursor() as c:
            c.execute(sql)
            return dictfetchall(c)

    @staticmethod
    def new_age_vs_core() -> list:
        sql = """SELECT f.year, b.category,
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
                 ORDER BY f.year, b.category"""
        with connection.cursor() as c:
            c.execute(sql)
            return dictfetchall(c)

    @staticmethod
    def iit_preference_ranking() -> list:
        sql = """WITH r AS (
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
                 ORDER BY median_close ASC"""
        with connection.cursor() as c:
            c.execute(sql)
            return dictfetchall(c)

    @staticmethod
    def old_vs_new_trend() -> list:
        """Average OPEN closing rank for OLD vs NEW generation IITs, year over year.
        Surfaces the prestige gap between the original 8 IITs and the newer ones."""
        sql = """SELECT f.year, i.generation,
                        ROUND(AVG(f.closing_rank)) AS avg_close,
                        COUNT(*) AS samples
                 FROM fact_allotment f
                 JOIN dim_iit i       ON f.iit_id = i.iit_id
                 JOIN dim_seat_type s ON f.seat_type_id = s.seat_type_id
                 JOIN dim_gender g    ON f.gender_id = g.gender_id
                 WHERE s.seat_type_code = 'OPEN'
                   AND g.gender_code IN ('Gender-Neutral', 'NULL')
                   AND f.is_preparatory = 0
                   AND f.round_no = (SELECT MAX(round_no) FROM fact_allotment x WHERE x.year = f.year)
                 GROUP BY f.year, i.generation
                 ORDER BY f.year, i.generation"""
        with connection.cursor() as c:
            c.execute(sql)
            return dictfetchall(c)

    @staticmethod
    def branch_vs_iit_tradeoff() -> list:
        sql = """SELECT i.generation,
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
                 ORDER BY i.generation, branch_tier"""
        with connection.cursor() as c:
            c.execute(sql)
            return dictfetchall(c)

    @staticmethod
    def category_cutoff_gaps() -> list:
        sql = """SELECT i.iit_name, b.branch_name, s.seat_type_code,
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
                 ORDER BY i.iit_name, b.branch_name, s.seat_type_code"""
        with connection.cursor() as c:
            c.execute(sql)
            return dictfetchall(c)

    @staticmethod
    def gender_gap_trend() -> list:
        """CSE average closing rank: Gender-Neutral vs Female-only (supernumerary),
        year over year. Shows the impact of the female-supernumerary scheme."""
        sql = """SELECT f.year, g.gender_code,
                        ROUND(AVG(f.closing_rank)) AS avg_close,
                        COUNT(*) AS samples
                 FROM fact_allotment f
                 JOIN dim_branch b    ON f.branch_id = b.branch_id
                 JOIN dim_seat_type s ON f.seat_type_id = s.seat_type_id
                 JOIN dim_gender g    ON f.gender_id = g.gender_id
                 WHERE b.category = 'cse_family'
                   AND s.seat_type_code = 'OPEN'
                   AND g.gender_code IN ('Gender-Neutral', 'Female-only (including Supernumerary)')
                   AND f.is_preparatory = 0
                   AND f.round_no = (SELECT MAX(round_no) FROM fact_allotment x WHERE x.year = f.year)
                 GROUP BY f.year, g.gender_code
                 ORDER BY f.year, g.gender_code"""
        with connection.cursor() as c:
            c.execute(sql)
            return dictfetchall(c)

    @staticmethod
    def new_age_growth() -> list:
        """Number of distinct new-age program offerings (AI / Data Science / Maths &
        Computing) across IITs per year. Tracks the rise of CS-adjacent branches."""
        sql = """SELECT f.year,
                        COUNT(DISTINCT CONCAT(f.iit_id, '-', f.branch_id)) AS offerings,
                        COUNT(DISTINCT f.iit_id) AS iits_offering
                 FROM fact_allotment f
                 JOIN dim_branch b    ON f.branch_id = b.branch_id
                 JOIN dim_seat_type s ON f.seat_type_id = s.seat_type_id
                 WHERE b.category = 'new_age'
                   AND s.seat_type_code = 'OPEN'
                   AND f.is_preparatory = 0
                   AND f.round_no = (SELECT MAX(round_no) FROM fact_allotment x WHERE x.year = f.year)
                 GROUP BY f.year
                 ORDER BY f.year"""
        with connection.cursor() as c:
            c.execute(sql)
            return dictfetchall(c)
