"""
JOSAA CSV importer — Django management command.

Loads a JoSAA seat-allotment CSV into the star schema. (This is the ETL step
that follows the Selenium scrape; the scraper itself is maintained separately.)

What it does:
  - keeps IITs only (drops NITs/IIITs/GFTIs)
  - normalizes preparatory ranks ("1234P") and flags them via is_preparatory
  - pre-classifies branch category (cse_family/new_age/core/other) and IIT
    generation (old/new) at load time, so analytical queries filter on clean
    enums instead of pattern-matching free text
  - caches dimension lookups in memory to avoid per-row round-trips
  - batches fact inserts inside a single transaction
  - keeps EVERY round by default (use --final-round-only to keep just the final round per year)

Usage:
    python manage.py import_csv [path/to/file.csv] [--batch-size 1000] [--final-round-only]

Expected CSV columns (case-insensitive):
    id, iit, branch, quota, seat_type, gender, or, cr, year, round
"""
import csv
import re
from pathlib import Path

from django.conf import settings
from django.core.management.base import BaseCommand, CommandError
from django.db import connection, transaction

OLD_IITS = {
    'Indian Institute of Technology Bombay',
    'Indian Institute of Technology Delhi',
    'Indian Institute of Technology Kanpur',
    'Indian Institute of Technology Kharagpur',
    'Indian Institute of Technology Madras',
    'Indian Institute of Technology Roorkee',
    'Indian Institute of Technology (BHU) Varanasi',
    'Indian Institute of Technology Guwahati',
}
CSE_KEYWORDS = ['Computer Science']
NEW_AGE_KEYWORDS = [
    'Artificial Intelligence', 'Data Science',
    'Mathematics and Computing', 'Mathematics & Computing', 'AI and Data',
]
CORE_KEYWORDS = ['Mechanical', 'Civil', 'Chemical', 'Metallurgical']

REQUIRED_COLUMNS = ['iit', 'branch', 'quota', 'seat_type', 'gender', 'or', 'cr', 'year', 'round']


class Command(BaseCommand):
    help = 'Import a JoSAA seat-allotment CSV into the star schema.'

    def add_arguments(self, parser):
        parser.add_argument(
            'csv_path', nargs='?',
            default=str(Path(settings.BASE_DIR) / 'data' / 'josaa_2016_2022.csv'),
            help='Path to the JoSAA CSV (default: data/josaa_2016_2022.csv)',
        )
        parser.add_argument('--batch-size', type=int, default=1000,
                            help='Rows per fact INSERT (default: 1000)')
        parser.add_argument('--final-round-only', action='store_true',
                            help='Keep only the final round per year (default: keep every round)')

    def handle(self, *args, **opts):
        importer = JosaaImporter(
            self.stdout, self.style,
            batch_size=opts['batch_size'],
            final_round_only=opts['final_round_only'],
        )
        importer.run(opts['csv_path'])


class JosaaImporter:
    def __init__(self, stdout, style, batch_size=1000, final_round_only=False):
        self.out = stdout
        self.style = style
        self.batch_size = batch_size
        self.final_round_only = final_round_only
        self._iit, self._branch = {}, {}
        self._quota, self._seat, self._gender = {}, {}, {}

    def run(self, csv_path):
        path = Path(csv_path)
        if not path.is_file():
            raise CommandError(f'CSV not found: {csv_path}')

        with path.open(newline='', encoding='utf-8') as fh, transaction.atomic():
            reader = csv.reader(fh)
            try:
                header = next(reader)
            except StopIteration:
                raise CommandError('CSV appears to be empty')

            col = {h.strip().lower(): i for i, h in enumerate(header)}
            missing = [c for c in REQUIRED_COLUMNS if c not in col]
            if missing:
                raise CommandError(f'Missing required column(s): {", ".join(missing)}')

            batch, inserted, skipped = [], 0, 0
            for row in reader:
                if not row:
                    continue

                iit_name = row[col['iit']].strip()
                if 'indian institute of technology' not in iit_name.lower():
                    skipped += 1
                    continue

                open_rank, open_prep = self._norm_rank(row[col['or']])
                close_rank, close_prep = self._norm_rank(row[col['cr']])
                if open_rank == 0 or close_rank == 0:
                    skipped += 1
                    continue

                batch.append((
                    self._get_iit(iit_name),
                    self._get_branch(row[col['branch']].strip()),
                    self._get_dim('dim_quota', 'quota_code', 'quota_id', row[col['quota']].strip(), self._quota),
                    self._get_dim('dim_seat_type', 'seat_type_code', 'seat_type_id', row[col['seat_type']].strip(), self._seat),
                    self._get_dim('dim_gender', 'gender_code', 'gender_id', row[col['gender']].strip(), self._gender),
                    int(row[col['year']]),
                    int(row[col['round']]),
                    open_rank, close_rank,
                    1 if (open_prep or close_prep) else 0,
                ))

                if len(batch) >= self.batch_size:
                    self._flush(batch)
                    inserted += len(batch)
                    batch = []
                    self.out.write(f'Inserted {inserted} rows...')

            if batch:
                self._flush(batch)
                inserted += len(batch)

            if self.final_round_only:
                self._delete_non_final_rounds()

        self.out.write(self.style.SUCCESS(
            f'\nImport complete. Rows inserted: {inserted}, rows skipped: {skipped}'
        ))

    # ── rank normalization ───────────────────────────────────────────────
    @staticmethod
    def _norm_rank(raw):
        """Return (int rank, bool is_preparatory). A 'P' suffix marks a prep rank."""
        raw = (raw or '').strip()
        prep = bool(re.search('P', raw, re.IGNORECASE))
        digits = re.sub(r'[^0-9]', '', raw)
        return (int(digits) if digits else 0, prep)

    # ── dimension lookups (memoized) ─────────────────────────────────────
    def _get_iit(self, name):
        if name in self._iit:
            return self._iit[name]
        with connection.cursor() as c:
            c.execute('SELECT iit_id FROM dim_iit WHERE iit_name = %s', [name])
            row = c.fetchone()
            if row:
                iid = row[0]
            else:
                generation = 'old' if name in OLD_IITS else 'new'
                short = re.sub(r'\s+', ' ', re.sub(r'Indian Institute of Technology', 'IIT', name, flags=re.IGNORECASE)).strip()
                c.execute('INSERT INTO dim_iit (iit_name, short_code, generation) VALUES (%s, %s, %s)',
                          [name, short, generation])
                iid = c.lastrowid
        self._iit[name] = iid
        return iid

    def _get_branch(self, name):
        if name in self._branch:
            return self._branch[name]
        with connection.cursor() as c:
            c.execute('SELECT branch_id FROM dim_branch WHERE branch_name = %s', [name])
            row = c.fetchone()
            if row:
                bid = row[0]
            else:
                c.execute('INSERT INTO dim_branch (branch_name, category) VALUES (%s, %s)',
                          [name, self._classify(name)])
                bid = c.lastrowid
        self._branch[name] = bid
        return bid

    @staticmethod
    def _classify(name):
        low = name.lower()
        if any(k.lower() in low for k in CSE_KEYWORDS):
            return 'cse_family'
        if any(k.lower() in low for k in NEW_AGE_KEYWORDS):
            return 'new_age'
        if any(k.lower() in low for k in CORE_KEYWORDS):
            return 'core'
        return 'other'

    def _get_dim(self, table, col, id_col, val, cache):
        # table/col/id_col are internal constants (never user input) — safe to interpolate.
        if val in cache:
            return cache[val]
        with connection.cursor() as c:
            c.execute(f'SELECT {id_col} FROM {table} WHERE {col} = %s', [val])
            row = c.fetchone()
            if row:
                did = row[0]
            else:
                c.execute(f'INSERT INTO {table} ({col}) VALUES (%s)', [val])
                did = c.lastrowid
        cache[val] = did
        return did

    # ── bulk fact insert ─────────────────────────────────────────────────
    @staticmethod
    def _flush(batch):
        placeholders = ','.join(['(%s,%s,%s,%s,%s,%s,%s,%s,%s,%s)'] * len(batch))
        sql = (
            'INSERT INTO fact_allotment '
            '(iit_id, branch_id, quota_id, seat_type_id, gender_id, '
            'year, round_no, opening_rank, closing_rank, is_preparatory) '
            f'VALUES {placeholders}'
        )
        flat = [v for rec in batch for v in rec]
        with connection.cursor() as c:
            c.execute(sql, flat)

    @staticmethod
    def _delete_non_final_rounds():
        with connection.cursor() as c:
            c.execute(
                """
                DELETE f1 FROM fact_allotment f1
                LEFT JOIN (
                    SELECT year, MAX(round_no) AS max_round
                    FROM fact_allotment
                    GROUP BY year
                ) f2 ON f1.year = f2.year AND f1.round_no = f2.max_round
                WHERE f2.max_round IS NULL
                """
            )
