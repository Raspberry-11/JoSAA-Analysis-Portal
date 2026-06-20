"""
Security test suite for the LLM SQL-injection defense layer (SqlValidator).

The AI Analyst lets a language model generate SQL, so the model's output is
treated as UNTRUSTED INPUT. Every generated query must pass through
`SqlValidator.validate_and_prepare()` before it is allowed near the database.

This suite validates that defense against 25 distinct attack vectors, plus 7
"must still work" cases proving the validator does not over-block legitimate
analytical queries.

These are pure unit tests — the validator only does string/regex analysis and
never touches the database — so they can be run without a live MySQL instance:

    python -m unittest analytics.tests -v
    # or
    python manage.py test analytics
"""
import unittest

from analytics.services.sql_validator import SqlValidator


class SqlInjectionDefenseTests(unittest.TestCase):
    """25 attack vectors the validator must reject."""

    # ── Group 1: DML / DDL mutation (data tampering & destruction) ──────────
    def test_01_drop_table(self):
        with self.assertRaises(ValueError):
            SqlValidator.validate_and_prepare("DROP TABLE fact_allotment")

    def test_02_delete_rows(self):
        with self.assertRaises(ValueError):
            SqlValidator.validate_and_prepare("DELETE FROM fact_allotment")

    def test_03_update_rows(self):
        with self.assertRaises(ValueError):
            SqlValidator.validate_and_prepare(
                "UPDATE fact_allotment SET closing_rank = 1"
            )

    def test_04_insert_rows(self):
        with self.assertRaises(ValueError):
            SqlValidator.validate_and_prepare(
                "INSERT INTO fact_allotment (year) VALUES (2099)"
            )

    def test_05_truncate_table(self):
        with self.assertRaises(ValueError):
            SqlValidator.validate_and_prepare("TRUNCATE TABLE fact_allotment")

    def test_06_alter_table(self):
        with self.assertRaises(ValueError):
            SqlValidator.validate_and_prepare(
                "ALTER TABLE fact_allotment ADD COLUMN hacked INT"
            )

    def test_07_replace_into(self):
        with self.assertRaises(ValueError):
            SqlValidator.validate_and_prepare(
                "REPLACE INTO fact_allotment (year) VALUES (2099)"
            )

    # ── Group 2: Stacked / multi-statement queries ─────────────────────────
    def test_08_stacked_drop_after_select(self):
        with self.assertRaises(ValueError):
            SqlValidator.validate_and_prepare(
                "SELECT 1; DROP TABLE fact_allotment"
            )

    def test_09_stacked_two_selects(self):
        with self.assertRaises(ValueError):
            SqlValidator.validate_and_prepare(
                "SELECT * FROM dim_iit; SELECT * FROM dim_branch"
            )

    def test_10_stacked_mid_statement_semicolon(self):
        with self.assertRaises(ValueError):
            SqlValidator.validate_and_prepare(
                "SELECT * FROM fact_allotment WHERE id = 1 ;DELETE FROM dim_iit"
            )

    # ── Group 3: Unauthorized table access (schema / credential theft) ──────
    def test_11_information_schema(self):
        with self.assertRaises(ValueError):
            SqlValidator.validate_and_prepare(
                "SELECT table_name FROM information_schema.tables"
            )

    def test_12_mysql_user_table(self):
        with self.assertRaises(ValueError):
            SqlValidator.validate_and_prepare(
                "SELECT user, authentication_string FROM mysql.user"
            )

    def test_13_union_to_credential_table(self):
        with self.assertRaises(ValueError):
            SqlValidator.validate_and_prepare(
                "SELECT iit_name FROM dim_iit "
                "UNION SELECT authentication_string FROM mysql.user"
            )

    def test_14_subquery_to_information_schema(self):
        with self.assertRaises(ValueError):
            SqlValidator.validate_and_prepare(
                "SELECT * FROM fact_allotment WHERE iit_id IN "
                "(SELECT table_rows FROM information_schema.tables)"
            )

    def test_15_arbitrary_unknown_table(self):
        with self.assertRaises(ValueError):
            SqlValidator.validate_and_prepare("SELECT * FROM users")

    # ── Group 4: Time-based blind injection & denial of service ────────────
    def test_16_sleep_time_based(self):
        with self.assertRaises(ValueError):
            SqlValidator.validate_and_prepare(
                "SELECT * FROM fact_allotment WHERE SLEEP(5)"
            )

    def test_17_benchmark_dos(self):
        with self.assertRaises(ValueError):
            SqlValidator.validate_and_prepare(
                "SELECT BENCHMARK(100000000, MD5('x'))"
            )

    # ── Group 5: File-system read / write (server compromise) ──────────────
    def test_18_into_outfile(self):
        with self.assertRaises(ValueError):
            SqlValidator.validate_and_prepare(
                "SELECT * FROM fact_allotment INTO OUTFILE '/tmp/leak.txt'"
            )

    def test_19_load_file(self):
        with self.assertRaises(ValueError):
            SqlValidator.validate_and_prepare(
                "SELECT LOAD_FILE('/etc/passwd')"
            )

    def test_20_load_data_infile(self):
        with self.assertRaises(ValueError):
            SqlValidator.validate_and_prepare(
                "LOAD DATA INFILE '/etc/passwd' INTO TABLE fact_allotment"
            )

    # ── Group 6: Privilege / procedural / session abuse ────────────────────
    def test_21_grant_privileges(self):
        with self.assertRaises(ValueError):
            SqlValidator.validate_and_prepare(
                "GRANT ALL PRIVILEGES ON *.* TO 'attacker'@'%'"
            )

    def test_22_set_global_variable(self):
        with self.assertRaises(ValueError):
            SqlValidator.validate_and_prepare(
                "SET GLOBAL general_log = 'ON'"
            )

    def test_23_call_stored_procedure(self):
        with self.assertRaises(ValueError):
            SqlValidator.validate_and_prepare("CALL sys.execute_prepared('x')")

    def test_24_prepare_statement(self):
        with self.assertRaises(ValueError):
            SqlValidator.validate_and_prepare(
                "PREPARE evil FROM 'SELECT * FROM mysql.user'"
            )

    # ── Group 7: Schema reconnaissance ─────────────────────────────────────
    def test_25_show_and_describe(self):
        with self.assertRaises(ValueError):
            SqlValidator.validate_and_prepare("SHOW TABLES")
        with self.assertRaises(ValueError):
            SqlValidator.validate_and_prepare("DESCRIBE fact_allotment")

    # ── Edge case: empty input ─────────────────────────────────────────────
    def test_26_empty_query(self):
        with self.assertRaises(ValueError):
            SqlValidator.validate_and_prepare("   ")


class LegitimateQueryTests(unittest.TestCase):
    """The validator must NOT over-block valid analytical SELECTs."""

    def test_simple_select_gets_limit_appended(self):
        out = SqlValidator.validate_and_prepare(
            "SELECT closing_rank FROM fact_allotment"
        )
        self.assertIn("LIMIT 1000", out)

    def test_join_across_allowed_tables_passes(self):
        sql = (
            "SELECT i.iit_name, AVG(f.closing_rank) FROM fact_allotment f "
            "JOIN dim_iit i ON f.iit_id = i.iit_id GROUP BY i.iit_name"
        )
        out = SqlValidator.validate_and_prepare(sql)
        self.assertIn("dim_iit", out)

    def test_cte_alias_not_flagged_as_table(self):
        # CTE-aware whitelisting: 'cse' is a CTE name, not a real table.
        sql = (
            "WITH cse AS (SELECT closing_rank FROM fact_allotment) "
            "SELECT AVG(closing_rank) FROM cse"
        )
        out = SqlValidator.validate_and_prepare(sql)
        self.assertIn("WITH", out)

    def test_quoted_semicolon_is_allowed(self):
        # Quote-aware stacked-query detection: a ';' inside a string literal
        # is NOT a statement separator.
        sql = "SELECT * FROM fact_allotment WHERE iit_id = 1 AND '1;2' = '1;2'"
        out = SqlValidator.validate_and_prepare(sql)
        self.assertIn("fact_allotment", out)

    def test_oversized_limit_is_capped(self):
        out = SqlValidator.validate_and_prepare(
            "SELECT * FROM fact_allotment LIMIT 999999"
        )
        self.assertIn("LIMIT 1000", out)
        self.assertNotIn("999999", out)

    def test_case_insensitive_lowercase_select(self):
        out = SqlValidator.validate_and_prepare("select * from dim_branch")
        self.assertIn("LIMIT 1000", out)

    def test_trailing_semicolon_is_stripped_not_rejected(self):
        out = SqlValidator.validate_and_prepare(
            "SELECT closing_rank FROM fact_allotment;"
        )
        self.assertIn("LIMIT 1000", out)


if __name__ == "__main__":
    unittest.main(verbosity=2)
