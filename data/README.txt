Place your JOSAA CSV file here, named:

    josaa_2016_2022.csv

Required columns (case-insensitive):
    id, iit, branch, quota, seat_type, gender, or, cr, year, round

Then load it into the database with:
    python manage.py import_csv data/josaa_2016_2022.csv

(Run scripts/schema.sql first to create the tables.)
