from django.db import connection


def _dictfetchall(cursor):
    cols = [col[0] for col in cursor.description]
    return [dict(zip(cols, row)) for row in cursor.fetchall()]


def _dictfetchone(cursor):
    row = cursor.fetchone()
    if row is None:
        return None
    cols = [col[0] for col in cursor.description]
    return dict(zip(cols, row))


class PredictorService:

    # =========================================================================
    #  MODE A: predict by rank
    # =========================================================================
    def predict_by_rank(
        self,
        user_rank: int,
        seat_type: str = 'OPEN',
        gender: str = 'Gender-Neutral',
        min_year: int = None,
    ) -> dict:
        min_year = min_year or 2019

        sql = """
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
            WHERE s.seat_type_code = %s
              AND g.gender_code = %s
              AND f.is_preparatory = 0
              AND f.year >= %s
              AND f.round_no = (
                  SELECT MAX(round_no) FROM fact_allotment x WHERE x.year = f.year
              )
            GROUP BY i.iit_id, b.branch_id
            HAVING samples >= 2
            ORDER BY avg_close ASC
        """

        with connection.cursor() as c:
            c.execute(sql, [seat_type, gender, min_year])
            rows = _dictfetchall(c)

        annotated = []
        for row in rows:
            latest = int(row['latest_close'] or 0)
            mean = int(row['avg_close'] or 0)
            std = max(int(row['std_close'] or 0), 50)
            threshold = int(round(0.6 * latest + 0.4 * mean))
            z = (threshold - user_rank) / std
            bucket, score = self._bucketize(z)

            annotated.append({
                **row,
                'threshold': threshold,
                'z_score': round(z, 2),
                'chance': bucket,
                'chance_score': score,
                'reasoning': self._reasoning_for(user_rank, threshold, std, bucket),
            })

        # Sort by closest threshold to user rank (target schools first)
        annotated.sort(key=lambda r: abs(r['threshold'] - user_rank))

        # Filter out Very Low
        filtered = [r for r in annotated if r['chance_score'] > 1]

        return {
            'user_rank': user_rank,
            'seat_type': seat_type,
            'gender': gender,
            'total_options': len(filtered),
            'options': filtered,
        }

    # =========================================================================
    #  MODE B: predict for specific preference
    # =========================================================================
    def predict_for_preference(
        self,
        user_rank: int,
        iit_id: int,
        branch_id: int,
        seat_type: str = 'OPEN',
        gender: str = 'Gender-Neutral',
    ) -> dict:
        primary = self._stats_for_combo(iit_id, branch_id, seat_type, gender)

        if not primary:
            return {
                'found': False,
                'message': 'No historical data found for this exact combination.',
                'suggestions': self._fallback_suggestions(user_rank, iit_id, branch_id, seat_type, gender),
            }

        std = max(int(primary['std_close'] or 0), 50)
        threshold = int(round(0.6 * int(primary['latest_close']) + 0.4 * int(primary['avg_close'])))
        z = (threshold - user_rank) / std
        bucket, score = self._bucketize(z)

        primary = {
            **primary,
            'threshold': threshold,
            'z_score': round(z, 2),
            'chance': bucket,
            'chance_score': score,
            'reasoning': self._reasoning_for(user_rank, threshold, std, bucket),
        }

        return {
            'found': True,
            'user_rank': user_rank,
            'primary': primary,
            'yearly_trend': self._yearly_trend_for_combo(iit_id, branch_id, seat_type, gender),
            'same_branch_other_iits': self._same_branch_other_iits(user_rank, iit_id, branch_id, seat_type, gender),
            'same_iit_other_branches': self._same_iit_other_branches(user_rank, iit_id, branch_id, seat_type, gender),
        }

    # =========================================================================
    #  Dropdown options
    # =========================================================================
    def get_dropdown_options(self) -> dict:
        with connection.cursor() as c:
            c.execute("SELECT iit_id AS id, iit_name AS label, short_code FROM dim_iit ORDER BY iit_name")
            cols = [col[0] for col in c.description]
            iits = [dict(zip(cols, row)) for row in c.fetchall()]

            c.execute("SELECT branch_id AS id, branch_name AS label FROM dim_branch ORDER BY branch_name")
            cols = [col[0] for col in c.description]
            branches = [dict(zip(cols, row)) for row in c.fetchall()]

            c.execute("SELECT seat_type_code AS value FROM dim_seat_type ORDER BY seat_type_code")
            seat_types = [row[0] for row in c.fetchall()]

            c.execute("SELECT gender_code AS value FROM dim_gender ORDER BY gender_code")
            genders = [row[0] for row in c.fetchall()]

        return {
            'iits': iits,
            'branches': branches,
            'seat_types': seat_types,
            'genders': genders,
        }

    # =========================================================================
    #  Private helpers
    # =========================================================================
    def _stats_for_combo(self, iit_id, branch_id, seat_type, gender):
        sql = """
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
            WHERE i.iit_id = %s
              AND b.branch_id = %s
              AND s.seat_type_code = %s
              AND g.gender_code = %s
              AND f.is_preparatory = 0
              AND f.round_no = (
                  SELECT MAX(round_no) FROM fact_allotment x WHERE x.year = f.year
              )
            GROUP BY i.iit_id, b.branch_id
        """
        with connection.cursor() as c:
            c.execute(sql, [iit_id, branch_id, seat_type, gender])
            return _dictfetchone(c)

    def _yearly_trend_for_combo(self, iit_id, branch_id, seat_type, gender):
        sql = """
            SELECT f.year, MIN(f.opening_rank) AS opening, MAX(f.closing_rank) AS closing
            FROM fact_allotment f
            JOIN dim_seat_type s ON f.seat_type_id = s.seat_type_id
            JOIN dim_gender g    ON f.gender_id = g.gender_id
            WHERE f.iit_id = %s AND f.branch_id = %s
              AND s.seat_type_code = %s AND g.gender_code = %s
              AND f.is_preparatory = 0
            GROUP BY f.year ORDER BY f.year
        """
        with connection.cursor() as c:
            c.execute(sql, [iit_id, branch_id, seat_type, gender])
            return _dictfetchall(c)

    def _same_branch_other_iits(self, user_rank, iit_id, branch_id, seat_type, gender):
        similar_ids = self._get_similar_branch_ids(branch_id)
        placeholders = ', '.join(['%s'] * len(similar_ids))
        sql = f"""
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
            WHERE b.branch_id IN ({placeholders})
              AND i.iit_id != %s
              AND s.seat_type_code = %s AND g.gender_code = %s
              AND f.is_preparatory = 0 AND f.year >= 2019
              AND f.round_no = (SELECT MAX(round_no) FROM fact_allotment x WHERE x.year = f.year)
            GROUP BY i.iit_id, b.branch_id
            HAVING samples >= 2
            ORDER BY avg_close ASC
        """
        with connection.cursor() as c:
            c.execute(sql, [*similar_ids, iit_id, seat_type, gender])
            rows = _dictfetchall(c)
        return self._annotate_and_rank(rows, user_rank)

    def _same_iit_other_branches(self, user_rank, iit_id, branch_id, seat_type, gender):
        sql = """
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
            WHERE i.iit_id = %s AND b.branch_id != %s
              AND s.seat_type_code = %s AND g.gender_code = %s
              AND f.is_preparatory = 0 AND f.year >= 2019
              AND f.round_no = (SELECT MAX(round_no) FROM fact_allotment x WHERE x.year = f.year)
            GROUP BY b.branch_id HAVING samples >= 2 ORDER BY avg_close ASC
        """
        with connection.cursor() as c:
            c.execute(sql, [iit_id, branch_id, seat_type, gender])
            rows = _dictfetchall(c)
        return self._annotate_and_rank(rows, user_rank)

    def _fallback_suggestions(self, user_rank, iit_id, branch_id, seat_type, gender):
        return {
            'same_branch_other_iits': self._same_branch_other_iits(user_rank, iit_id, branch_id, seat_type, gender),
            'same_iit_other_branches': self._same_iit_other_branches(user_rank, iit_id, branch_id, seat_type, gender),
        }

    def _get_similar_branch_ids(self, branch_id: int) -> list:
        with connection.cursor() as c:
            c.execute("SELECT branch_name FROM dim_branch WHERE branch_id = %s", [branch_id])
            row = c.fetchone()
        if not row:
            return [branch_id]

        target_name = (row[0] or '').lower()
        target_key = self._branch_bucket_key(target_name)

        with connection.cursor() as c:
            c.execute("SELECT branch_id, branch_name FROM dim_branch")
            all_branches = c.fetchall()

        similar = [b[0] for b in all_branches if self._branch_bucket_key(b[1].lower()) == target_key]
        return similar if similar else [branch_id]

    @staticmethod
    def _branch_bucket_key(name: str) -> str:
        if 'computer science' in name or '(cse)' in name: return 'cse'
        if any(x in name for x in ['artificial intelligence', 'data science', 'data analytics', 'data engineering']): return 'ai_data'
        if any(x in name for x in ['mathematics & computing', 'mathematics and computing', 'mathematics and scientific computing']): return 'mnc'
        if 'mathematics' in name: return 'math'
        if any(x in name for x in ['electronics and communication', 'electronics and electrical communication', '(ece)']): return 'ece'
        if 'electrical' in name or '(eee)' in name: return 'ee'
        if any(x in name for x in ['electronics', 'vlsi', 'microelectronics', 'integrated circuit']): return 'electronics_other'
        if any(x in name for x in ['mechanical', 'mechatronics', '(me)']): return 'mech'
        if any(x in name for x in ['civil', 'structural', 'infrastructure']): return 'civil'
        if any(x in name for x in ['chemical engineer', 'chemical and biochemical', 'chemical science']): return 'chem_eng'
        if 'chemistry' in name or 'chemical sciences' in name: return 'chemistry'
        if 'aerospace' in name or 'aeronautical' in name: return 'aero'
        if 'bio' in name and any(x in name for x in ['technology', 'engineer', 'informatic', 'science']): return 'bio'
        if 'physics' in name or 'engineering physics' in name: return 'physics'
        if any(x in name for x in ['metallurg', 'materials', 'ceramic', 'polymer']): return 'metal_mat'
        if 'mining' in name or 'mineral' in name: return 'mining'
        if 'textile' in name: return 'textile'
        if any(x in name for x in ['production', 'industrial', 'manufacturing', 'quality']): return 'production_industrial'
        if 'architecture' in name: return 'arch'
        if 'ocean' in name or 'naval' in name: return 'ocean'
        if 'pharmaceutic' in name: return 'pharma'
        if any(x in name for x in ['geolog', 'geophysic', 'earth science', 'petroleum']): return 'geo'
        if 'economics' in name: return 'eco'
        if 'energy' in name: return 'energy'
        if 'instrumentation' in name: return 'instrumentation'
        return name

    def _annotate_and_rank(self, rows: list, user_rank: int) -> list:
        out = []
        for row in rows:
            latest = int(row['latest_close'] or 0)
            mean = int(row['avg_close'] or 0)
            std = max(int(row['std_close'] or 0), 50)
            threshold = int(round(0.6 * latest + 0.4 * mean))
            z = (threshold - user_rank) / std
            bucket, score = self._bucketize(z)
            out.append({**row, 'threshold': threshold, 'z_score': round(z, 2), 'chance': bucket, 'chance_score': score})

        out = [r for r in out if r['chance_score'] > 1]
        out.sort(key=lambda r: abs(r['threshold'] - user_rank))
        return out[:12]

    @staticmethod
    def _bucketize(z: float) -> tuple:
        if z >= 1.5:  return ('Very High', 5)
        if z >= 0.5:  return ('High', 4)
        if z >= -0.5: return ('Moderate', 3)
        if z >= -1.5: return ('Low', 2)
        return ('Very Low', 1)

    @staticmethod
    def _reasoning_for(user_rank: int, threshold: int, std: int, bucket: str) -> str:
        diff = threshold - user_rank
        if diff > 0:
            return f'Your rank ({user_rank}) is {diff} positions better than the predicted rank ({threshold}). Volatility ±{std}.'
        return f'Your rank ({user_rank}) is {abs(diff)} positions worse than the predicted rank ({threshold}). Volatility ±{std}.'
