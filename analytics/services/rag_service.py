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
    {
        'keywords': ['best', 'top', 'branch', 'madras', 'iitm', 'competitive'],
        'q': 'What are the most competitive branches at IIT Madras?',
        'sql': "SELECT SUBSTRING_INDEX(b.branch_name, '(', 1) AS branch, ROUND(AVG(f.closing_rank)) AS avg_closing_rank FROM fact_allotment f JOIN dim_iit i ON f.iit_id=i.iit_id JOIN dim_branch b ON f.branch_id=b.branch_id JOIN dim_seat_type s ON f.seat_type_id=s.seat_type_id JOIN dim_gender g ON f.gender_id=g.gender_id WHERE quota_id=(SELECT quota_id FROM dim_quota WHERE quota_code='AI') AND i.iit_name='Indian Institute of Technology Madras' AND s.seat_type_code='OPEN' AND (g.gender_code='Gender-Neutral' OR g.gender_code='NULL') AND (f.year, f.round_no) IN (SELECT year, MAX(round_no) FROM fact_allotment GROUP BY year) AND f.is_preparatory=0 GROUP BY b.branch_id ORDER BY avg_closing_rank ASC LIMIT 10",
        'answer': 'These are the most competitive branches at IIT Madras, ranked by lowest average closing rank.',
        'response_type': 'chart',
        'chart': {'type': 'horizontalBar', 'x': 'branch', 'y': 'avg_closing_rank', 'title': 'Toughest Branches at IIT Madras'},
    },
    {
        'keywords': ['opening', 'closing', 'gap', 'spread', 'widest', 'difference'],
        'q': 'Which IIT-branch has the widest gap between opening and closing rank?',
        'sql': "SELECT CONCAT(i.short_code, ' · ', SUBSTRING_INDEX(b.branch_name, '(', 1)) AS iit_branch, ROUND(AVG(f.closing_rank - f.opening_rank)) AS rank_spread FROM fact_allotment f JOIN dim_iit i ON f.iit_id=i.iit_id JOIN dim_branch b ON f.branch_id=b.branch_id JOIN dim_seat_type s ON f.seat_type_id=s.seat_type_id JOIN dim_gender g ON f.gender_id=g.gender_id WHERE quota_id=(SELECT quota_id FROM dim_quota WHERE quota_code='AI') AND s.seat_type_code='OPEN' AND (g.gender_code='Gender-Neutral' OR g.gender_code='NULL') AND (f.year, f.round_no) IN (SELECT year, MAX(round_no) FROM fact_allotment GROUP BY year) AND f.is_preparatory=0 GROUP BY i.iit_id, b.branch_id HAVING COUNT(*) >= 3 ORDER BY rank_spread DESC LIMIT 10",
        'answer': 'These IIT-branch combinations have the widest opening-to-closing rank spread, indicating high in-round demand.',
        'response_type': 'chart',
        'chart': {'type': 'horizontalBar', 'x': 'iit_branch', 'y': 'rank_spread', 'title': 'Widest Opening-Closing Rank Gap'},
    },
    {
        'keywords': ['most', 'seats', 'offer', 'total', 'count', 'capacity'],
        'q': 'Which IITs offer the most seats?',
        'sql': "SELECT i.short_code AS iit, COUNT(*) AS total_seats FROM fact_allotment f JOIN dim_iit i ON f.iit_id=i.iit_id JOIN dim_seat_type s ON f.seat_type_id=s.seat_type_id JOIN dim_gender g ON f.gender_id=g.gender_id WHERE quota_id=(SELECT quota_id FROM dim_quota WHERE quota_code='AI') AND (f.year, f.round_no) IN (SELECT year, MAX(round_no) FROM fact_allotment GROUP BY year) AND f.is_preparatory=0 GROUP BY i.iit_id ORDER BY total_seats DESC LIMIT 15",
        'answer': 'These IITs offer the most seats across all branches and categories.',
        'response_type': 'chart',
        'chart': {'type': 'horizontalBar', 'x': 'iit', 'y': 'total_seats', 'title': 'Seats Offered per IIT'},
    },
    {
        'keywords': ['sc', 'scheduled caste', 'category', 'reservation'],
        'q': 'Compare SC category CSE closing ranks across IITs',
        'sql': "SELECT i.short_code AS iit, ROUND(AVG(f.closing_rank)) AS avg_closing_rank FROM fact_allotment f JOIN dim_iit i ON f.iit_id=i.iit_id JOIN dim_branch b ON f.branch_id=b.branch_id JOIN dim_seat_type s ON f.seat_type_id=s.seat_type_id JOIN dim_gender g ON f.gender_id=g.gender_id WHERE quota_id=(SELECT quota_id FROM dim_quota WHERE quota_code='AI') AND b.category='cse_family' AND s.seat_type_code='SC' AND (g.gender_code='Gender-Neutral' OR g.gender_code='NULL') AND (f.year, f.round_no) IN (SELECT year, MAX(round_no) FROM fact_allotment GROUP BY year) AND f.is_preparatory=0 GROUP BY i.iit_id ORDER BY avg_closing_rank ASC LIMIT 15",
        'answer': 'SC category CSE closing ranks compared across IITs, from most to least competitive.',
        'response_type': 'chart',
        'chart': {'type': 'horizontalBar', 'x': 'iit', 'y': 'avg_closing_rank', 'title': 'SC CSE Cutoffs by IIT'},
    },
    {
        'keywords': ['st', 'scheduled tribe', 'category'],
        'q': 'Show ST category CSE closing rank trend at IIT Bombay',
        'sql': "SELECT f.year, ROUND(AVG(f.closing_rank)) AS avg_closing_rank FROM fact_allotment f JOIN dim_iit i ON f.iit_id=i.iit_id JOIN dim_branch b ON f.branch_id=b.branch_id JOIN dim_seat_type s ON f.seat_type_id=s.seat_type_id JOIN dim_gender g ON f.gender_id=g.gender_id WHERE quota_id=(SELECT quota_id FROM dim_quota WHERE quota_code='AI') AND i.iit_name='Indian Institute of Technology Bombay' AND b.category='cse_family' AND s.seat_type_code='ST' AND (g.gender_code='Gender-Neutral' OR g.gender_code='NULL') AND (f.year, f.round_no) IN (SELECT year, MAX(round_no) FROM fact_allotment GROUP BY year) AND f.is_preparatory=0 GROUP BY f.year ORDER BY f.year",
        'answer': 'ST category CSE closing rank trend at IIT Bombay over the years.',
        'response_type': 'chart',
        'chart': {'type': 'line', 'x': 'year', 'y': 'avg_closing_rank', 'title': 'ST CSE Cutoffs (IIT Bombay)', 'y_reverse': True},
    },
    {
        'keywords': ['ews', 'economically weaker', 'category'],
        'q': 'How have EWS closing ranks changed over the years for CSE?',
        'sql': "SELECT f.year, ROUND(AVG(f.closing_rank)) AS avg_closing_rank FROM fact_allotment f JOIN dim_iit i ON f.iit_id=i.iit_id JOIN dim_branch b ON f.branch_id=b.branch_id JOIN dim_seat_type s ON f.seat_type_id=s.seat_type_id JOIN dim_gender g ON f.gender_id=g.gender_id WHERE quota_id=(SELECT quota_id FROM dim_quota WHERE quota_code='AI') AND b.category='cse_family' AND s.seat_type_code='EWS' AND (g.gender_code='Gender-Neutral' OR g.gender_code='NULL') AND (f.year, f.round_no) IN (SELECT year, MAX(round_no) FROM fact_allotment GROUP BY year) AND f.is_preparatory=0 GROUP BY f.year ORDER BY f.year",
        'answer': 'EWS category CSE closing ranks across all IITs, year over year.',
        'response_type': 'chart',
        'chart': {'type': 'line', 'x': 'year', 'y': 'avg_closing_rank', 'title': 'EWS CSE Cutoff Trend', 'y_reverse': True},
    },
    {
        'keywords': ['mechanical', 'mech', 'trend'],
        'q': 'Show mechanical engineering closing rank trend at IIT Delhi',
        'sql': "SELECT f.year, ROUND(AVG(f.closing_rank)) AS avg_closing_rank FROM fact_allotment f JOIN dim_iit i ON f.iit_id=i.iit_id JOIN dim_branch b ON f.branch_id=b.branch_id JOIN dim_seat_type s ON f.seat_type_id=s.seat_type_id JOIN dim_gender g ON f.gender_id=g.gender_id WHERE quota_id=(SELECT quota_id FROM dim_quota WHERE quota_code='AI') AND i.iit_name='Indian Institute of Technology Delhi' AND b.branch_name LIKE 'Mechanical Engineering%' AND s.seat_type_code='OPEN' AND (g.gender_code='Gender-Neutral' OR g.gender_code='NULL') AND (f.year, f.round_no) IN (SELECT year, MAX(round_no) FROM fact_allotment GROUP BY year) AND f.is_preparatory=0 GROUP BY f.year ORDER BY f.year",
        'answer': 'Mechanical Engineering closing rank trend at IIT Delhi over the years.',
        'response_type': 'chart',
        'chart': {'type': 'line', 'x': 'year', 'y': 'avg_closing_rank', 'title': 'Mechanical Cutoffs (IIT Delhi)', 'y_reverse': True},
    },
    {
        'keywords': ['electrical', 'ee', 'easiest'],
        'q': 'Which IIT is easiest for Electrical Engineering?',
        'sql': "SELECT i.short_code AS iit, ROUND(AVG(f.closing_rank)) AS avg_closing_rank FROM fact_allotment f JOIN dim_iit i ON f.iit_id=i.iit_id JOIN dim_branch b ON f.branch_id=b.branch_id JOIN dim_seat_type s ON f.seat_type_id=s.seat_type_id JOIN dim_gender g ON f.gender_id=g.gender_id WHERE quota_id=(SELECT quota_id FROM dim_quota WHERE quota_code='AI') AND b.branch_name LIKE 'Electrical Engineering%' AND s.seat_type_code='OPEN' AND (g.gender_code='Gender-Neutral' OR g.gender_code='NULL') AND (f.year, f.round_no) IN (SELECT year, MAX(round_no) FROM fact_allotment GROUP BY year) AND f.is_preparatory=0 GROUP BY i.iit_id ORDER BY avg_closing_rank DESC LIMIT 10",
        'answer': 'These IITs have the most relaxed (highest) closing ranks for Electrical Engineering.',
        'response_type': 'chart',
        'chart': {'type': 'horizontalBar', 'x': 'iit', 'y': 'avg_closing_rank', 'title': 'Easiest IITs for Electrical'},
    },
    {
        'keywords': ['civil', 'easiest'],
        'q': 'Easiest IITs for Civil Engineering',
        'sql': "SELECT i.short_code AS iit, ROUND(AVG(f.closing_rank)) AS avg_closing_rank FROM fact_allotment f JOIN dim_iit i ON f.iit_id=i.iit_id JOIN dim_branch b ON f.branch_id=b.branch_id JOIN dim_seat_type s ON f.seat_type_id=s.seat_type_id JOIN dim_gender g ON f.gender_id=g.gender_id WHERE quota_id=(SELECT quota_id FROM dim_quota WHERE quota_code='AI') AND b.branch_name LIKE 'Civil Engineering%' AND s.seat_type_code='OPEN' AND (g.gender_code='Gender-Neutral' OR g.gender_code='NULL') AND (f.year, f.round_no) IN (SELECT year, MAX(round_no) FROM fact_allotment GROUP BY year) AND f.is_preparatory=0 GROUP BY i.iit_id ORDER BY avg_closing_rank DESC LIMIT 10",
        'answer': 'These IITs have the most accessible Civil Engineering cutoffs.',
        'response_type': 'chart',
        'chart': {'type': 'horizontalBar', 'x': 'iit', 'y': 'avg_closing_rank', 'title': 'Easiest IITs for Civil'},
    },
    {
        'keywords': ['overall', 'average', 'all', 'trend', 'year'],
        'q': 'What is the overall average closing rank trend across all IITs?',
        'sql': "SELECT f.year, ROUND(AVG(f.closing_rank)) AS avg_closing_rank FROM fact_allotment f JOIN dim_seat_type s ON f.seat_type_id=s.seat_type_id JOIN dim_gender g ON f.gender_id=g.gender_id WHERE quota_id=(SELECT quota_id FROM dim_quota WHERE quota_code='AI') AND s.seat_type_code='OPEN' AND (g.gender_code='Gender-Neutral' OR g.gender_code='NULL') AND (f.year, f.round_no) IN (SELECT year, MAX(round_no) FROM fact_allotment GROUP BY year) AND f.is_preparatory=0 GROUP BY f.year ORDER BY f.year",
        'answer': 'The overall average OPEN closing rank across all IITs and branches, year over year.',
        'response_type': 'chart',
        'chart': {'type': 'line', 'x': 'year', 'y': 'avg_closing_rank', 'title': 'Overall Average Closing Rank Trend'},
    },
    {
        'keywords': ['how many', 'number', 'branches', 'programs', 'distinct'],
        'q': 'How many distinct branches does each IIT offer?',
        'sql': "SELECT i.short_code AS iit, COUNT(DISTINCT b.branch_id) AS branch_count FROM fact_allotment f JOIN dim_iit i ON f.iit_id=i.iit_id JOIN dim_branch b ON f.branch_id=b.branch_id WHERE f.is_preparatory=0 GROUP BY i.iit_id ORDER BY branch_count DESC LIMIT 15",
        'answer': 'The number of distinct branches offered by each IIT.',
        'response_type': 'chart',
        'chart': {'type': 'horizontalBar', 'x': 'iit', 'y': 'branch_count', 'title': 'Branch Diversity per IIT'},
    },
    {
        'keywords': ['volatile', 'volatility', 'fluctuate', 'unstable', 'stddev'],
        'q': 'Which IIT-branch combinations have the most volatile closing ranks?',
        'sql': "SELECT CONCAT(i.short_code, ' · ', SUBSTRING_INDEX(b.branch_name, '(', 1)) AS iit_branch, ROUND(STDDEV_POP(f.closing_rank)) AS volatility FROM fact_allotment f JOIN dim_iit i ON f.iit_id=i.iit_id JOIN dim_branch b ON f.branch_id=b.branch_id JOIN dim_seat_type s ON f.seat_type_id=s.seat_type_id JOIN dim_gender g ON f.gender_id=g.gender_id WHERE quota_id=(SELECT quota_id FROM dim_quota WHERE quota_code='AI') AND s.seat_type_code='OPEN' AND (g.gender_code='Gender-Neutral' OR g.gender_code='NULL') AND (f.year, f.round_no) IN (SELECT year, MAX(round_no) FROM fact_allotment GROUP BY year) AND f.is_preparatory=0 GROUP BY i.iit_id, b.branch_id HAVING COUNT(*) >= 4 ORDER BY volatility DESC LIMIT 10",
        'answer': 'These IIT-branch combinations show the highest year-to-year closing rank volatility.',
        'response_type': 'chart',
        'chart': {'type': 'horizontalBar', 'x': 'iit_branch', 'y': 'volatility', 'title': 'Most Volatile IIT-Branch Cutoffs'},
    },
    {
        'keywords': ['cse', 'ece', 'compare', 'vs', 'kanpur'],
        'q': 'Compare CSE and ECE closing ranks at IIT Kanpur over the years',
        'sql': "SELECT f.year, CASE WHEN b.category='cse_family' THEN 'CSE' ELSE 'ECE' END AS branch, ROUND(AVG(f.closing_rank)) AS avg_closing_rank FROM fact_allotment f JOIN dim_iit i ON f.iit_id=i.iit_id JOIN dim_branch b ON f.branch_id=b.branch_id JOIN dim_seat_type s ON f.seat_type_id=s.seat_type_id JOIN dim_gender g ON f.gender_id=g.gender_id WHERE quota_id=(SELECT quota_id FROM dim_quota WHERE quota_code='AI') AND i.iit_name='Indian Institute of Technology Kanpur' AND (b.category='cse_family' OR b.branch_name LIKE 'Electronics and Communication%') AND s.seat_type_code='OPEN' AND (g.gender_code='Gender-Neutral' OR g.gender_code='NULL') AND (f.year, f.round_no) IN (SELECT year, MAX(round_no) FROM fact_allotment GROUP BY year) AND f.is_preparatory=0 GROUP BY f.year, branch ORDER BY f.year",
        'answer': 'CSE vs ECE closing rank comparison at IIT Kanpur over the years.',
        'response_type': 'chart',
        'chart': {'type': 'line', 'x': 'year', 'y': 'avg_closing_rank', 'series': 'branch', 'title': 'CSE vs ECE at IIT Kanpur', 'y_reverse': True},
    },
    {
        'keywords': ['new age', 'new-age', 'emerging', 'ai', 'data science'],
        'q': 'Show closing ranks for new-age branches like AI and Data Science',
        'sql': "SELECT CONCAT(i.short_code, ' · ', SUBSTRING_INDEX(b.branch_name, '(', 1)) AS iit_branch, ROUND(AVG(f.closing_rank)) AS avg_closing_rank FROM fact_allotment f JOIN dim_iit i ON f.iit_id=i.iit_id JOIN dim_branch b ON f.branch_id=b.branch_id JOIN dim_seat_type s ON f.seat_type_id=s.seat_type_id JOIN dim_gender g ON f.gender_id=g.gender_id WHERE quota_id=(SELECT quota_id FROM dim_quota WHERE quota_code='AI') AND b.category='new_age' AND s.seat_type_code='OPEN' AND (g.gender_code='Gender-Neutral' OR g.gender_code='NULL') AND (f.year, f.round_no) IN (SELECT year, MAX(round_no) FROM fact_allotment GROUP BY year) AND f.is_preparatory=0 GROUP BY i.iit_id, b.branch_id ORDER BY avg_closing_rank ASC LIMIT 10",
        'answer': 'These are the most competitive new-age branches such as AI and Data Science.',
        'response_type': 'chart',
        'chart': {'type': 'horizontalBar', 'x': 'iit_branch', 'y': 'avg_closing_rank', 'title': 'Toughest New-Age Branches'},
    },
    {
        'keywords': ['pwd', 'disability', 'differently abled'],
        'q': 'What are the closing ranks for OPEN (PwD) seats in CSE?',
        'sql': "SELECT i.short_code AS iit, ROUND(AVG(f.closing_rank)) AS avg_closing_rank FROM fact_allotment f JOIN dim_iit i ON f.iit_id=i.iit_id JOIN dim_branch b ON f.branch_id=b.branch_id JOIN dim_seat_type s ON f.seat_type_id=s.seat_type_id JOIN dim_gender g ON f.gender_id=g.gender_id WHERE quota_id=(SELECT quota_id FROM dim_quota WHERE quota_code='AI') AND b.category='cse_family' AND s.seat_type_code='OPEN (PwD)' AND (g.gender_code='Gender-Neutral' OR g.gender_code='NULL') AND (f.year, f.round_no) IN (SELECT year, MAX(round_no) FROM fact_allotment GROUP BY year) AND f.is_preparatory=0 GROUP BY i.iit_id ORDER BY avg_closing_rank ASC LIMIT 12",
        'answer': 'CSE closing ranks for OPEN (PwD) seats across IITs.',
        'response_type': 'chart',
        'chart': {'type': 'horizontalBar', 'x': 'iit', 'y': 'avg_closing_rank', 'title': 'OPEN (PwD) CSE Cutoffs'},
    },
    {
        'keywords': ['dual degree', '5 year', 'integrated', 'm.tech'],
        'q': 'Show dual degree CSE closing ranks across IITs',
        'sql': "SELECT i.short_code AS iit, ROUND(AVG(f.closing_rank)) AS avg_closing_rank FROM fact_allotment f JOIN dim_iit i ON f.iit_id=i.iit_id JOIN dim_branch b ON f.branch_id=b.branch_id JOIN dim_seat_type s ON f.seat_type_id=s.seat_type_id JOIN dim_gender g ON f.gender_id=g.gender_id WHERE quota_id=(SELECT quota_id FROM dim_quota WHERE quota_code='AI') AND b.branch_name LIKE '%Computer Science%' AND b.branch_name LIKE '%5 Years%' AND s.seat_type_code='OPEN' AND (g.gender_code='Gender-Neutral' OR g.gender_code='NULL') AND (f.year, f.round_no) IN (SELECT year, MAX(round_no) FROM fact_allotment GROUP BY year) AND f.is_preparatory=0 GROUP BY i.iit_id ORDER BY avg_closing_rank ASC LIMIT 12",
        'answer': 'Closing ranks for the 5-year dual degree CSE programs across IITs.',
        'response_type': 'chart',
        'chart': {'type': 'horizontalBar', 'x': 'iit', 'y': 'avg_closing_rank', 'title': 'Dual Degree CSE Cutoffs'},
    },
    {
        'keywords': ['physics', 'engineering physics'],
        'q': 'Which IITs offer Engineering Physics and what are the cutoffs?',
        'sql': "SELECT i.short_code AS iit, ROUND(AVG(f.closing_rank)) AS avg_closing_rank FROM fact_allotment f JOIN dim_iit i ON f.iit_id=i.iit_id JOIN dim_branch b ON f.branch_id=b.branch_id JOIN dim_seat_type s ON f.seat_type_id=s.seat_type_id JOIN dim_gender g ON f.gender_id=g.gender_id WHERE quota_id=(SELECT quota_id FROM dim_quota WHERE quota_code='AI') AND b.branch_name LIKE 'Engineering Physics%' AND s.seat_type_code='OPEN' AND (g.gender_code='Gender-Neutral' OR g.gender_code='NULL') AND (f.year, f.round_no) IN (SELECT year, MAX(round_no) FROM fact_allotment GROUP BY year) AND f.is_preparatory=0 GROUP BY i.iit_id ORDER BY avg_closing_rank ASC LIMIT 12",
        'answer': 'These IITs offer Engineering Physics, ranked by closing rank.',
        'response_type': 'chart',
        'chart': {'type': 'horizontalBar', 'x': 'iit', 'y': 'avg_closing_rank', 'title': 'Engineering Physics Cutoffs'},
    },
    {
        'keywords': ['chemical', 'trend'],
        'q': 'Show chemical engineering closing rank trend at IIT Bombay',
        'sql': "SELECT f.year, ROUND(AVG(f.closing_rank)) AS avg_closing_rank FROM fact_allotment f JOIN dim_iit i ON f.iit_id=i.iit_id JOIN dim_branch b ON f.branch_id=b.branch_id JOIN dim_seat_type s ON f.seat_type_id=s.seat_type_id JOIN dim_gender g ON f.gender_id=g.gender_id WHERE quota_id=(SELECT quota_id FROM dim_quota WHERE quota_code='AI') AND i.iit_name='Indian Institute of Technology Bombay' AND b.branch_name LIKE 'Chemical Engineering%' AND s.seat_type_code='OPEN' AND (g.gender_code='Gender-Neutral' OR g.gender_code='NULL') AND (f.year, f.round_no) IN (SELECT year, MAX(round_no) FROM fact_allotment GROUP BY year) AND f.is_preparatory=0 GROUP BY f.year ORDER BY f.year",
        'answer': 'Chemical Engineering closing rank trend at IIT Bombay.',
        'response_type': 'chart',
        'chart': {'type': 'line', 'x': 'year', 'y': 'avg_closing_rank', 'title': 'Chemical Cutoffs (IIT Bombay)', 'y_reverse': True},
    },
    {
        'keywords': ['aerospace', 'aeronautical', 'aero'],
        'q': 'Compare aerospace engineering cutoffs across IITs',
        'sql': "SELECT i.short_code AS iit, ROUND(AVG(f.closing_rank)) AS avg_closing_rank FROM fact_allotment f JOIN dim_iit i ON f.iit_id=i.iit_id JOIN dim_branch b ON f.branch_id=b.branch_id JOIN dim_seat_type s ON f.seat_type_id=s.seat_type_id JOIN dim_gender g ON f.gender_id=g.gender_id WHERE quota_id=(SELECT quota_id FROM dim_quota WHERE quota_code='AI') AND (b.branch_name LIKE 'Aerospace%' OR b.branch_name LIKE 'Aeronautical%') AND s.seat_type_code='OPEN' AND (g.gender_code='Gender-Neutral' OR g.gender_code='NULL') AND (f.year, f.round_no) IN (SELECT year, MAX(round_no) FROM fact_allotment GROUP BY year) AND f.is_preparatory=0 GROUP BY i.iit_id ORDER BY avg_closing_rank ASC LIMIT 12",
        'answer': 'Aerospace engineering closing ranks compared across IITs.',
        'response_type': 'chart',
        'chart': {'type': 'horizontalBar', 'x': 'iit', 'y': 'avg_closing_rank', 'title': 'Aerospace Cutoffs by IIT'},
    },
    {
        'keywords': ['gender', 'gap', 'female', 'difference'],
        'q': 'What is the gender gap in CSE closing ranks at each IIT?',
        'sql': "SELECT i.short_code AS iit, ROUND(AVG(CASE WHEN g.gender_code LIKE 'Female%' THEN f.closing_rank END) - AVG(CASE WHEN g.gender_code='Gender-Neutral' THEN f.closing_rank END)) AS gender_gap FROM fact_allotment f JOIN dim_iit i ON f.iit_id=i.iit_id JOIN dim_branch b ON f.branch_id=b.branch_id JOIN dim_seat_type s ON f.seat_type_id=s.seat_type_id JOIN dim_gender g ON f.gender_id=g.gender_id WHERE quota_id=(SELECT quota_id FROM dim_quota WHERE quota_code='AI') AND b.category='cse_family' AND s.seat_type_code='OPEN' AND (f.year, f.round_no) IN (SELECT year, MAX(round_no) FROM fact_allotment GROUP BY year) AND f.is_preparatory=0 GROUP BY i.iit_id HAVING gender_gap IS NOT NULL ORDER BY gender_gap DESC LIMIT 12",
        'answer': 'The gap between female-only and gender-neutral CSE closing ranks at each IIT.',
        'response_type': 'chart',
        'chart': {'type': 'horizontalBar', 'x': 'iit', 'y': 'gender_gap', 'title': 'CSE Gender Gap by IIT'},
    },
    {
        'keywords': ['top 5', 'best', 'hierarchy', 'ranking', 'prestige'],
        'q': 'Rank the top 5 IITs by overall average closing rank',
        'sql': "SELECT i.short_code AS iit, ROUND(AVG(f.closing_rank)) AS avg_closing_rank FROM fact_allotment f JOIN dim_iit i ON f.iit_id=i.iit_id JOIN dim_seat_type s ON f.seat_type_id=s.seat_type_id JOIN dim_gender g ON f.gender_id=g.gender_id WHERE quota_id=(SELECT quota_id FROM dim_quota WHERE quota_code='AI') AND s.seat_type_code='OPEN' AND (g.gender_code='Gender-Neutral' OR g.gender_code='NULL') AND (f.year, f.round_no) IN (SELECT year, MAX(round_no) FROM fact_allotment GROUP BY year) AND f.is_preparatory=0 GROUP BY i.iit_id ORDER BY avg_closing_rank ASC LIMIT 5",
        'answer': 'The top 5 most competitive IITs by overall average closing rank.',
        'response_type': 'chart',
        'chart': {'type': 'horizontalBar', 'x': 'iit', 'y': 'avg_closing_rank', 'title': 'Top 5 IITs by Cutoff'},
    },
    {
        'keywords': ['2022', 'snapshot', 'all iits', 'specific year'],
        'q': 'Show CSE closing ranks at all IITs in 2022',
        'sql': "SELECT i.short_code AS iit, ROUND(AVG(f.closing_rank)) AS avg_closing_rank FROM fact_allotment f JOIN dim_iit i ON f.iit_id=i.iit_id JOIN dim_branch b ON f.branch_id=b.branch_id JOIN dim_seat_type s ON f.seat_type_id=s.seat_type_id JOIN dim_gender g ON f.gender_id=g.gender_id WHERE quota_id=(SELECT quota_id FROM dim_quota WHERE quota_code='AI') AND b.category='cse_family' AND s.seat_type_code='OPEN' AND (g.gender_code='Gender-Neutral' OR g.gender_code='NULL') AND f.year=2022 AND f.round_no=(SELECT MAX(round_no) FROM fact_allotment WHERE year=2022) AND f.is_preparatory=0 GROUP BY i.iit_id ORDER BY avg_closing_rank ASC LIMIT 20",
        'answer': 'CSE closing ranks at all IITs for the year 2022.',
        'response_type': 'chart',
        'chart': {'type': 'horizontalBar', 'x': 'iit', 'y': 'avg_closing_rank', 'title': 'CSE Cutoffs Across IITs (2022)'},
    },
    {
        'keywords': ['available', 'list', 'guwahati', 'iitg', 'all branches'],
        'q': 'List all branches available at IIT Guwahati with their cutoffs',
        'sql': "SELECT SUBSTRING_INDEX(b.branch_name, '(', 1) AS branch, ROUND(AVG(f.closing_rank)) AS avg_closing_rank FROM fact_allotment f JOIN dim_iit i ON f.iit_id=i.iit_id JOIN dim_branch b ON f.branch_id=b.branch_id JOIN dim_seat_type s ON f.seat_type_id=s.seat_type_id JOIN dim_gender g ON f.gender_id=g.gender_id WHERE quota_id=(SELECT quota_id FROM dim_quota WHERE quota_code='AI') AND i.iit_name='Indian Institute of Technology Guwahati' AND s.seat_type_code='OPEN' AND (g.gender_code='Gender-Neutral' OR g.gender_code='NULL') AND (f.year, f.round_no) IN (SELECT year, MAX(round_no) FROM fact_allotment GROUP BY year) AND f.is_preparatory=0 GROUP BY b.branch_id ORDER BY avg_closing_rank ASC",
        'answer': 'All branches offered at IIT Guwahati with their average closing ranks.',
        'response_type': 'table',
    },
    {
        'keywords': ['round', 'improvement', 'round 1', 'final round'],
        'q': 'How much does CSE closing rank at IIT Bombay improve across rounds in 2022?',
        'sql': "SELECT f.round_no, ROUND(AVG(f.closing_rank)) AS avg_closing_rank FROM fact_allotment f JOIN dim_iit i ON f.iit_id=i.iit_id JOIN dim_branch b ON f.branch_id=b.branch_id JOIN dim_seat_type s ON f.seat_type_id=s.seat_type_id JOIN dim_gender g ON f.gender_id=g.gender_id WHERE quota_id=(SELECT quota_id FROM dim_quota WHERE quota_code='AI') AND i.iit_name='Indian Institute of Technology Bombay' AND b.category='cse_family' AND s.seat_type_code='OPEN' AND (g.gender_code='Gender-Neutral' OR g.gender_code='NULL') AND f.year=2022 AND f.is_preparatory=0 GROUP BY f.round_no ORDER BY f.round_no",
        'answer': 'Average CSE closing rank at IIT Bombay by round in 2022.',
        'response_type': 'chart',
        'chart': {'type': 'bar', 'x': 'round_no', 'y': 'avg_closing_rank', 'title': 'Round-wise CSE Cutoff (IIT Bombay, 2022)'},
    },
    {
        'keywords': ['metallurgy', 'metallurgical', 'materials', 'metal'],
        'q': 'Show metallurgical engineering cutoffs across IITs',
        'sql': "SELECT i.short_code AS iit, ROUND(AVG(f.closing_rank)) AS avg_closing_rank FROM fact_allotment f JOIN dim_iit i ON f.iit_id=i.iit_id JOIN dim_branch b ON f.branch_id=b.branch_id JOIN dim_seat_type s ON f.seat_type_id=s.seat_type_id JOIN dim_gender g ON f.gender_id=g.gender_id WHERE quota_id=(SELECT quota_id FROM dim_quota WHERE quota_code='AI') AND b.branch_name LIKE '%Metallurg%' AND s.seat_type_code='OPEN' AND (g.gender_code='Gender-Neutral' OR g.gender_code='NULL') AND (f.year, f.round_no) IN (SELECT year, MAX(round_no) FROM fact_allotment GROUP BY year) AND f.is_preparatory=0 GROUP BY i.iit_id ORDER BY avg_closing_rank ASC LIMIT 12",
        'answer': 'Metallurgical engineering closing ranks across IITs.',
        'response_type': 'chart',
        'chart': {'type': 'horizontalBar', 'x': 'iit', 'y': 'avg_closing_rank', 'title': 'Metallurgy Cutoffs by IIT'},
    },
    {
        'keywords': ['compare', 'three', 'madras', 'kanpur', 'kharagpur'],
        'q': 'Compare CSE closing ranks between IIT Madras, IIT Kanpur and IIT Kharagpur',
        'sql': "SELECT f.year, i.short_code AS iit, ROUND(AVG(f.closing_rank)) AS avg_closing_rank FROM fact_allotment f JOIN dim_iit i ON f.iit_id=i.iit_id JOIN dim_branch b ON f.branch_id=b.branch_id JOIN dim_seat_type s ON f.seat_type_id=s.seat_type_id JOIN dim_gender g ON f.gender_id=g.gender_id WHERE quota_id=(SELECT quota_id FROM dim_quota WHERE quota_code='AI') AND i.iit_name IN ('Indian Institute of Technology Madras','Indian Institute of Technology Kanpur','Indian Institute of Technology Kharagpur') AND b.category='cse_family' AND s.seat_type_code='OPEN' AND (g.gender_code='Gender-Neutral' OR g.gender_code='NULL') AND (f.year, f.round_no) IN (SELECT year, MAX(round_no) FROM fact_allotment GROUP BY year) AND f.is_preparatory=0 GROUP BY f.year, i.iit_id ORDER BY f.year",
        'answer': 'CSE closing rank comparison between IIT Madras, Kanpur and Kharagpur over the years.',
        'response_type': 'chart',
        'chart': {'type': 'line', 'x': 'year', 'y': 'avg_closing_rank', 'series': 'iit', 'title': 'CSE: Madras vs Kanpur vs Kharagpur', 'y_reverse': True},
    },
    {
        'keywords': ['seat type', 'competitive', 'reservation', 'category gap'],
        'q': 'Which seat type category has the most competitive average closing rank for CSE?',
        'sql': "SELECT s.seat_type_code AS category, ROUND(AVG(f.closing_rank)) AS avg_closing_rank FROM fact_allotment f JOIN dim_branch b ON f.branch_id=b.branch_id JOIN dim_seat_type s ON f.seat_type_id=s.seat_type_id JOIN dim_gender g ON f.gender_id=g.gender_id WHERE quota_id=(SELECT quota_id FROM dim_quota WHERE quota_code='AI') AND b.category='cse_family' AND (g.gender_code='Gender-Neutral' OR g.gender_code='NULL') AND (f.year, f.round_no) IN (SELECT year, MAX(round_no) FROM fact_allotment GROUP BY year) AND f.is_preparatory=0 GROUP BY s.seat_type_id ORDER BY avg_closing_rank ASC",
        'answer': 'Average CSE closing rank by seat type category, from most to least competitive.',
        'response_type': 'chart',
        'chart': {'type': 'horizontalBar', 'x': 'category', 'y': 'avg_closing_rank', 'title': 'CSE Cutoff by Seat Type'},
    },
    {
        'keywords': ['toughest year', 'hardest year', 'which year'],
        'q': 'In which year was CSE at IIT Bombay the toughest?',
        'sql': "SELECT f.year, ROUND(AVG(f.closing_rank)) AS avg_closing_rank FROM fact_allotment f JOIN dim_iit i ON f.iit_id=i.iit_id JOIN dim_branch b ON f.branch_id=b.branch_id JOIN dim_seat_type s ON f.seat_type_id=s.seat_type_id JOIN dim_gender g ON f.gender_id=g.gender_id WHERE quota_id=(SELECT quota_id FROM dim_quota WHERE quota_code='AI') AND i.iit_name='Indian Institute of Technology Bombay' AND b.category='cse_family' AND s.seat_type_code='OPEN' AND (g.gender_code='Gender-Neutral' OR g.gender_code='NULL') AND (f.year, f.round_no) IN (SELECT year, MAX(round_no) FROM fact_allotment GROUP BY year) AND f.is_preparatory=0 GROUP BY f.year ORDER BY avg_closing_rank ASC LIMIT 1",
        'answer': 'CSE at IIT Bombay was toughest in {year}, with an average closing rank of {avg_closing_rank}.',
        'response_type': 'text',
    },
    {
        'keywords': ['economics', 'eco', 'humanities'],
        'q': 'Show economics program closing ranks across IITs',
        'sql': "SELECT i.short_code AS iit, ROUND(AVG(f.closing_rank)) AS avg_closing_rank FROM fact_allotment f JOIN dim_iit i ON f.iit_id=i.iit_id JOIN dim_branch b ON f.branch_id=b.branch_id JOIN dim_seat_type s ON f.seat_type_id=s.seat_type_id JOIN dim_gender g ON f.gender_id=g.gender_id WHERE quota_id=(SELECT quota_id FROM dim_quota WHERE quota_code='AI') AND b.branch_name LIKE '%Economics%' AND s.seat_type_code='OPEN' AND (g.gender_code='Gender-Neutral' OR g.gender_code='NULL') AND (f.year, f.round_no) IN (SELECT year, MAX(round_no) FROM fact_allotment GROUP BY year) AND f.is_preparatory=0 GROUP BY i.iit_id ORDER BY avg_closing_rank ASC LIMIT 12",
        'answer': 'Economics program closing ranks across IITs.',
        'response_type': 'chart',
        'chart': {'type': 'horizontalBar', 'x': 'iit', 'y': 'avg_closing_rank', 'title': 'Economics Cutoffs by IIT'},
    },
    {
        'keywords': ['mathematics', 'computing', 'mnc', 'maths'],
        'q': 'Compare Mathematics and Computing cutoffs across IITs',
        'sql': "SELECT i.short_code AS iit, ROUND(AVG(f.closing_rank)) AS avg_closing_rank FROM fact_allotment f JOIN dim_iit i ON f.iit_id=i.iit_id JOIN dim_branch b ON f.branch_id=b.branch_id JOIN dim_seat_type s ON f.seat_type_id=s.seat_type_id JOIN dim_gender g ON f.gender_id=g.gender_id WHERE quota_id=(SELECT quota_id FROM dim_quota WHERE quota_code='AI') AND b.branch_name LIKE '%Mathematics and Computing%' AND s.seat_type_code='OPEN' AND (g.gender_code='Gender-Neutral' OR g.gender_code='NULL') AND (f.year, f.round_no) IN (SELECT year, MAX(round_no) FROM fact_allotment GROUP BY year) AND f.is_preparatory=0 GROUP BY i.iit_id ORDER BY avg_closing_rank ASC LIMIT 12",
        'answer': 'Mathematics and Computing closing ranks across IITs.',
        'response_type': 'chart',
        'chart': {'type': 'horizontalBar', 'x': 'iit', 'y': 'avg_closing_rank', 'title': 'Mathematics & Computing Cutoffs'},
    },
    {
        'keywords': ['biotech', 'biotechnology', 'bio'],
        'q': 'Show biotechnology closing ranks across IITs',
        'sql': "SELECT i.short_code AS iit, ROUND(AVG(f.closing_rank)) AS avg_closing_rank FROM fact_allotment f JOIN dim_iit i ON f.iit_id=i.iit_id JOIN dim_branch b ON f.branch_id=b.branch_id JOIN dim_seat_type s ON f.seat_type_id=s.seat_type_id JOIN dim_gender g ON f.gender_id=g.gender_id WHERE quota_id=(SELECT quota_id FROM dim_quota WHERE quota_code='AI') AND b.branch_name LIKE '%Biotechnology%' AND s.seat_type_code='OPEN' AND (g.gender_code='Gender-Neutral' OR g.gender_code='NULL') AND (f.year, f.round_no) IN (SELECT year, MAX(round_no) FROM fact_allotment GROUP BY year) AND f.is_preparatory=0 GROUP BY i.iit_id ORDER BY avg_closing_rank DESC LIMIT 12",
        'answer': 'Biotechnology closing ranks across IITs.',
        'response_type': 'chart',
        'chart': {'type': 'horizontalBar', 'x': 'iit', 'y': 'avg_closing_rank', 'title': 'Biotechnology Cutoffs by IIT'},
    },
    {
        'keywords': ['home state', 'other state', 'quota', 'hs', 'os'],
        'q': 'Compare All India quota closing ranks for CSE between old and new IITs',
        'sql': "SELECT i.generation, ROUND(AVG(f.closing_rank)) AS avg_closing_rank FROM fact_allotment f JOIN dim_iit i ON f.iit_id=i.iit_id JOIN dim_branch b ON f.branch_id=b.branch_id JOIN dim_seat_type s ON f.seat_type_id=s.seat_type_id JOIN dim_gender g ON f.gender_id=g.gender_id WHERE quota_id=(SELECT quota_id FROM dim_quota WHERE quota_code='AI') AND b.category='cse_family' AND s.seat_type_code='OPEN' AND (g.gender_code='Gender-Neutral' OR g.gender_code='NULL') AND (f.year, f.round_no) IN (SELECT year, MAX(round_no) FROM fact_allotment GROUP BY year) AND f.is_preparatory=0 GROUP BY i.generation ORDER BY avg_closing_rank ASC",
        'answer': 'Average CSE closing rank under the All India quota, split by old vs new IIT generation.',
        'response_type': 'chart',
        'chart': {'type': 'bar', 'x': 'generation', 'y': 'avg_closing_rank', 'title': 'CSE Cutoff: Old vs New IITs'},
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
