import json


GLOSSARY = {
    'iitg':   'Indian Institute of Technology Guwahati (NOT Gandhinagar)',
    'iitgn':  'Indian Institute of Technology Gandhinagar',
    'iitb':   'Indian Institute of Technology Bombay',
    'iitd':   'Indian Institute of Technology Delhi',
    'iitm':   'Indian Institute of Technology Madras',
    'iitk':   'Indian Institute of Technology Kanpur',
    'iitkgp': 'Indian Institute of Technology Kharagpur',
    'iitr':   'Indian Institute of Technology Roorkee',
    'cs':     'Computer Science',
    'cse':    'Computer Science and Engineering',
    'ece':    'Electronics and Communication Engineering',
    'ee':     'Electrical Engineering',
    'me':     'Mechanical Engineering',
    'ce':     'Civil Engineering',
}

GOLDEN_QUERIES = [
    {
        'keywords': ['easiest', 'iit', 'cs', 'cse', 'computer science'],
        'q': 'Which IIT is easiest for CS?',
        'sql': "SELECT i.short_code AS iit, ROUND(AVG(f.closing_rank)) AS avg_closing_rank FROM fact_allotment f JOIN dim_iit i ON f.iit_id=i.iit_id JOIN dim_branch b ON f.branch_id=b.branch_id JOIN dim_seat_type s ON f.seat_type_id=s.seat_type_id JOIN dim_gender g ON f.gender_id=g.gender_id WHERE quota_id=(SELECT quota_id FROM dim_quota WHERE quota_code='AI') AND b.category='cse_family' AND s.seat_type_code='OPEN' AND (g.gender_code='Gender-Neutral' OR g.gender_code = 'NULL') AND (f.year, f.round_no) IN (SELECT year, MAX(round_no) FROM fact_allotment GROUP BY year) AND f.is_preparatory=0 GROUP BY i.iit_id ORDER BY avg_closing_rank DESC LIMIT 10",
        'answer': 'Here are the easiest IITs for Computer Science, based on the highest average closing ranks.',
        'response_type': 'chart',
        'chart': {'type': 'horizontalBar', 'x': 'iit', 'y': 'avg_closing_rank', 'title': 'Easiest IITs for CS'},
    },
    {
        'keywords': ['toughest', 'hardest', 'branch', 'iit'],
        'q': 'What are the top 10 toughest branches?',
        'sql': "SELECT CONCAT(i.short_code, ' · ', SUBSTRING_INDEX(b.branch_name, '(', 1)) AS iit_branch, ROUND(AVG(f.closing_rank)) AS avg_closing_rank FROM fact_allotment f JOIN dim_iit i ON f.iit_id=i.iit_id JOIN dim_branch b ON f.branch_id=b.branch_id JOIN dim_seat_type s ON f.seat_type_id=s.seat_type_id JOIN dim_gender g ON f.gender_id=g.gender_id WHERE quota_id=(SELECT quota_id FROM dim_quota WHERE quota_code='AI') AND s.seat_type_code='OPEN' AND (g.gender_code='Gender-Neutral' OR g.gender_code = 'NULL') AND (f.year, f.round_no) IN (SELECT year, MAX(round_no) FROM fact_allotment GROUP BY year) AND f.is_preparatory=0 GROUP BY i.iit_id, b.branch_id HAVING COUNT(*) >= 3 ORDER BY avg_closing_rank ASC LIMIT 10",
        'answer': 'These are the 10 toughest IIT-branch combinations to get into, ranked by lowest average closing rank.',
        'response_type': 'chart',
        'chart': {'type': 'horizontalBar', 'x': 'iit_branch', 'y': 'avg_closing_rank', 'title': 'Top 10 Toughest Branches'},
    },
    {
        'keywords': ['trend', 'year', 'closing rank', 'cse'],
        'q': 'Show me CSE closing ranks at IIT Bombay over the years',
        'sql': "SELECT f.year, ROUND(AVG(f.closing_rank)) AS avg_closing_rank FROM fact_allotment f JOIN dim_iit i ON f.iit_id=i.iit_id JOIN dim_branch b ON f.branch_id=b.branch_id JOIN dim_seat_type s ON f.seat_type_id=s.seat_type_id JOIN dim_gender g ON f.gender_id=g.gender_id WHERE quota_id=(SELECT quota_id FROM dim_quota WHERE quota_code='AI') AND i.iit_name='Indian Institute of Technology Bombay' AND b.category='cse_family' AND s.seat_type_code='OPEN' AND (g.gender_code='Gender-Neutral' OR g.gender_code = 'NULL') AND (f.year, f.round_no) IN (SELECT year, MAX(round_no) FROM fact_allotment GROUP BY year) AND f.is_preparatory=0 GROUP BY f.year ORDER BY f.year",
        'answer': 'CSE closing ranks at IIT Bombay have steadily tightened over the years.',
        'response_type': 'chart',
        'chart': {'type': 'line', 'x': 'year', 'y': 'avg_closing_rank', 'title': 'CSE Cutoffs over time (IIT Bombay)', 'y_reverse': True},
    },
    {
        'keywords': ['decrease', 'increase', 'difference', 'drop', 'change', 'between', 'biggest'],
        'q': 'Which branch at IIT Kanpur saw the biggest decrease in closing rank between 2017 and 2022?',
        'sql': "SELECT b.branch_name AS branch, early.cr AS closing_rank_early, later.cr AS closing_rank_later, (CAST(early.cr AS SIGNED) - CAST(later.cr AS SIGNED)) AS rank_drop FROM (SELECT f.branch_id, f.closing_rank AS cr FROM fact_allotment f JOIN dim_iit i ON f.iit_id=i.iit_id JOIN dim_seat_type s ON f.seat_type_id=s.seat_type_id JOIN dim_gender g ON f.gender_id=g.gender_id WHERE quota_id=(SELECT quota_id FROM dim_quota WHERE quota_code='AI') AND i.short_code='IIT Kanpur' AND f.year=2017 AND s.seat_type_code='OPEN' AND (g.gender_code='Gender-Neutral' OR g.gender_code = 'NULL') AND f.is_preparatory=0 AND f.round_no=(SELECT MAX(round_no) FROM fact_allotment WHERE year=2017)) early INNER JOIN (SELECT f.branch_id, f.closing_rank AS cr FROM fact_allotment f JOIN dim_iit i ON f.iit_id=i.iit_id JOIN dim_seat_type s ON f.seat_type_id=s.seat_type_id JOIN dim_gender g ON f.gender_id=g.gender_id WHERE quota_id=(SELECT quota_id FROM dim_quota WHERE quota_code='AI') AND i.short_code='IIT Kanpur' AND f.year=2022 AND s.seat_type_code='OPEN' AND (g.gender_code='Gender-Neutral' OR g.gender_code = 'NULL') AND f.is_preparatory=0 AND f.round_no=(SELECT MAX(round_no) FROM fact_allotment WHERE year=2022)) later ON early.branch_id = later.branch_id JOIN dim_branch b ON early.branch_id = b.branch_id ORDER BY rank_drop DESC LIMIT 1",
        'answer': 'The branch with the biggest decrease was {branch}, dropping from {closing_rank_early} to {closing_rank_later} — a change of {rank_drop} ranks.',
        'response_type': 'text',
    },
    {
        'keywords': ['compare', 'bombay', 'delhi', 'vs', 'comparison'],
        'q': 'Compare CSE closing ranks between IIT Bombay and IIT Delhi over the years',
        'sql': "SELECT f.year, i.short_code AS iit, ROUND(AVG(f.closing_rank)) AS avg_closing_rank FROM fact_allotment f JOIN dim_iit i ON f.iit_id=i.iit_id JOIN dim_branch b ON f.branch_id=b.branch_id JOIN dim_seat_type s ON f.seat_type_id=s.seat_type_id JOIN dim_gender g ON f.gender_id=g.gender_id WHERE quota_id=(SELECT quota_id FROM dim_quota WHERE quota_code='AI') AND i.short_code IN ('IIT Bombay','IIT Delhi') AND b.category='cse_family' AND s.seat_type_code='OPEN' AND (g.gender_code='Gender-Neutral' OR g.gender_code='NULL') AND (f.year, f.round_no) IN (SELECT year, MAX(round_no) FROM fact_allotment GROUP BY year) AND f.is_preparatory=0 GROUP BY f.year, i.iit_id ORDER BY f.year",
        'answer': 'CSE closing rank comparison between IIT Bombay and IIT Delhi over the years.',
        'response_type': 'chart',
        'chart': {'type': 'line', 'x': 'year', 'y': 'avg_closing_rank', 'series': 'iit', 'title': 'CSE: IIT Bombay vs IIT Delhi', 'y_reverse': True},
    },
    {
        'keywords': ['obc', 'ncl', 'category', 'sc', 'st', 'ews', 'reservation'],
        'q': 'Compare OBC-NCL vs OPEN closing ranks at IIT Bombay for CSE',
        'sql': "SELECT f.year, s.seat_type_code AS category, ROUND(AVG(f.closing_rank)) AS avg_closing_rank FROM fact_allotment f JOIN dim_iit i ON f.iit_id=i.iit_id JOIN dim_branch b ON f.branch_id=b.branch_id JOIN dim_seat_type s ON f.seat_type_id=s.seat_type_id JOIN dim_gender g ON f.gender_id=g.gender_id WHERE quota_id=(SELECT quota_id FROM dim_quota WHERE quota_code='AI') AND i.iit_name='Indian Institute of Technology Bombay' AND b.category='cse_family' AND s.seat_type_code IN ('OPEN','OBC-NCL','SC','ST','EWS') AND g.gender_code='Gender-Neutral' AND (f.year, f.round_no) IN (SELECT year, MAX(round_no) FROM fact_allotment GROUP BY year) AND f.is_preparatory=0 GROUP BY f.year, s.seat_type_id ORDER BY f.year, avg_closing_rank ASC",
        'answer': 'Category-wise closing rank comparison for CSE at IIT Bombay.',
        'response_type': 'chart',
        'chart': {'type': 'line', 'x': 'year', 'y': 'avg_closing_rank', 'series': 'category', 'title': 'CSE Cutoffs by Category (IIT Bombay)'},
    },
    {
        'keywords': ['female', 'girl', 'women', 'supernumerary', 'gender'],
        'q': 'How do female-only closing ranks compare to gender-neutral at IIT Bombay?',
        'sql': "SELECT f.year, g.gender_code AS gender, ROUND(AVG(f.closing_rank)) AS avg_closing_rank FROM fact_allotment f JOIN dim_iit i ON f.iit_id=i.iit_id JOIN dim_branch b ON f.branch_id=b.branch_id JOIN dim_seat_type s ON f.seat_type_id=s.seat_type_id JOIN dim_gender g ON f.gender_id=g.gender_id WHERE quota_id=(SELECT quota_id FROM dim_quota WHERE quota_code='AI') AND i.iit_name='Indian Institute of Technology Bombay' AND b.category='cse_family' AND s.seat_type_code='OPEN' AND (f.year, f.round_no) IN (SELECT year, MAX(round_no) FROM fact_allotment GROUP BY year) AND f.is_preparatory=0 GROUP BY f.year, g.gender_id ORDER BY f.year",
        'answer': 'Female-only seats have higher closing ranks (more relaxed cutoffs) than gender-neutral seats.',
        'response_type': 'chart',
        'chart': {'type': 'line', 'x': 'year', 'y': 'avg_closing_rank', 'series': 'gender', 'title': 'Gender-wise Closing Ranks (IIT Bombay CSE)', 'y_reverse': True},
    },
    {
        'keywords': ['iit', 'new', 'old', 'generation', 'newer'],
        'q': 'How do old IITs compare to new IITs in terms of closing ranks?',
        'sql': "SELECT f.year, i.generation, ROUND(AVG(f.closing_rank)) AS avg_closing_rank FROM fact_allotment f JOIN dim_iit i ON f.iit_id=i.iit_id JOIN dim_seat_type s ON f.seat_type_id=s.seat_type_id JOIN dim_gender g ON f.gender_id=g.gender_id WHERE quota_id=(SELECT quota_id FROM dim_quota WHERE quota_code='AI') AND s.seat_type_code='OPEN' AND (g.gender_code='Gender-Neutral' OR g.gender_code='NULL') AND (f.year, f.round_no) IN (SELECT year, MAX(round_no) FROM fact_allotment GROUP BY year) AND f.is_preparatory=0 GROUP BY f.year, i.generation ORDER BY f.year",
        'answer': 'Old IITs consistently have lower (more competitive) closing ranks than new IITs.',
        'response_type': 'chart',
        'chart': {'type': 'line', 'x': 'year', 'y': 'avg_closing_rank', 'series': 'generation', 'title': 'Old vs New IITs: Closing Rank Trend', 'y_reverse': True},
    },
    {
        'keywords': ['round', 'allotment', 'inflation', 'improve', 'change'],
        'q': 'How much do closing ranks improve from round 1 to the final round?',
        'sql': "SELECT r1.year, ROUND(AVG(CAST(rf.closing_rank AS SIGNED) - CAST(r1.closing_rank AS SIGNED))) AS avg_rank_change FROM fact_allotment r1 JOIN (SELECT year, MAX(round_no) AS final_round FROM fact_allotment GROUP BY year HAVING MAX(round_no) > 1) fr ON r1.year = fr.year AND r1.round_no = 1 JOIN fact_allotment rf ON rf.year = r1.year AND rf.iit_id = r1.iit_id AND rf.branch_id = r1.branch_id AND rf.quota_id = r1.quota_id AND rf.seat_type_id = r1.seat_type_id AND rf.gender_id = r1.gender_id AND rf.round_no = fr.final_round JOIN dim_seat_type s ON r1.seat_type_id = s.seat_type_id JOIN dim_gender g ON r1.gender_id = g.gender_id WHERE s.seat_type_code = 'OPEN' AND (g.gender_code = 'Gender-Neutral' OR g.gender_code = 'NULL') AND r1.is_preparatory = 0 AND rf.is_preparatory = 0 GROUP BY r1.year ORDER BY r1.year",
        'answer': 'On average, closing ranks increase (worsen) from round 1 to the final round, reflecting gradual vacancy filling.',
        'response_type': 'chart',
        'chart': {'type': 'bar', 'x': 'year', 'y': 'avg_rank_change', 'title': 'Rank Inflation: Round 1 to Final'},
    },
    {
        'keywords': ['top', 'rank', '100', 'air', 'monopoly', 'capture'],
        'q': 'Which IITs capture the most top 100 AIR students?',
        'sql': "SELECT i.short_code AS iit, COUNT(*) AS top100_seats FROM fact_allotment f JOIN dim_iit i ON f.iit_id=i.iit_id JOIN dim_seat_type s ON f.seat_type_id=s.seat_type_id JOIN dim_gender g ON f.gender_id=g.gender_id WHERE quota_id=(SELECT quota_id FROM dim_quota WHERE quota_code='AI') AND s.seat_type_code='OPEN' AND (g.gender_code='Gender-Neutral' OR g.gender_code='NULL') AND f.opening_rank <= 100 AND f.is_preparatory=0 AND (f.year, f.round_no) IN (SELECT year, MAX(round_no) FROM fact_allotment GROUP BY year) GROUP BY i.iit_id ORDER BY top100_seats DESC LIMIT 10",
        'answer': 'These IITs capture the most seats with opening ranks in the top 100 AIR.',
        'response_type': 'chart',
        'chart': {'type': 'horizontalBar', 'x': 'iit', 'y': 'top100_seats', 'title': 'IITs Capturing Top 100 AIR'},
    },
]


class RagService:

    @staticmethod
    def augment_question(question: str) -> str:
        """Expand known abbreviations in the question."""
        words = question.split()
        expanded = []
        for word in words:
            lower = word.strip('.,!?;:').lower()
            if lower in GLOSSARY:
                expanded.append(f"{word} ({GLOSSARY[lower]})")
            else:
                expanded.append(word)
        return ' '.join(expanded)

    @staticmethod
    def get_relevant_golden_queries(question: str) -> list:
        """Return golden queries whose keywords overlap with the question."""
        q_lower = question.lower()
        matched = []
        for gq in GOLDEN_QUERIES:
            if any(kw.lower() in q_lower for kw in gq['keywords']):
                matched.append(gq)
        return matched[:3]  # cap at 3 examples to keep prompt size reasonable
