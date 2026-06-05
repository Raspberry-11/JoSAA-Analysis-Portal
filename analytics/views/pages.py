import json
from django.shortcuts import render
from django.db import connection


def _db_stats() -> dict:
    stats = {'rows': 0, 'iits': 0, 'branches': 0, 'years': 0}
    try:
        with connection.cursor() as c:
            c.execute('SELECT COUNT(*) FROM fact_allotment')
            stats['rows'] = c.fetchone()[0]
            c.execute('SELECT COUNT(*) FROM dim_iit')
            stats['iits'] = c.fetchone()[0]
            c.execute('SELECT COUNT(*) FROM dim_branch')
            stats['branches'] = c.fetchone()[0]
            c.execute('SELECT COUNT(DISTINCT year) FROM fact_allotment')
            stats['years'] = c.fetchone()[0]
    except Exception:
        pass
    return stats


def index(request):
    return render(request, 'index.html', {'stats': _db_stats(), 'active': 'home'})


def dashboard(request):
    return render(request, 'dashboard.html', {'active': 'dashboard'})


def ai_chat(request):
    return render(request, 'ai_chat.html', {'active': 'ai'})


def predictor(request):
    return render(request, 'predictor.html', {'active': 'predictor'})


def preference(request):
    iits = []
    branches_by_iit = {}
    try:
        with connection.cursor() as c:
            c.execute("SELECT iit_id, iit_name FROM dim_iit ORDER BY iit_name")
            iits = [{'iit_id': r[0], 'iit_name': r[1]} for r in c.fetchall()]

            c.execute("""
                SELECT DISTINCT db.branch_id, db.branch_name, fa.iit_id
                FROM dim_branch db
                JOIN fact_allotment fa ON fa.branch_id = db.branch_id
                ORDER BY db.branch_name
            """)
            for row in c.fetchall():
                iit_id = row[2]
                branches_by_iit.setdefault(iit_id, []).append({'id': row[0], 'name': row[1]})
    except Exception:
        pass

    return render(request, 'preference.html', {
        'active': 'preference',
        'iits': iits,
        'branches_by_iit_json': json.dumps(branches_by_iit),
    })
