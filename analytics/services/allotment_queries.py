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
    def toughest_branches(limit: int = 10) -> list:
        limit = max(1, min(int(limit), 50))
        sql = f"""WITH ranked AS (
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
                  LIMIT {limit}"""
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
    def round_wise_drop() -> list:
        sql = """WITH final_rounds AS (
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
                       ON r1.year = fr.year AND r1.round_no = 1
                     JOIN fact_allotment rf
                       ON rf.year = r1.year
                      AND rf.iit_id = r1.iit_id AND rf.branch_id = r1.branch_id
                      AND rf.quota_id = r1.quota_id AND rf.seat_type_id = r1.seat_type_id
                      AND rf.gender_id = r1.gender_id AND rf.round_no = fr.final_round
                     JOIN dim_seat_type s ON r1.seat_type_id = s.seat_type_id
                     JOIN dim_gender g    ON r1.gender_id = g.gender_id
                     WHERE s.seat_type_code = 'OPEN'
                       AND g.gender_code IN ('Gender-Neutral', 'NULL')
                       AND r1.is_preparatory = 0 AND rf.is_preparatory = 0
                     GROUP BY r1.year
                 ),
                 final_round_spread AS (
                     SELECT f.year,
                            ROUND(AVG(CAST(f.closing_rank AS SIGNED) - CAST(f.opening_rank AS SIGNED))) AS avg_rank_inflation,
                            COUNT(*) AS paired_samples,
                            'final_opening_to_closing' AS metric_basis
                     FROM final_rounds fr
                     JOIN fact_allotment f ON f.year = fr.year AND f.round_no = fr.final_round
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
                 ORDER BY year"""
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
    def highest_volatility(limit: int = 15) -> list:
        limit = max(1, min(int(limit), 50))
        sql = f"""SELECT i.iit_name, b.branch_name,
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
                  LIMIT {limit}"""
        with connection.cursor() as c:
            c.execute(sql)
            return dictfetchall(c)

    @staticmethod
    def top100_monopoly() -> list:
        sql = """SELECT i.iit_name, f.year, COUNT(*) AS top100_seats
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
                 ORDER BY f.year, top100_seats DESC"""
        with connection.cursor() as c:
            c.execute(sql)
            return dictfetchall(c)
