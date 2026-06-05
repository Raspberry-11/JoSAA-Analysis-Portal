<?php
declare(strict_types=1);

final class RagService
{
    private PDO $pdo;

    // Dictionary of common abbreviations
    private const GLOSSARY = [
        'iitg' => 'Indian Institute of Technology Guwahati (NOT Gandhinagar)',
        'iitgn' => 'Indian Institute of Technology Gandhinagar',
        'iitb' => 'Indian Institute of Technology Bombay',
        'iitd' => 'Indian Institute of Technology Delhi',
        'iitm' => 'Indian Institute of Technology Madras',
        'iitk' => 'Indian Institute of Technology Kanpur',
        'iitkgp' => 'Indian Institute of Technology Kharagpur',
        'iitr' => 'Indian Institute of Technology Roorkee',
        'cs' => 'Computer Science',
        'cse' => 'Computer Science and Engineering',
        'ece' => 'Electronics and Communication Engineering',
        'ee' => 'Electrical Engineering',
        'me' => 'Mechanical Engineering',
        'ce' => 'Civil Engineering',
    ];

    // Library of perfectly crafted SQL queries for common patterns
    private const GOLDEN_QUERIES = [
        [
            'keywords' => ['easiest', 'iit', 'cs', 'cse', 'computer science'],
            'q' => 'Which IIT is easiest for CS?',
            'sql' => "SELECT i.short_code AS iit, ROUND(AVG(f.closing_rank)) AS avg_closing_rank FROM fact_allotment f JOIN dim_iit i ON f.iit_id=i.iit_id JOIN dim_branch b ON f.branch_id=b.branch_id JOIN dim_seat_type s ON f.seat_type_id=s.seat_type_id JOIN dim_gender g ON f.gender_id=g.gender_id WHERE quota_id=(SELECT quota_id FROM dim_quota WHERE quota_code='AI') AND b.category='cse_family' AND s.seat_type_code='OPEN' AND (g.gender_code='Gender-Neutral' OR g.gender_code = 'NULL') AND (f.year, f.round_no) IN (SELECT year, MAX(round_no) FROM fact_allotment GROUP BY year) AND f.is_preparatory=0 GROUP BY i.iit_id ORDER BY avg_closing_rank DESC LIMIT 10",
            'answer' => "Here are the easiest IITs for Computer Science, based on the highest average closing ranks.",
            "response_type" => "chart",
            "chart" => ["type" => "horizontalBar", "x" => "iit", "y" => "avg_closing_rank", "title" => "Easiest IITs for CS"]
        ],
        [
            'keywords' => ['toughest', 'hardest', 'branch', 'iit'],
            'q' => 'What are the top 10 toughest branches?',
            'sql' => "SELECT CONCAT(i.short_code, ' · ', SUBSTRING_INDEX(b.branch_name, '(', 1)) AS iit_branch, ROUND(AVG(f.closing_rank)) AS avg_closing_rank FROM fact_allotment f JOIN dim_iit i ON f.iit_id=i.iit_id JOIN dim_branch b ON f.branch_id=b.branch_id JOIN dim_seat_type s ON f.seat_type_id=s.seat_type_id JOIN dim_gender g ON f.gender_id=g.gender_id WHERE quota_id=(SELECT quota_id FROM dim_quota WHERE quota_code='AI') AND s.seat_type_code='OPEN' AND (g.gender_code='Gender-Neutral' OR g.gender_code = 'NULL') AND (f.year, f.round_no) IN (SELECT year, MAX(round_no) FROM fact_allotment GROUP BY year) AND f.is_preparatory=0 GROUP BY i.iit_id, b.branch_id HAVING COUNT(*) >= 3 ORDER BY avg_closing_rank ASC LIMIT 10",
            'answer' => "These are the 10 toughest IIT-branch combinations to get into, ranked by lowest average closing rank.",
            "response_type" => "chart",
            "chart" => ["type" => "horizontalBar", "x" => "iit_branch", "y" => "avg_closing_rank", "title" => "Top 10 Toughest Branches"]
        ],
        [
            'keywords' => ['trend', 'year', 'closing rank', 'cse'],
            'q' => 'Show me CSE closing ranks at IIT Bombay over the years',
            'sql' => "SELECT f.year, ROUND(AVG(f.closing_rank)) AS avg_closing_rank FROM fact_allotment f JOIN dim_iit i ON f.iit_id=i.iit_id JOIN dim_branch b ON f.branch_id=b.branch_id JOIN dim_seat_type s ON f.seat_type_id=s.seat_type_id JOIN dim_gender g ON f.gender_id=g.gender_id WHERE quota_id=(SELECT quota_id FROM dim_quota WHERE quota_code='AI') AND i.iit_name='Indian Institute of Technology Bombay' AND b.category='cse_family' AND s.seat_type_code='OPEN' AND (g.gender_code='Gender-Neutral' OR g.gender_code = 'NULL') AND (f.year, f.round_no) IN (SELECT year, MAX(round_no) FROM fact_allotment GROUP BY year) AND f.is_preparatory=0 GROUP BY f.year ORDER BY f.year",
            'answer' => "CSE closing ranks at IIT Bombay have steadily tightened over the years.",
            "response_type" => "chart",
            "chart" => ["type" => "line", "x" => "year", "y" => "avg_closing_rank", "title" => "CSE Cutoffs over time (IIT Bombay)", "y_reverse" => true]
        ],
        [
            'keywords' => ['decrease', 'increase', 'difference', 'drop', 'change', 'between', 'biggest'],
            'q' => 'Which branch at IIT Kanpur saw the biggest decrease in closing rank between 2017 and 2022?',
            'sql' => "SELECT b.branch_name AS branch, early.cr AS closing_rank_early, later.cr AS closing_rank_later, (CAST(early.cr AS SIGNED) - CAST(later.cr AS SIGNED)) AS rank_drop FROM (SELECT f.branch_id, f.closing_rank AS cr FROM fact_allotment f JOIN dim_iit i ON f.iit_id=i.iit_id JOIN dim_seat_type s ON f.seat_type_id=s.seat_type_id JOIN dim_gender g ON f.gender_id=g.gender_id WHERE quota_id=(SELECT quota_id FROM dim_quota WHERE quota_code='AI') AND i.short_code='IIT Kanpur' AND f.year=2017 AND s.seat_type_code='OPEN' AND (g.gender_code='Gender-Neutral' OR g.gender_code = 'NULL') AND f.is_preparatory=0 AND f.round_no=(SELECT MAX(round_no) FROM fact_allotment WHERE year=2017)) early INNER JOIN (SELECT f.branch_id, f.closing_rank AS cr FROM fact_allotment f JOIN dim_iit i ON f.iit_id=i.iit_id JOIN dim_seat_type s ON f.seat_type_id=s.seat_type_id JOIN dim_gender g ON f.gender_id=g.gender_id WHERE quota_id=(SELECT quota_id FROM dim_quota WHERE quota_code='AI') AND i.short_code='IIT Kanpur' AND f.year=2022 AND s.seat_type_code='OPEN' AND (g.gender_code='Gender-Neutral' OR g.gender_code = 'NULL') AND f.is_preparatory=0 AND f.round_no=(SELECT MAX(round_no) FROM fact_allotment WHERE year=2022)) later ON early.branch_id = later.branch_id JOIN dim_branch b ON early.branch_id = b.branch_id ORDER BY rank_drop DESC LIMIT 1",
            'answer' => "The branch with the biggest decrease was {branch}, dropping from a closing rank of {closing_rank_early} to {closing_rank_later} — a change of {rank_drop} ranks.",
            "response_type" => "text"
        ],
        [
            'keywords' => ['electrical', 'ee', 'trend', 'delhi', 'year'],
            'q' => 'Show EE closing rank trend at IIT Delhi',
            'sql' => "SELECT f.year, ROUND(AVG(f.closing_rank)) AS avg_closing_rank FROM fact_allotment f JOIN dim_iit i ON f.iit_id=i.iit_id JOIN dim_branch b ON f.branch_id=b.branch_id JOIN dim_seat_type s ON f.seat_type_id=s.seat_type_id JOIN dim_gender g ON f.gender_id=g.gender_id WHERE quota_id=(SELECT quota_id FROM dim_quota WHERE quota_code='AI') AND i.short_code='IIT Delhi' AND b.branch_name LIKE '%Electrical Engineer%' AND s.seat_type_code='OPEN' AND (g.gender_code='Gender-Neutral' OR g.gender_code='NULL') AND (f.year, f.round_no) IN (SELECT year, MAX(round_no) FROM fact_allotment GROUP BY year) AND f.is_preparatory=0 GROUP BY f.year ORDER BY f.year",
            'answer' => "Electrical Engineering closing ranks at IIT Delhi over the years.",
            "response_type" => "chart",
            "chart" => ["type" => "line", "x" => "year", "y" => "avg_closing_rank", "title" => "EE Closing Rank Trend (IIT Delhi)", "y_reverse" => true]
        ],
        [
            'keywords' => ['mechanical', 'me', 'easiest', 'best', 'ranking'],
            'q' => 'Which IIT is easiest for Mechanical Engineering?',
            'sql' => "SELECT i.short_code AS iit, ROUND(AVG(f.closing_rank)) AS avg_closing_rank FROM fact_allotment f JOIN dim_iit i ON f.iit_id=i.iit_id JOIN dim_branch b ON f.branch_id=b.branch_id JOIN dim_seat_type s ON f.seat_type_id=s.seat_type_id JOIN dim_gender g ON f.gender_id=g.gender_id WHERE quota_id=(SELECT quota_id FROM dim_quota WHERE quota_code='AI') AND b.branch_name LIKE '%Mechanical Engineer%' AND s.seat_type_code='OPEN' AND (g.gender_code='Gender-Neutral' OR g.gender_code='NULL') AND (f.year, f.round_no) IN (SELECT year, MAX(round_no) FROM fact_allotment GROUP BY year) AND f.is_preparatory=0 GROUP BY i.iit_id ORDER BY avg_closing_rank DESC LIMIT 10",
            'answer' => "Easiest IITs for Mechanical Engineering by average closing rank.",
            "response_type" => "chart",
            "chart" => ["type" => "horizontalBar", "x" => "iit", "y" => "avg_closing_rank", "title" => "Easiest IITs for ME"]
        ],
        [
            'keywords' => ['compare', 'bombay', 'delhi', 'vs', 'comparison'],
            'q' => 'Compare CSE closing ranks between IIT Bombay and IIT Delhi over the years',
            'sql' => "SELECT f.year, i.short_code AS iit, ROUND(AVG(f.closing_rank)) AS avg_closing_rank FROM fact_allotment f JOIN dim_iit i ON f.iit_id=i.iit_id JOIN dim_branch b ON f.branch_id=b.branch_id JOIN dim_seat_type s ON f.seat_type_id=s.seat_type_id JOIN dim_gender g ON f.gender_id=g.gender_id WHERE quota_id=(SELECT quota_id FROM dim_quota WHERE quota_code='AI') AND i.short_code IN ('IIT Bombay','IIT Delhi') AND b.category='cse_family' AND s.seat_type_code='OPEN' AND (g.gender_code='Gender-Neutral' OR g.gender_code='NULL') AND (f.year, f.round_no) IN (SELECT year, MAX(round_no) FROM fact_allotment GROUP BY year) AND f.is_preparatory=0 GROUP BY f.year, i.iit_id ORDER BY f.year",
            'answer' => "CSE closing rank comparison between IIT Bombay and IIT Delhi.",
            "response_type" => "chart",
            "chart" => ["type" => "line", "x" => "year", "y" => "avg_closing_rank", "series" => "iit", "title" => "CSE: IIT Bombay vs IIT Delhi", "y_reverse" => true]
        ],
        [
            'keywords' => ['obc', 'category', 'reservation', 'cutoff'],
            'q' => 'What are OBC-NCL closing ranks for CSE at top IITs?',
            'sql' => "SELECT i.short_code AS iit, ROUND(AVG(f.closing_rank)) AS avg_closing_rank FROM fact_allotment f JOIN dim_iit i ON f.iit_id=i.iit_id JOIN dim_branch b ON f.branch_id=b.branch_id JOIN dim_seat_type s ON f.seat_type_id=s.seat_type_id JOIN dim_gender g ON f.gender_id=g.gender_id WHERE quota_id=(SELECT quota_id FROM dim_quota WHERE quota_code='AI') AND b.category='cse_family' AND s.seat_type_code='OBC-NCL' AND (g.gender_code='Gender-Neutral' OR g.gender_code='NULL') AND (f.year, f.round_no) IN (SELECT year, MAX(round_no) FROM fact_allotment GROUP BY year) AND f.is_preparatory=0 GROUP BY i.iit_id ORDER BY avg_closing_rank ASC LIMIT 10",
            'answer' => "Top 10 IITs for CSE under OBC-NCL category, ranked by competitiveness.",
            "response_type" => "chart",
            "chart" => ["type" => "horizontalBar", "x" => "iit", "y" => "avg_closing_rank", "title" => "OBC-NCL CSE Cutoffs by IIT"]
        ],
        [
            'keywords' => ['female', 'girl', 'women', 'supernumerary', 'gender'],
            'q' => 'Show female-only closing ranks for CSE across IITs',
            'sql' => "SELECT i.short_code AS iit, ROUND(AVG(f.closing_rank)) AS avg_closing_rank FROM fact_allotment f JOIN dim_iit i ON f.iit_id=i.iit_id JOIN dim_branch b ON f.branch_id=b.branch_id JOIN dim_seat_type s ON f.seat_type_id=s.seat_type_id JOIN dim_gender g ON f.gender_id=g.gender_id WHERE quota_id=(SELECT quota_id FROM dim_quota WHERE quota_code='AI') AND b.category='cse_family' AND s.seat_type_code='OPEN' AND g.gender_code='Female-only (including Supernumerary)' AND (f.year, f.round_no) IN (SELECT year, MAX(round_no) FROM fact_allotment GROUP BY year) AND f.is_preparatory=0 GROUP BY i.iit_id ORDER BY avg_closing_rank ASC LIMIT 10",
            'answer' => "Female-only CSE closing ranks across IITs.",
            "response_type" => "chart",
            "chart" => ["type" => "horizontalBar", "x" => "iit", "y" => "avg_closing_rank", "title" => "Female-only CSE Cutoffs"]
        ],
        [
            'keywords' => ['old', 'new', 'generation', 'comparison', 'average'],
            'q' => 'Compare average closing ranks of old IITs vs new IITs',
            'sql' => "SELECT i.generation, ROUND(AVG(f.closing_rank)) AS avg_closing_rank FROM fact_allotment f JOIN dim_iit i ON f.iit_id=i.iit_id JOIN dim_seat_type s ON f.seat_type_id=s.seat_type_id JOIN dim_gender g ON f.gender_id=g.gender_id WHERE quota_id=(SELECT quota_id FROM dim_quota WHERE quota_code='AI') AND s.seat_type_code='OPEN' AND (g.gender_code='Gender-Neutral' OR g.gender_code='NULL') AND (f.year, f.round_no) IN (SELECT year, MAX(round_no) FROM fact_allotment GROUP BY year) AND f.is_preparatory=0 GROUP BY i.generation",
            'answer' => "Old IITs have an average closing rank of {avg_closing_rank} compared to new IITs.",
            "response_type" => "text"
        ],
        [
            'keywords' => ['how many', 'count', 'number', 'branches', 'offered'],
            'q' => 'How many branches does each IIT offer?',
            'sql' => "SELECT i.short_code AS iit, COUNT(DISTINCT f.branch_id) AS branch_count FROM fact_allotment f JOIN dim_iit i ON f.iit_id=i.iit_id WHERE quota_id=(SELECT quota_id FROM dim_quota WHERE quota_code='AI') AND f.year=(SELECT MAX(year) FROM fact_allotment) AND f.is_preparatory=0 GROUP BY i.iit_id ORDER BY branch_count DESC",
            'answer' => "Number of branches offered by each IIT in the latest year.",
            "response_type" => "chart",
            "chart" => ["type" => "bar", "x" => "iit", "y" => "branch_count", "title" => "Branches per IIT"]
        ],
        [
            'keywords' => ['closing rank', 'specific', 'what', 'was'],
            'q' => 'What was the closing rank for EE at IIT Madras in 2023?',
            'sql' => "SELECT f.closing_rank FROM fact_allotment f JOIN dim_iit i ON f.iit_id=i.iit_id JOIN dim_branch b ON f.branch_id=b.branch_id JOIN dim_seat_type s ON f.seat_type_id=s.seat_type_id JOIN dim_gender g ON f.gender_id=g.gender_id WHERE quota_id=(SELECT quota_id FROM dim_quota WHERE quota_code='AI') AND i.short_code='IIT Madras' AND b.branch_name LIKE '%Electrical Engineer%' AND s.seat_type_code='OPEN' AND (g.gender_code='Gender-Neutral' OR g.gender_code='NULL') AND f.year=2023 AND f.is_preparatory=0 AND f.round_no=(SELECT MAX(round_no) FROM fact_allotment WHERE year=2023) LIMIT 1",
            'answer' => "The closing rank for Electrical Engineering at IIT Madras in 2023 was {closing_rank}.",
            "response_type" => "text"
        ],
        [
            'keywords' => ['sc', 'st', 'scheduled', 'caste', 'tribe'],
            'q' => 'Show SC category CSE cutoffs across IITs',
            'sql' => "SELECT i.short_code AS iit, ROUND(AVG(f.closing_rank)) AS avg_closing_rank FROM fact_allotment f JOIN dim_iit i ON f.iit_id=i.iit_id JOIN dim_branch b ON f.branch_id=b.branch_id JOIN dim_seat_type s ON f.seat_type_id=s.seat_type_id JOIN dim_gender g ON f.gender_id=g.gender_id WHERE quota_id=(SELECT quota_id FROM dim_quota WHERE quota_code='AI') AND b.category='cse_family' AND s.seat_type_code='SC' AND (g.gender_code='Gender-Neutral' OR g.gender_code='NULL') AND (f.year, f.round_no) IN (SELECT year, MAX(round_no) FROM fact_allotment GROUP BY year) AND f.is_preparatory=0 GROUP BY i.iit_id ORDER BY avg_closing_rank ASC LIMIT 15",
            'answer' => "SC category CSE closing ranks across IITs.",
            "response_type" => "chart",
            "chart" => ["type" => "horizontalBar", "x" => "iit", "y" => "avg_closing_rank", "title" => "SC CSE Cutoffs by IIT"]
        ],
        [
            'keywords' => ['ews', 'economically', 'weaker'],
            'q' => 'EWS cutoffs for top IITs in CSE',
            'sql' => "SELECT i.short_code AS iit, ROUND(AVG(f.closing_rank)) AS avg_closing_rank FROM fact_allotment f JOIN dim_iit i ON f.iit_id=i.iit_id JOIN dim_branch b ON f.branch_id=b.branch_id JOIN dim_seat_type s ON f.seat_type_id=s.seat_type_id JOIN dim_gender g ON f.gender_id=g.gender_id WHERE quota_id=(SELECT quota_id FROM dim_quota WHERE quota_code='AI') AND b.category='cse_family' AND s.seat_type_code='EWS' AND (g.gender_code='Gender-Neutral' OR g.gender_code='NULL') AND (f.year, f.round_no) IN (SELECT year, MAX(round_no) FROM fact_allotment GROUP BY year) AND f.is_preparatory=0 GROUP BY i.iit_id ORDER BY avg_closing_rank ASC LIMIT 10",
            'answer' => "EWS category CSE cutoffs across top IITs.",
            "response_type" => "chart",
            "chart" => ["type" => "horizontalBar", "x" => "iit", "y" => "avg_closing_rank", "title" => "EWS CSE Cutoffs"]
        ],
        [
            'keywords' => ['all', 'branches', 'at', 'list', 'available'],
            'q' => 'List all branches at IIT Bombay with their closing ranks in 2024',
            'sql' => "SELECT SUBSTRING_INDEX(b.branch_name, '(', 1) AS branch, f.closing_rank FROM fact_allotment f JOIN dim_iit i ON f.iit_id=i.iit_id JOIN dim_branch b ON f.branch_id=b.branch_id JOIN dim_seat_type s ON f.seat_type_id=s.seat_type_id JOIN dim_gender g ON f.gender_id=g.gender_id WHERE quota_id=(SELECT quota_id FROM dim_quota WHERE quota_code='AI') AND i.short_code='IIT Bombay' AND s.seat_type_code='OPEN' AND (g.gender_code='Gender-Neutral' OR g.gender_code='NULL') AND f.year=2024 AND f.is_preparatory=0 AND f.round_no=(SELECT MAX(round_no) FROM fact_allotment WHERE year=2024) ORDER BY f.closing_rank ASC",
            'answer' => "All branches at IIT Bombay with 2024 closing ranks.",
            "response_type" => "table"
        ],
        [
            'keywords' => ['opening', 'gap', 'spread', 'difference', 'opening rank'],
            'q' => 'Which branches have the biggest gap between opening and closing rank?',
            'sql' => "SELECT CONCAT(i.short_code, ' · ', SUBSTRING_INDEX(b.branch_name, '(', 1)) AS iit_branch, ROUND(AVG(f.opening_rank)) AS avg_opening, ROUND(AVG(f.closing_rank)) AS avg_closing, ROUND(AVG(CAST(f.closing_rank AS SIGNED) - CAST(f.opening_rank AS SIGNED))) AS avg_gap FROM fact_allotment f JOIN dim_iit i ON f.iit_id=i.iit_id JOIN dim_branch b ON f.branch_id=b.branch_id JOIN dim_seat_type s ON f.seat_type_id=s.seat_type_id JOIN dim_gender g ON f.gender_id=g.gender_id WHERE quota_id=(SELECT quota_id FROM dim_quota WHERE quota_code='AI') AND s.seat_type_code='OPEN' AND (g.gender_code='Gender-Neutral' OR g.gender_code='NULL') AND (f.year, f.round_no) IN (SELECT year, MAX(round_no) FROM fact_allotment GROUP BY year) AND f.is_preparatory=0 GROUP BY i.iit_id, b.branch_id HAVING COUNT(*)>=3 ORDER BY avg_gap DESC LIMIT 10",
            'answer' => "Branches with the widest opening-to-closing rank spread.",
            "response_type" => "chart",
            "chart" => ["type" => "horizontalBar", "x" => "iit_branch", "y" => "avg_gap", "title" => "Largest Opening-Closing Rank Gaps"]
        ],
        [
            'keywords' => ['popular', 'popularity', 'demand', 'competitive', 'most'],
            'q' => 'Which branches are getting more competitive over the years?',
            'sql' => "SELECT SUBSTRING_INDEX(b.branch_name, '(', 1) AS branch, ROUND(AVG(CASE WHEN f.year<=2019 THEN f.closing_rank END)) AS avg_early, ROUND(AVG(CASE WHEN f.year>=2022 THEN f.closing_rank END)) AS avg_recent, ROUND(AVG(CASE WHEN f.year<=2019 THEN f.closing_rank END) - AVG(CASE WHEN f.year>=2022 THEN f.closing_rank END)) AS competitiveness_gain FROM fact_allotment f JOIN dim_branch b ON f.branch_id=b.branch_id JOIN dim_seat_type s ON f.seat_type_id=s.seat_type_id JOIN dim_gender g ON f.gender_id=g.gender_id WHERE quota_id=(SELECT quota_id FROM dim_quota WHERE quota_code='AI') AND s.seat_type_code='OPEN' AND (g.gender_code='Gender-Neutral' OR g.gender_code='NULL') AND (f.year, f.round_no) IN (SELECT year, MAX(round_no) FROM fact_allotment GROUP BY year) AND f.is_preparatory=0 GROUP BY b.branch_id HAVING avg_early IS NOT NULL AND avg_recent IS NOT NULL ORDER BY competitiveness_gain DESC LIMIT 10",
            'answer' => "Branches gaining the most competitiveness (closing rank dropping).",
            "response_type" => "table"
        ],
        [
            'keywords' => ['civil', 'ce', 'civil engineering'],
            'q' => 'Show Civil Engineering closing rank trend across years',
            'sql' => "SELECT f.year, ROUND(AVG(f.closing_rank)) AS avg_closing_rank FROM fact_allotment f JOIN dim_branch b ON f.branch_id=b.branch_id JOIN dim_seat_type s ON f.seat_type_id=s.seat_type_id JOIN dim_gender g ON f.gender_id=g.gender_id WHERE quota_id=(SELECT quota_id FROM dim_quota WHERE quota_code='AI') AND b.branch_name LIKE '%Civil Engineer%' AND s.seat_type_code='OPEN' AND (g.gender_code='Gender-Neutral' OR g.gender_code='NULL') AND (f.year, f.round_no) IN (SELECT year, MAX(round_no) FROM fact_allotment GROUP BY year) AND f.is_preparatory=0 GROUP BY f.year ORDER BY f.year",
            'answer' => "Civil Engineering average closing rank trend across all IITs.",
            "response_type" => "chart",
            "chart" => ["type" => "line", "x" => "year", "y" => "avg_closing_rank", "title" => "Civil Engineering Trend (All IITs)", "y_reverse" => true]
        ],
        [
            'keywords' => ['core', 'new age', 'interdisciplinary', 'category', 'branch type'],
            'q' => 'Compare average closing ranks across branch categories (core vs CSE vs new age)',
            'sql' => "SELECT b.category, ROUND(AVG(f.closing_rank)) AS avg_closing_rank FROM fact_allotment f JOIN dim_branch b ON f.branch_id=b.branch_id JOIN dim_seat_type s ON f.seat_type_id=s.seat_type_id JOIN dim_gender g ON f.gender_id=g.gender_id WHERE quota_id=(SELECT quota_id FROM dim_quota WHERE quota_code='AI') AND s.seat_type_code='OPEN' AND (g.gender_code='Gender-Neutral' OR g.gender_code='NULL') AND (f.year, f.round_no) IN (SELECT year, MAX(round_no) FROM fact_allotment GROUP BY year) AND f.is_preparatory=0 GROUP BY b.category ORDER BY avg_closing_rank ASC",
            'answer' => "Average closing rank by branch category.",
            "response_type" => "chart",
            "chart" => ["type" => "bar", "x" => "category", "y" => "avg_closing_rank", "title" => "Closing Rank by Branch Category"]
        ],
        [
            'keywords' => ['latest', 'recent', '2024', 'current', 'newest'],
            'q' => 'Show top 10 toughest branches in the latest year',
            'sql' => "SELECT CONCAT(i.short_code, ' · ', SUBSTRING_INDEX(b.branch_name, '(', 1)) AS iit_branch, f.closing_rank FROM fact_allotment f JOIN dim_iit i ON f.iit_id=i.iit_id JOIN dim_branch b ON f.branch_id=b.branch_id JOIN dim_seat_type s ON f.seat_type_id=s.seat_type_id JOIN dim_gender g ON f.gender_id=g.gender_id WHERE quota_id=(SELECT quota_id FROM dim_quota WHERE quota_code='AI') AND s.seat_type_code='OPEN' AND (g.gender_code='Gender-Neutral' OR g.gender_code='NULL') AND f.year=(SELECT MAX(year) FROM fact_allotment) AND f.round_no=(SELECT MAX(round_no) FROM fact_allotment WHERE year=(SELECT MAX(year) FROM fact_allotment)) AND f.is_preparatory=0 ORDER BY f.closing_rank ASC LIMIT 10",
            'answer' => "The 10 toughest IIT-branch combinations in the most recent year.",
            "response_type" => "chart",
            "chart" => ["type" => "horizontalBar", "x" => "iit_branch", "y" => "closing_rank", "title" => "Top 10 Toughest (Latest Year)"]
        ],
        [
            'keywords' => ['kharagpur', 'iitkgp', 'kgp'],
            'q' => 'Show all branch cutoffs at IIT Kharagpur',
            'sql' => "SELECT SUBSTRING_INDEX(b.branch_name, '(', 1) AS branch, ROUND(AVG(f.closing_rank)) AS avg_closing_rank FROM fact_allotment f JOIN dim_iit i ON f.iit_id=i.iit_id JOIN dim_branch b ON f.branch_id=b.branch_id JOIN dim_seat_type s ON f.seat_type_id=s.seat_type_id JOIN dim_gender g ON f.gender_id=g.gender_id WHERE quota_id=(SELECT quota_id FROM dim_quota WHERE quota_code='AI') AND i.short_code='IIT Kharagpur' AND s.seat_type_code='OPEN' AND (g.gender_code='Gender-Neutral' OR g.gender_code='NULL') AND (f.year, f.round_no) IN (SELECT year, MAX(round_no) FROM fact_allotment GROUP BY year) AND f.is_preparatory=0 GROUP BY b.branch_id ORDER BY avg_closing_rank ASC",
            'answer' => "Branch cutoffs at IIT Kharagpur ranked by competitiveness.",
            "response_type" => "chart",
            "chart" => ["type" => "horizontalBar", "x" => "branch", "y" => "avg_closing_rank", "title" => "IIT Kharagpur Branch Cutoffs"]
        ],
        [
            'keywords' => ['roorkee', 'iitr'],
            'q' => 'CSE closing rank trend at IIT Roorkee',
            'sql' => "SELECT f.year, ROUND(AVG(f.closing_rank)) AS avg_closing_rank FROM fact_allotment f JOIN dim_iit i ON f.iit_id=i.iit_id JOIN dim_branch b ON f.branch_id=b.branch_id JOIN dim_seat_type s ON f.seat_type_id=s.seat_type_id JOIN dim_gender g ON f.gender_id=g.gender_id WHERE quota_id=(SELECT quota_id FROM dim_quota WHERE quota_code='AI') AND i.short_code='IIT Roorkee' AND b.category='cse_family' AND s.seat_type_code='OPEN' AND (g.gender_code='Gender-Neutral' OR g.gender_code='NULL') AND (f.year, f.round_no) IN (SELECT year, MAX(round_no) FROM fact_allotment GROUP BY year) AND f.is_preparatory=0 GROUP BY f.year ORDER BY f.year",
            'answer' => "CSE closing rank trend at IIT Roorkee.",
            "response_type" => "chart",
            "chart" => ["type" => "line", "x" => "year", "y" => "avg_closing_rank", "title" => "CSE Trend (IIT Roorkee)", "y_reverse" => true]
        ],
        [
            'keywords' => ['guwahati', 'iitg'],
            'q' => 'Show closing ranks for all branches at IIT Guwahati',
            'sql' => "SELECT SUBSTRING_INDEX(b.branch_name, '(', 1) AS branch, ROUND(AVG(f.closing_rank)) AS avg_closing_rank FROM fact_allotment f JOIN dim_iit i ON f.iit_id=i.iit_id JOIN dim_branch b ON f.branch_id=b.branch_id JOIN dim_seat_type s ON f.seat_type_id=s.seat_type_id JOIN dim_gender g ON f.gender_id=g.gender_id WHERE quota_id=(SELECT quota_id FROM dim_quota WHERE quota_code='AI') AND i.short_code='IIT Guwahati' AND s.seat_type_code='OPEN' AND (g.gender_code='Gender-Neutral' OR g.gender_code='NULL') AND (f.year, f.round_no) IN (SELECT year, MAX(round_no) FROM fact_allotment GROUP BY year) AND f.is_preparatory=0 GROUP BY b.branch_id ORDER BY avg_closing_rank ASC",
            'answer' => "All branches at IIT Guwahati by average closing rank.",
            "response_type" => "chart",
            "chart" => ["type" => "horizontalBar", "x" => "branch", "y" => "avg_closing_rank", "title" => "IIT Guwahati Branch Cutoffs"]
        ],
        [
            'keywords' => ['hyderabad', 'iith'],
            'q' => 'Show CSE trend at IIT Hyderabad',
            'sql' => "SELECT f.year, ROUND(AVG(f.closing_rank)) AS avg_closing_rank FROM fact_allotment f JOIN dim_iit i ON f.iit_id=i.iit_id JOIN dim_branch b ON f.branch_id=b.branch_id JOIN dim_seat_type s ON f.seat_type_id=s.seat_type_id JOIN dim_gender g ON f.gender_id=g.gender_id WHERE quota_id=(SELECT quota_id FROM dim_quota WHERE quota_code='AI') AND i.short_code='IIT Hyderabad' AND b.category='cse_family' AND s.seat_type_code='OPEN' AND (g.gender_code='Gender-Neutral' OR g.gender_code='NULL') AND (f.year, f.round_no) IN (SELECT year, MAX(round_no) FROM fact_allotment GROUP BY year) AND f.is_preparatory=0 GROUP BY f.year ORDER BY f.year",
            'answer' => "CSE closing rank trend at IIT Hyderabad.",
            "response_type" => "chart",
            "chart" => ["type" => "line", "x" => "year", "y" => "avg_closing_rank", "title" => "CSE Trend (IIT Hyderabad)", "y_reverse" => true]
        ],
        [
            'keywords' => ['bhu', 'varanasi', 'banaras'],
            'q' => 'Show branch cutoffs at IIT BHU Varanasi',
            'sql' => "SELECT SUBSTRING_INDEX(b.branch_name, '(', 1) AS branch, ROUND(AVG(f.closing_rank)) AS avg_closing_rank FROM fact_allotment f JOIN dim_iit i ON f.iit_id=i.iit_id JOIN dim_branch b ON f.branch_id=b.branch_id JOIN dim_seat_type s ON f.seat_type_id=s.seat_type_id JOIN dim_gender g ON f.gender_id=g.gender_id WHERE quota_id=(SELECT quota_id FROM dim_quota WHERE quota_code='AI') AND i.iit_name LIKE '%Varanasi%' AND s.seat_type_code='OPEN' AND (g.gender_code='Gender-Neutral' OR g.gender_code='NULL') AND (f.year, f.round_no) IN (SELECT year, MAX(round_no) FROM fact_allotment GROUP BY year) AND f.is_preparatory=0 GROUP BY b.branch_id ORDER BY avg_closing_rank ASC",
            'answer' => "Branch cutoffs at IIT BHU Varanasi.",
            "response_type" => "chart",
            "chart" => ["type" => "horizontalBar", "x" => "branch", "y" => "avg_closing_rank", "title" => "IIT BHU Branch Cutoffs"]
        ],
        [
            'keywords' => ['aerospace', 'aero'],
            'q' => 'Which IIT is easiest for Aerospace Engineering?',
            'sql' => "SELECT i.short_code AS iit, ROUND(AVG(f.closing_rank)) AS avg_closing_rank FROM fact_allotment f JOIN dim_iit i ON f.iit_id=i.iit_id JOIN dim_branch b ON f.branch_id=b.branch_id JOIN dim_seat_type s ON f.seat_type_id=s.seat_type_id JOIN dim_gender g ON f.gender_id=g.gender_id WHERE quota_id=(SELECT quota_id FROM dim_quota WHERE quota_code='AI') AND b.branch_name LIKE '%Aerospace%' AND s.seat_type_code='OPEN' AND (g.gender_code='Gender-Neutral' OR g.gender_code='NULL') AND (f.year, f.round_no) IN (SELECT year, MAX(round_no) FROM fact_allotment GROUP BY year) AND f.is_preparatory=0 GROUP BY i.iit_id ORDER BY avg_closing_rank DESC LIMIT 10",
            'answer' => "IITs ranked by Aerospace Engineering closing rank (easiest first).",
            "response_type" => "chart",
            "chart" => ["type" => "horizontalBar", "x" => "iit", "y" => "avg_closing_rank", "title" => "Aerospace Engineering by IIT"]
        ],
        [
            'keywords' => ['chemical', 'chemistry'],
            'q' => 'Chemical Engineering trend across all IITs',
            'sql' => "SELECT f.year, ROUND(AVG(f.closing_rank)) AS avg_closing_rank FROM fact_allotment f JOIN dim_branch b ON f.branch_id=b.branch_id JOIN dim_seat_type s ON f.seat_type_id=s.seat_type_id JOIN dim_gender g ON f.gender_id=g.gender_id WHERE quota_id=(SELECT quota_id FROM dim_quota WHERE quota_code='AI') AND b.branch_name LIKE '%Chemical Engineer%' AND s.seat_type_code='OPEN' AND (g.gender_code='Gender-Neutral' OR g.gender_code='NULL') AND (f.year, f.round_no) IN (SELECT year, MAX(round_no) FROM fact_allotment GROUP BY year) AND f.is_preparatory=0 GROUP BY f.year ORDER BY f.year",
            'answer' => "Chemical Engineering closing rank trend across all IITs.",
            "response_type" => "chart",
            "chart" => ["type" => "line", "x" => "year", "y" => "avg_closing_rank", "title" => "Chemical Engineering Trend", "y_reverse" => true]
        ],
        [
            'keywords' => ['metallurgy', 'metallurgical', 'material'],
            'q' => 'Show Metallurgical Engineering cutoffs across IITs',
            'sql' => "SELECT i.short_code AS iit, ROUND(AVG(f.closing_rank)) AS avg_closing_rank FROM fact_allotment f JOIN dim_iit i ON f.iit_id=i.iit_id JOIN dim_branch b ON f.branch_id=b.branch_id JOIN dim_seat_type s ON f.seat_type_id=s.seat_type_id JOIN dim_gender g ON f.gender_id=g.gender_id WHERE quota_id=(SELECT quota_id FROM dim_quota WHERE quota_code='AI') AND b.branch_name LIKE '%Metallur%' AND s.seat_type_code='OPEN' AND (g.gender_code='Gender-Neutral' OR g.gender_code='NULL') AND (f.year, f.round_no) IN (SELECT year, MAX(round_no) FROM fact_allotment GROUP BY year) AND f.is_preparatory=0 GROUP BY i.iit_id ORDER BY avg_closing_rank ASC",
            'answer' => "Metallurgical Engineering cutoffs by IIT.",
            "response_type" => "chart",
            "chart" => ["type" => "horizontalBar", "x" => "iit", "y" => "avg_closing_rank", "title" => "Metallurgical Eng. Cutoffs"]
        ],
        [
            'keywords' => ['mathematics', 'maths', 'computing', 'msc'],
            'q' => 'Show Mathematics and Computing cutoffs across IITs',
            'sql' => "SELECT i.short_code AS iit, ROUND(AVG(f.closing_rank)) AS avg_closing_rank FROM fact_allotment f JOIN dim_iit i ON f.iit_id=i.iit_id JOIN dim_branch b ON f.branch_id=b.branch_id JOIN dim_seat_type s ON f.seat_type_id=s.seat_type_id JOIN dim_gender g ON f.gender_id=g.gender_id WHERE quota_id=(SELECT quota_id FROM dim_quota WHERE quota_code='AI') AND b.branch_name LIKE '%Mathematics%Computing%' AND s.seat_type_code='OPEN' AND (g.gender_code='Gender-Neutral' OR g.gender_code='NULL') AND (f.year, f.round_no) IN (SELECT year, MAX(round_no) FROM fact_allotment GROUP BY year) AND f.is_preparatory=0 GROUP BY i.iit_id ORDER BY avg_closing_rank ASC",
            'answer' => "Mathematics and Computing cutoffs across IITs.",
            "response_type" => "chart",
            "chart" => ["type" => "horizontalBar", "x" => "iit", "y" => "avg_closing_rank", "title" => "Maths & Computing Cutoffs"]
        ],
        [
            'keywords' => ['biotechnology', 'biotech', 'bio'],
            'q' => 'Biotechnology closing ranks across IITs',
            'sql' => "SELECT i.short_code AS iit, ROUND(AVG(f.closing_rank)) AS avg_closing_rank FROM fact_allotment f JOIN dim_iit i ON f.iit_id=i.iit_id JOIN dim_branch b ON f.branch_id=b.branch_id JOIN dim_seat_type s ON f.seat_type_id=s.seat_type_id JOIN dim_gender g ON f.gender_id=g.gender_id WHERE quota_id=(SELECT quota_id FROM dim_quota WHERE quota_code='AI') AND b.branch_name LIKE '%Biotechnol%' AND s.seat_type_code='OPEN' AND (g.gender_code='Gender-Neutral' OR g.gender_code='NULL') AND (f.year, f.round_no) IN (SELECT year, MAX(round_no) FROM fact_allotment GROUP BY year) AND f.is_preparatory=0 GROUP BY i.iit_id ORDER BY avg_closing_rank ASC",
            'answer' => "Biotechnology cutoffs across IITs.",
            "response_type" => "chart",
            "chart" => ["type" => "horizontalBar", "x" => "iit", "y" => "avg_closing_rank", "title" => "Biotechnology Cutoffs"]
        ],
        [
            'keywords' => ['open', 'obc', 'compare', 'category', 'general'],
            'q' => 'Compare OPEN vs OBC closing ranks for CSE over the years',
            'sql' => "SELECT f.year, s.seat_type_code AS category, ROUND(AVG(f.closing_rank)) AS avg_closing_rank FROM fact_allotment f JOIN dim_branch b ON f.branch_id=b.branch_id JOIN dim_seat_type s ON f.seat_type_id=s.seat_type_id JOIN dim_gender g ON f.gender_id=g.gender_id WHERE quota_id=(SELECT quota_id FROM dim_quota WHERE quota_code='AI') AND b.category='cse_family' AND s.seat_type_code IN ('OPEN','OBC-NCL') AND (g.gender_code='Gender-Neutral' OR g.gender_code='NULL') AND (f.year, f.round_no) IN (SELECT year, MAX(round_no) FROM fact_allotment GROUP BY year) AND f.is_preparatory=0 GROUP BY f.year, s.seat_type_code ORDER BY f.year",
            'answer' => "OPEN vs OBC-NCL CSE closing rank comparison over the years.",
            "response_type" => "chart",
            "chart" => ["type" => "line", "x" => "year", "y" => "avg_closing_rank", "series" => "category", "title" => "OPEN vs OBC CSE Trend", "y_reverse" => true]
        ],
        [
            'keywords' => ['three', 'multiple', 'madras', 'top iits', 'compare'],
            'q' => 'Compare CSE at IIT Bombay, Delhi and Madras',
            'sql' => "SELECT f.year, i.short_code AS iit, ROUND(AVG(f.closing_rank)) AS avg_closing_rank FROM fact_allotment f JOIN dim_iit i ON f.iit_id=i.iit_id JOIN dim_branch b ON f.branch_id=b.branch_id JOIN dim_seat_type s ON f.seat_type_id=s.seat_type_id JOIN dim_gender g ON f.gender_id=g.gender_id WHERE quota_id=(SELECT quota_id FROM dim_quota WHERE quota_code='AI') AND i.short_code IN ('IIT Bombay','IIT Delhi','IIT Madras') AND b.category='cse_family' AND s.seat_type_code='OPEN' AND (g.gender_code='Gender-Neutral' OR g.gender_code='NULL') AND (f.year, f.round_no) IN (SELECT year, MAX(round_no) FROM fact_allotment GROUP BY year) AND f.is_preparatory=0 GROUP BY f.year, i.iit_id ORDER BY f.year",
            'answer' => "CSE closing rank comparison across the top 3 IITs.",
            "response_type" => "chart",
            "chart" => ["type" => "line", "x" => "year", "y" => "avg_closing_rank", "series" => "iit", "title" => "CSE: Bombay vs Delhi vs Madras", "y_reverse" => true]
        ],
        [
            'keywords' => ['quota', 'home state', 'all india', 'hs', 'ai'],
            'q' => 'Compare All India vs Home State quota cutoffs',
            'sql' => "SELECT q.quota_code, ROUND(AVG(f.closing_rank)) AS avg_closing_rank FROM fact_allotment f JOIN dim_quota q ON f.quota_id=q.quota_id JOIN dim_seat_type s ON f.seat_type_id=s.seat_type_id JOIN dim_gender g ON f.gender_id=g.gender_id WHERE q.quota_code IN ('AI','HS') AND s.seat_type_code='OPEN' AND (g.gender_code='Gender-Neutral' OR g.gender_code='NULL') AND (f.year, f.round_no) IN (SELECT year, MAX(round_no) FROM fact_allotment GROUP BY year) AND f.is_preparatory=0 GROUP BY q.quota_code",
            'answer' => "Comparison between All India and Home State quotas.",
            "response_type" => "text"
        ],
        [
            'keywords' => ['under', 'below', 'within', 'rank', 'chance', 'get'],
            'q' => 'Which branches can I get at rank 5000?',
            'sql' => "SELECT CONCAT(i.short_code, ' · ', SUBSTRING_INDEX(b.branch_name, '(', 1)) AS iit_branch, f.closing_rank FROM fact_allotment f JOIN dim_iit i ON f.iit_id=i.iit_id JOIN dim_branch b ON f.branch_id=b.branch_id JOIN dim_seat_type s ON f.seat_type_id=s.seat_type_id JOIN dim_gender g ON f.gender_id=g.gender_id WHERE quota_id=(SELECT quota_id FROM dim_quota WHERE quota_code='AI') AND f.closing_rank >= 5000 AND s.seat_type_code='OPEN' AND (g.gender_code='Gender-Neutral' OR g.gender_code='NULL') AND f.year=(SELECT MAX(year) FROM fact_allotment) AND f.round_no=(SELECT MAX(round_no) FROM fact_allotment WHERE year=(SELECT MAX(year) FROM fact_allotment)) AND f.is_preparatory=0 ORDER BY f.closing_rank ASC LIMIT 20",
            'answer' => "Branches where closing rank is around 5000 or higher in the latest year.",
            "response_type" => "table"
        ],
        [
            'keywords' => ['overall', 'system', 'average', 'all iits', 'mean'],
            'q' => 'What is the overall average closing rank trend across all IITs?',
            'sql' => "SELECT f.year, ROUND(AVG(f.closing_rank)) AS avg_closing_rank FROM fact_allotment f JOIN dim_seat_type s ON f.seat_type_id=s.seat_type_id JOIN dim_gender g ON f.gender_id=g.gender_id WHERE quota_id=(SELECT quota_id FROM dim_quota WHERE quota_code='AI') AND s.seat_type_code='OPEN' AND (g.gender_code='Gender-Neutral' OR g.gender_code='NULL') AND (f.year, f.round_no) IN (SELECT year, MAX(round_no) FROM fact_allotment GROUP BY year) AND f.is_preparatory=0 GROUP BY f.year ORDER BY f.year",
            'answer' => "Overall average closing rank across all IITs and branches by year.",
            "response_type" => "chart",
            "chart" => ["type" => "line", "x" => "year", "y" => "avg_closing_rank", "title" => "Overall System Closing Rank Trend", "y_reverse" => true]
        ],
        [
            'keywords' => ['improved', 'better', 'gain', 'progress', 'rose'],
            'q' => 'Which IIT improved the most in CSE closing rank?',
            'sql' => "SELECT i.short_code AS iit, ROUND(AVG(CASE WHEN f.year<=2019 THEN f.closing_rank END)) AS avg_early, ROUND(AVG(CASE WHEN f.year>=2022 THEN f.closing_rank END)) AS avg_recent, ROUND(AVG(CASE WHEN f.year<=2019 THEN f.closing_rank END) - AVG(CASE WHEN f.year>=2022 THEN f.closing_rank END)) AS improvement FROM fact_allotment f JOIN dim_iit i ON f.iit_id=i.iit_id JOIN dim_branch b ON f.branch_id=b.branch_id JOIN dim_seat_type s ON f.seat_type_id=s.seat_type_id JOIN dim_gender g ON f.gender_id=g.gender_id WHERE quota_id=(SELECT quota_id FROM dim_quota WHERE quota_code='AI') AND b.category='cse_family' AND s.seat_type_code='OPEN' AND (g.gender_code='Gender-Neutral' OR g.gender_code='NULL') AND (f.year, f.round_no) IN (SELECT year, MAX(round_no) FROM fact_allotment GROUP BY year) AND f.is_preparatory=0 GROUP BY i.iit_id HAVING avg_early IS NOT NULL AND avg_recent IS NOT NULL ORDER BY improvement DESC LIMIT 10",
            'answer' => "IITs that improved the most in CSE competitiveness.",
            "response_type" => "table"
        ],
        [
            'keywords' => ['worst', 'least', 'unpopular', 'highest closing', 'easiest branch'],
            'q' => 'What are the least competitive branches overall?',
            'sql' => "SELECT CONCAT(i.short_code, ' · ', SUBSTRING_INDEX(b.branch_name, '(', 1)) AS iit_branch, ROUND(AVG(f.closing_rank)) AS avg_closing_rank FROM fact_allotment f JOIN dim_iit i ON f.iit_id=i.iit_id JOIN dim_branch b ON f.branch_id=b.branch_id JOIN dim_seat_type s ON f.seat_type_id=s.seat_type_id JOIN dim_gender g ON f.gender_id=g.gender_id WHERE quota_id=(SELECT quota_id FROM dim_quota WHERE quota_code='AI') AND s.seat_type_code='OPEN' AND (g.gender_code='Gender-Neutral' OR g.gender_code='NULL') AND (f.year, f.round_no) IN (SELECT year, MAX(round_no) FROM fact_allotment GROUP BY year) AND f.is_preparatory=0 GROUP BY i.iit_id, b.branch_id HAVING COUNT(*)>=3 ORDER BY avg_closing_rank DESC LIMIT 10",
            'answer' => "The 10 least competitive IIT-branch combinations.",
            "response_type" => "chart",
            "chart" => ["type" => "horizontalBar", "x" => "iit_branch", "y" => "avg_closing_rank", "title" => "Least Competitive Branches"]
        ],
        [
            'keywords' => ['gandhinagar', 'iitgn'],
            'q' => 'Show branch cutoffs at IIT Gandhinagar',
            'sql' => "SELECT SUBSTRING_INDEX(b.branch_name, '(', 1) AS branch, ROUND(AVG(f.closing_rank)) AS avg_closing_rank FROM fact_allotment f JOIN dim_iit i ON f.iit_id=i.iit_id JOIN dim_branch b ON f.branch_id=b.branch_id JOIN dim_seat_type s ON f.seat_type_id=s.seat_type_id JOIN dim_gender g ON f.gender_id=g.gender_id WHERE quota_id=(SELECT quota_id FROM dim_quota WHERE quota_code='AI') AND i.short_code='IIT Gandhinagar' AND s.seat_type_code='OPEN' AND (g.gender_code='Gender-Neutral' OR g.gender_code='NULL') AND (f.year, f.round_no) IN (SELECT year, MAX(round_no) FROM fact_allotment GROUP BY year) AND f.is_preparatory=0 GROUP BY b.branch_id ORDER BY avg_closing_rank ASC",
            'answer' => "Branch cutoffs at IIT Gandhinagar.",
            "response_type" => "chart",
            "chart" => ["type" => "horizontalBar", "x" => "branch", "y" => "avg_closing_rank", "title" => "IIT Gandhinagar Branch Cutoffs"]
        ],
        [
            'keywords' => ['indore', 'iiti'],
            'q' => 'Show CSE trend at IIT Indore',
            'sql' => "SELECT f.year, ROUND(AVG(f.closing_rank)) AS avg_closing_rank FROM fact_allotment f JOIN dim_iit i ON f.iit_id=i.iit_id JOIN dim_branch b ON f.branch_id=b.branch_id JOIN dim_seat_type s ON f.seat_type_id=s.seat_type_id JOIN dim_gender g ON f.gender_id=g.gender_id WHERE quota_id=(SELECT quota_id FROM dim_quota WHERE quota_code='AI') AND i.short_code='IIT Indore' AND b.category='cse_family' AND s.seat_type_code='OPEN' AND (g.gender_code='Gender-Neutral' OR g.gender_code='NULL') AND (f.year, f.round_no) IN (SELECT year, MAX(round_no) FROM fact_allotment GROUP BY year) AND f.is_preparatory=0 GROUP BY f.year ORDER BY f.year",
            'answer' => "CSE closing rank trend at IIT Indore.",
            "response_type" => "chart",
            "chart" => ["type" => "line", "x" => "year", "y" => "avg_closing_rank", "title" => "CSE Trend (IIT Indore)", "y_reverse" => true]
        ],
        [
            'keywords' => ['mandi'],
            'q' => 'Show branch cutoffs at IIT Mandi',
            'sql' => "SELECT SUBSTRING_INDEX(b.branch_name, '(', 1) AS branch, ROUND(AVG(f.closing_rank)) AS avg_closing_rank FROM fact_allotment f JOIN dim_iit i ON f.iit_id=i.iit_id JOIN dim_branch b ON f.branch_id=b.branch_id JOIN dim_seat_type s ON f.seat_type_id=s.seat_type_id JOIN dim_gender g ON f.gender_id=g.gender_id WHERE quota_id=(SELECT quota_id FROM dim_quota WHERE quota_code='AI') AND i.short_code='IIT Mandi' AND s.seat_type_code='OPEN' AND (g.gender_code='Gender-Neutral' OR g.gender_code='NULL') AND (f.year, f.round_no) IN (SELECT year, MAX(round_no) FROM fact_allotment GROUP BY year) AND f.is_preparatory=0 GROUP BY b.branch_id ORDER BY avg_closing_rank ASC",
            'answer' => "Branch cutoffs at IIT Mandi.",
            "response_type" => "chart",
            "chart" => ["type" => "horizontalBar", "x" => "branch", "y" => "avg_closing_rank", "title" => "IIT Mandi Branch Cutoffs"]
        ],
        [
            'keywords' => ['jodhpur', 'iitj'],
            'q' => 'Show branch cutoffs at IIT Jodhpur',
            'sql' => "SELECT SUBSTRING_INDEX(b.branch_name, '(', 1) AS branch, ROUND(AVG(f.closing_rank)) AS avg_closing_rank FROM fact_allotment f JOIN dim_iit i ON f.iit_id=i.iit_id JOIN dim_branch b ON f.branch_id=b.branch_id JOIN dim_seat_type s ON f.seat_type_id=s.seat_type_id JOIN dim_gender g ON f.gender_id=g.gender_id WHERE quota_id=(SELECT quota_id FROM dim_quota WHERE quota_code='AI') AND i.short_code='IIT Jodhpur' AND s.seat_type_code='OPEN' AND (g.gender_code='Gender-Neutral' OR g.gender_code='NULL') AND (f.year, f.round_no) IN (SELECT year, MAX(round_no) FROM fact_allotment GROUP BY year) AND f.is_preparatory=0 GROUP BY b.branch_id ORDER BY avg_closing_rank ASC",
            'answer' => "Branch cutoffs at IIT Jodhpur.",
            "response_type" => "chart",
            "chart" => ["type" => "horizontalBar", "x" => "branch", "y" => "avg_closing_rank", "title" => "IIT Jodhpur Branch Cutoffs"]
        ],
        [
            'keywords' => ['patna', 'iitp'],
            'q' => 'Show branch cutoffs at IIT Patna',
            'sql' => "SELECT SUBSTRING_INDEX(b.branch_name, '(', 1) AS branch, ROUND(AVG(f.closing_rank)) AS avg_closing_rank FROM fact_allotment f JOIN dim_iit i ON f.iit_id=i.iit_id JOIN dim_branch b ON f.branch_id=b.branch_id JOIN dim_seat_type s ON f.seat_type_id=s.seat_type_id JOIN dim_gender g ON f.gender_id=g.gender_id WHERE quota_id=(SELECT quota_id FROM dim_quota WHERE quota_code='AI') AND i.short_code='IIT Patna' AND s.seat_type_code='OPEN' AND (g.gender_code='Gender-Neutral' OR g.gender_code='NULL') AND (f.year, f.round_no) IN (SELECT year, MAX(round_no) FROM fact_allotment GROUP BY year) AND f.is_preparatory=0 GROUP BY b.branch_id ORDER BY avg_closing_rank ASC",
            'answer' => "Branch cutoffs at IIT Patna.",
            "response_type" => "chart",
            "chart" => ["type" => "horizontalBar", "x" => "branch", "y" => "avg_closing_rank", "title" => "IIT Patna Branch Cutoffs"]
        ],
        [
            'keywords' => ['tirupati'],
            'q' => 'Show CSE trend at IIT Tirupati',
            'sql' => "SELECT f.year, ROUND(AVG(f.closing_rank)) AS avg_closing_rank FROM fact_allotment f JOIN dim_iit i ON f.iit_id=i.iit_id JOIN dim_branch b ON f.branch_id=b.branch_id JOIN dim_seat_type s ON f.seat_type_id=s.seat_type_id JOIN dim_gender g ON f.gender_id=g.gender_id WHERE quota_id=(SELECT quota_id FROM dim_quota WHERE quota_code='AI') AND i.short_code='IIT Tirupati' AND b.category='cse_family' AND s.seat_type_code='OPEN' AND (g.gender_code='Gender-Neutral' OR g.gender_code='NULL') AND (f.year, f.round_no) IN (SELECT year, MAX(round_no) FROM fact_allotment GROUP BY year) AND f.is_preparatory=0 GROUP BY f.year ORDER BY f.year",
            'answer' => "CSE trend at IIT Tirupati.",
            "response_type" => "chart",
            "chart" => ["type" => "line", "x" => "year", "y" => "avg_closing_rank", "title" => "CSE Trend (IIT Tirupati)", "y_reverse" => true]
        ],
        [
            'keywords' => ['dharwad', 'goa', 'palakkad', 'bhilai', 'jammu'],
            'q' => 'Show CSE cutoffs at newer IITs (Dharwad, Goa, Palakkad, Bhilai, Jammu)',
            'sql' => "SELECT i.short_code AS iit, ROUND(AVG(f.closing_rank)) AS avg_closing_rank FROM fact_allotment f JOIN dim_iit i ON f.iit_id=i.iit_id JOIN dim_branch b ON f.branch_id=b.branch_id JOIN dim_seat_type s ON f.seat_type_id=s.seat_type_id JOIN dim_gender g ON f.gender_id=g.gender_id WHERE quota_id=(SELECT quota_id FROM dim_quota WHERE quota_code='AI') AND i.generation='new' AND b.category='cse_family' AND s.seat_type_code='OPEN' AND (g.gender_code='Gender-Neutral' OR g.gender_code='NULL') AND (f.year, f.round_no) IN (SELECT year, MAX(round_no) FROM fact_allotment GROUP BY year) AND f.is_preparatory=0 GROUP BY i.iit_id ORDER BY avg_closing_rank ASC",
            'answer' => "CSE cutoffs across all new-generation IITs.",
            "response_type" => "chart",
            "chart" => ["type" => "horizontalBar", "x" => "iit", "y" => "avg_closing_rank", "title" => "New IITs CSE Cutoffs"]
        ],
        [
            'keywords' => ['physics', 'engineering physics', 'ep'],
            'q' => 'Engineering Physics cutoffs across IITs',
            'sql' => "SELECT i.short_code AS iit, ROUND(AVG(f.closing_rank)) AS avg_closing_rank FROM fact_allotment f JOIN dim_iit i ON f.iit_id=i.iit_id JOIN dim_branch b ON f.branch_id=b.branch_id JOIN dim_seat_type s ON f.seat_type_id=s.seat_type_id JOIN dim_gender g ON f.gender_id=g.gender_id WHERE quota_id=(SELECT quota_id FROM dim_quota WHERE quota_code='AI') AND b.branch_name LIKE '%Engineering Physics%' AND s.seat_type_code='OPEN' AND (g.gender_code='Gender-Neutral' OR g.gender_code='NULL') AND (f.year, f.round_no) IN (SELECT year, MAX(round_no) FROM fact_allotment GROUP BY year) AND f.is_preparatory=0 GROUP BY i.iit_id ORDER BY avg_closing_rank ASC",
            'answer' => "Engineering Physics cutoffs across IITs.",
            "response_type" => "chart",
            "chart" => ["type" => "horizontalBar", "x" => "iit", "y" => "avg_closing_rank", "title" => "Engineering Physics Cutoffs"]
        ],
        [
            'keywords' => ['mining', 'mineral', 'textile'],
            'q' => 'Show Mining Engineering cutoffs',
            'sql' => "SELECT i.short_code AS iit, ROUND(AVG(f.closing_rank)) AS avg_closing_rank FROM fact_allotment f JOIN dim_iit i ON f.iit_id=i.iit_id JOIN dim_branch b ON f.branch_id=b.branch_id JOIN dim_seat_type s ON f.seat_type_id=s.seat_type_id JOIN dim_gender g ON f.gender_id=g.gender_id WHERE quota_id=(SELECT quota_id FROM dim_quota WHERE quota_code='AI') AND b.branch_name LIKE '%Mining%' AND s.seat_type_code='OPEN' AND (g.gender_code='Gender-Neutral' OR g.gender_code='NULL') AND (f.year, f.round_no) IN (SELECT year, MAX(round_no) FROM fact_allotment GROUP BY year) AND f.is_preparatory=0 GROUP BY i.iit_id ORDER BY avg_closing_rank ASC",
            'answer' => "Mining Engineering cutoffs by IIT.",
            "response_type" => "chart",
            "chart" => ["type" => "horizontalBar", "x" => "iit", "y" => "avg_closing_rank", "title" => "Mining Engineering Cutoffs"]
        ],
        [
            'keywords' => ['2016', '2024', 'compare years', 'first', 'last'],
            'q' => 'Compare CSE cutoffs in 2016 vs 2024',
            'sql' => "SELECT f.year, i.short_code AS iit, f.closing_rank FROM fact_allotment f JOIN dim_iit i ON f.iit_id=i.iit_id JOIN dim_branch b ON f.branch_id=b.branch_id JOIN dim_seat_type s ON f.seat_type_id=s.seat_type_id JOIN dim_gender g ON f.gender_id=g.gender_id WHERE quota_id=(SELECT quota_id FROM dim_quota WHERE quota_code='AI') AND f.year IN (2016, 2024) AND b.category='cse_family' AND s.seat_type_code='OPEN' AND (g.gender_code='Gender-Neutral' OR g.gender_code='NULL') AND f.round_no=(SELECT MAX(round_no) FROM fact_allotment fa WHERE fa.year=f.year) AND f.is_preparatory=0 AND i.generation='old' ORDER BY i.short_code, f.year",
            'answer' => "CSE closing ranks at old IITs in 2016 vs 2024.",
            "response_type" => "table"
        ],
        [
            'keywords' => ['gender', 'male', 'female', 'trend', 'over time'],
            'q' => 'Show gender-neutral vs female-only CSE trend at IIT Bombay',
            'sql' => "SELECT f.year, g.gender_code AS gender, ROUND(AVG(f.closing_rank)) AS avg_closing_rank FROM fact_allotment f JOIN dim_iit i ON f.iit_id=i.iit_id JOIN dim_branch b ON f.branch_id=b.branch_id JOIN dim_seat_type s ON f.seat_type_id=s.seat_type_id JOIN dim_gender g ON f.gender_id=g.gender_id WHERE quota_id=(SELECT quota_id FROM dim_quota WHERE quota_code='AI') AND i.short_code='IIT Bombay' AND b.category='cse_family' AND s.seat_type_code='OPEN' AND g.gender_code IN ('Gender-Neutral','Female-only (including Supernumerary)') AND (f.year, f.round_no) IN (SELECT year, MAX(round_no) FROM fact_allotment GROUP BY year) AND f.is_preparatory=0 GROUP BY f.year, g.gender_code ORDER BY f.year",
            'answer' => "Gender-neutral vs female-only CSE closing rank trend at IIT Bombay.",
            "response_type" => "chart",
            "chart" => ["type" => "line", "x" => "year", "y" => "avg_closing_rank", "series" => "gender", "title" => "Gender Comparison: CSE at IIT Bombay", "y_reverse" => true]
        ],
        [
            'keywords' => ['round', 'rounds', 'round wise', 'each round'],
            'q' => 'How does closing rank change across rounds for CSE at IIT Bombay in 2023?',
            'sql' => "SELECT f.round_no, f.closing_rank FROM fact_allotment f JOIN dim_iit i ON f.iit_id=i.iit_id JOIN dim_branch b ON f.branch_id=b.branch_id JOIN dim_seat_type s ON f.seat_type_id=s.seat_type_id JOIN dim_gender g ON f.gender_id=g.gender_id WHERE quota_id=(SELECT quota_id FROM dim_quota WHERE quota_code='AI') AND i.short_code='IIT Bombay' AND b.category='cse_family' AND s.seat_type_code='OPEN' AND (g.gender_code='Gender-Neutral' OR g.gender_code='NULL') AND f.year=2023 AND f.is_preparatory=0 ORDER BY f.round_no",
            'answer' => "Round-wise closing rank progression for CSE at IIT Bombay in 2023.",
            "response_type" => "chart",
            "chart" => ["type" => "line", "x" => "round_no", "y" => "closing_rank", "title" => "Round-wise CSE Cutoff (IIT Bombay 2023)", "y_reverse" => true]
        ],
        [
            'keywords' => ['seat type', 'all categories', 'category wise', 'reservation wise'],
            'q' => 'Compare cutoffs across all seat type categories for CSE at IIT Bombay',
            'sql' => "SELECT s.seat_type_code AS category, ROUND(AVG(f.closing_rank)) AS avg_closing_rank FROM fact_allotment f JOIN dim_iit i ON f.iit_id=i.iit_id JOIN dim_branch b ON f.branch_id=b.branch_id JOIN dim_seat_type s ON f.seat_type_id=s.seat_type_id JOIN dim_gender g ON f.gender_id=g.gender_id WHERE quota_id=(SELECT quota_id FROM dim_quota WHERE quota_code='AI') AND i.short_code='IIT Bombay' AND b.category='cse_family' AND (g.gender_code='Gender-Neutral' OR g.gender_code='NULL') AND (f.year, f.round_no) IN (SELECT year, MAX(round_no) FROM fact_allotment GROUP BY year) AND f.is_preparatory=0 GROUP BY s.seat_type_code ORDER BY avg_closing_rank ASC",
            'answer' => "CSE cutoffs at IIT Bombay broken down by seat type category.",
            "response_type" => "chart",
            "chart" => ["type" => "bar", "x" => "category", "y" => "avg_closing_rank", "title" => "Category-wise CSE Cutoffs (IIT Bombay)"]
        ],
        [
            'keywords' => ['preparatory', 'prep', 'prep year'],
            'q' => 'Show preparatory rank cutoffs for CSE',
            'sql' => "SELECT i.short_code AS iit, ROUND(AVG(f.closing_rank)) AS avg_closing_rank FROM fact_allotment f JOIN dim_iit i ON f.iit_id=i.iit_id JOIN dim_branch b ON f.branch_id=b.branch_id JOIN dim_seat_type s ON f.seat_type_id=s.seat_type_id JOIN dim_gender g ON f.gender_id=g.gender_id WHERE quota_id=(SELECT quota_id FROM dim_quota WHERE quota_code='AI') AND b.category='cse_family' AND s.seat_type_code='OPEN' AND (g.gender_code='Gender-Neutral' OR g.gender_code='NULL') AND (f.year, f.round_no) IN (SELECT year, MAX(round_no) FROM fact_allotment GROUP BY year) AND f.is_preparatory=1 GROUP BY i.iit_id ORDER BY avg_closing_rank ASC LIMIT 10",
            'answer' => "Preparatory rank CSE cutoffs across IITs.",
            "response_type" => "chart",
            "chart" => ["type" => "horizontalBar", "x" => "iit", "y" => "avg_closing_rank", "title" => "Preparatory CSE Cutoffs"]
        ],
        [
            'keywords' => ['how many', 'iits', 'total', 'count iit'],
            'q' => 'How many IITs are there in each year?',
            'sql' => "SELECT f.year, COUNT(DISTINCT f.iit_id) AS iit_count FROM fact_allotment f WHERE quota_id=(SELECT quota_id FROM dim_quota WHERE quota_code='AI') AND f.is_preparatory=0 GROUP BY f.year ORDER BY f.year",
            'answer' => "Number of IITs participating in JOSAA each year.",
            "response_type" => "chart",
            "chart" => ["type" => "bar", "x" => "year", "y" => "iit_count", "title" => "Number of IITs per Year"]
        ],
        [
            'keywords' => ['rank', 'iit', 'overall', 'best iit', 'top iit'],
            'q' => 'Rank all IITs by overall average closing rank',
            'sql' => "SELECT i.short_code AS iit, i.generation, ROUND(AVG(f.closing_rank)) AS avg_closing_rank FROM fact_allotment f JOIN dim_iit i ON f.iit_id=i.iit_id JOIN dim_seat_type s ON f.seat_type_id=s.seat_type_id JOIN dim_gender g ON f.gender_id=g.gender_id WHERE quota_id=(SELECT quota_id FROM dim_quota WHERE quota_code='AI') AND s.seat_type_code='OPEN' AND (g.gender_code='Gender-Neutral' OR g.gender_code='NULL') AND (f.year, f.round_no) IN (SELECT year, MAX(round_no) FROM fact_allotment GROUP BY year) AND f.is_preparatory=0 GROUP BY i.iit_id ORDER BY avg_closing_rank ASC",
            'answer' => "All IITs ranked by overall average closing rank (most competitive first).",
            "response_type" => "table"
        ],
        [
            'keywords' => ['st', 'scheduled tribe'],
            'q' => 'Show ST category CSE cutoffs',
            'sql' => "SELECT i.short_code AS iit, ROUND(AVG(f.closing_rank)) AS avg_closing_rank FROM fact_allotment f JOIN dim_iit i ON f.iit_id=i.iit_id JOIN dim_branch b ON f.branch_id=b.branch_id JOIN dim_seat_type s ON f.seat_type_id=s.seat_type_id JOIN dim_gender g ON f.gender_id=g.gender_id WHERE quota_id=(SELECT quota_id FROM dim_quota WHERE quota_code='AI') AND b.category='cse_family' AND s.seat_type_code='ST' AND (g.gender_code='Gender-Neutral' OR g.gender_code='NULL') AND (f.year, f.round_no) IN (SELECT year, MAX(round_no) FROM fact_allotment GROUP BY year) AND f.is_preparatory=0 GROUP BY i.iit_id ORDER BY avg_closing_rank ASC LIMIT 15",
            'answer' => "ST category CSE cutoffs across IITs.",
            "response_type" => "chart",
            "chart" => ["type" => "horizontalBar", "x" => "iit", "y" => "avg_closing_rank", "title" => "ST CSE Cutoffs"]
        ],
        [
            'keywords' => ['pwd', 'disability', 'disabled', 'physically'],
            'q' => 'Show PwD category cutoffs for CSE',
            'sql' => "SELECT i.short_code AS iit, ROUND(AVG(f.closing_rank)) AS avg_closing_rank FROM fact_allotment f JOIN dim_iit i ON f.iit_id=i.iit_id JOIN dim_branch b ON f.branch_id=b.branch_id JOIN dim_seat_type s ON f.seat_type_id=s.seat_type_id JOIN dim_gender g ON f.gender_id=g.gender_id WHERE quota_id=(SELECT quota_id FROM dim_quota WHERE quota_code='AI') AND b.category='cse_family' AND s.seat_type_code LIKE '%PwD%' AND (g.gender_code='Gender-Neutral' OR g.gender_code='NULL') AND (f.year, f.round_no) IN (SELECT year, MAX(round_no) FROM fact_allotment GROUP BY year) AND f.is_preparatory=0 GROUP BY i.iit_id ORDER BY avg_closing_rank ASC LIMIT 15",
            'answer' => "PwD category CSE cutoffs across IITs.",
            "response_type" => "chart",
            "chart" => ["type" => "horizontalBar", "x" => "iit", "y" => "avg_closing_rank", "title" => "PwD CSE Cutoffs"]
        ],
        [
            'keywords' => ['kanpur', 'iitk'],
            'q' => 'Show all branch cutoffs at IIT Kanpur',
            'sql' => "SELECT SUBSTRING_INDEX(b.branch_name, '(', 1) AS branch, ROUND(AVG(f.closing_rank)) AS avg_closing_rank FROM fact_allotment f JOIN dim_iit i ON f.iit_id=i.iit_id JOIN dim_branch b ON f.branch_id=b.branch_id JOIN dim_seat_type s ON f.seat_type_id=s.seat_type_id JOIN dim_gender g ON f.gender_id=g.gender_id WHERE quota_id=(SELECT quota_id FROM dim_quota WHERE quota_code='AI') AND i.short_code='IIT Kanpur' AND s.seat_type_code='OPEN' AND (g.gender_code='Gender-Neutral' OR g.gender_code='NULL') AND (f.year, f.round_no) IN (SELECT year, MAX(round_no) FROM fact_allotment GROUP BY year) AND f.is_preparatory=0 GROUP BY b.branch_id ORDER BY avg_closing_rank ASC",
            'answer' => "All branch cutoffs at IIT Kanpur.",
            "response_type" => "chart",
            "chart" => ["type" => "horizontalBar", "x" => "branch", "y" => "avg_closing_rank", "title" => "IIT Kanpur Branch Cutoffs"]
        ],
        [
            'keywords' => ['madras', 'iitm', 'chennai'],
            'q' => 'Show all branch cutoffs at IIT Madras',
            'sql' => "SELECT SUBSTRING_INDEX(b.branch_name, '(', 1) AS branch, ROUND(AVG(f.closing_rank)) AS avg_closing_rank FROM fact_allotment f JOIN dim_iit i ON f.iit_id=i.iit_id JOIN dim_branch b ON f.branch_id=b.branch_id JOIN dim_seat_type s ON f.seat_type_id=s.seat_type_id JOIN dim_gender g ON f.gender_id=g.gender_id WHERE quota_id=(SELECT quota_id FROM dim_quota WHERE quota_code='AI') AND i.short_code='IIT Madras' AND s.seat_type_code='OPEN' AND (g.gender_code='Gender-Neutral' OR g.gender_code='NULL') AND (f.year, f.round_no) IN (SELECT year, MAX(round_no) FROM fact_allotment GROUP BY year) AND f.is_preparatory=0 GROUP BY b.branch_id ORDER BY avg_closing_rank ASC",
            'answer' => "All branch cutoffs at IIT Madras.",
            "response_type" => "chart",
            "chart" => ["type" => "horizontalBar", "x" => "branch", "y" => "avg_closing_rank", "title" => "IIT Madras Branch Cutoffs"]
        ],
        [
            'keywords' => ['1000', 'top 1000', 'rank 1000'],
            'q' => 'Which branches can I get with rank under 1000?',
            'sql' => "SELECT CONCAT(i.short_code, ' · ', SUBSTRING_INDEX(b.branch_name, '(', 1)) AS iit_branch, f.closing_rank FROM fact_allotment f JOIN dim_iit i ON f.iit_id=i.iit_id JOIN dim_branch b ON f.branch_id=b.branch_id JOIN dim_seat_type s ON f.seat_type_id=s.seat_type_id JOIN dim_gender g ON f.gender_id=g.gender_id WHERE quota_id=(SELECT quota_id FROM dim_quota WHERE quota_code='AI') AND f.closing_rank <= 1000 AND s.seat_type_code='OPEN' AND (g.gender_code='Gender-Neutral' OR g.gender_code='NULL') AND f.year=(SELECT MAX(year) FROM fact_allotment) AND f.round_no=(SELECT MAX(round_no) FROM fact_allotment WHERE year=(SELECT MAX(year) FROM fact_allotment)) AND f.is_preparatory=0 ORDER BY f.closing_rank ASC",
            'answer' => "Branches with closing rank under 1000 in the latest year.",
            "response_type" => "table"
        ],
        [
            'keywords' => ['10000', 'rank 10000', 'high rank'],
            'q' => 'What options do I have at rank 10000?',
            'sql' => "SELECT CONCAT(i.short_code, ' · ', SUBSTRING_INDEX(b.branch_name, '(', 1)) AS iit_branch, f.closing_rank FROM fact_allotment f JOIN dim_iit i ON f.iit_id=i.iit_id JOIN dim_branch b ON f.branch_id=b.branch_id JOIN dim_seat_type s ON f.seat_type_id=s.seat_type_id JOIN dim_gender g ON f.gender_id=g.gender_id WHERE quota_id=(SELECT quota_id FROM dim_quota WHERE quota_code='AI') AND f.closing_rank BETWEEN 9000 AND 11000 AND s.seat_type_code='OPEN' AND (g.gender_code='Gender-Neutral' OR g.gender_code='NULL') AND f.year=(SELECT MAX(year) FROM fact_allotment) AND f.round_no=(SELECT MAX(round_no) FROM fact_allotment WHERE year=(SELECT MAX(year) FROM fact_allotment)) AND f.is_preparatory=0 ORDER BY f.closing_rank ASC LIMIT 20",
            'answer' => "Branches with closing rank around 10,000 in the latest year.",
            "response_type" => "table"
        ],
        [
            'keywords' => ['data science', 'artificial intelligence', 'ai ml', 'machine learning'],
            'q' => 'Show AI/Data Science branch cutoffs across IITs',
            'sql' => "SELECT i.short_code AS iit, SUBSTRING_INDEX(b.branch_name, '(', 1) AS branch, ROUND(AVG(f.closing_rank)) AS avg_closing_rank FROM fact_allotment f JOIN dim_iit i ON f.iit_id=i.iit_id JOIN dim_branch b ON f.branch_id=b.branch_id JOIN dim_seat_type s ON f.seat_type_id=s.seat_type_id JOIN dim_gender g ON f.gender_id=g.gender_id WHERE quota_id=(SELECT quota_id FROM dim_quota WHERE quota_code='AI') AND (b.branch_name LIKE '%Artificial Intelligence%' OR b.branch_name LIKE '%Data Science%' OR b.branch_name LIKE '%Machine Learning%') AND s.seat_type_code='OPEN' AND (g.gender_code='Gender-Neutral' OR g.gender_code='NULL') AND (f.year, f.round_no) IN (SELECT year, MAX(round_no) FROM fact_allotment GROUP BY year) AND f.is_preparatory=0 GROUP BY i.iit_id, b.branch_id ORDER BY avg_closing_rank ASC",
            'answer' => "AI/Data Science/ML branch cutoffs across IITs.",
            "response_type" => "table"
        ],
        [
            'keywords' => ['5 year', 'integrated', 'dual degree', 'dual'],
            'q' => 'Show 5-year integrated/dual degree program cutoffs',
            'sql' => "SELECT CONCAT(i.short_code, ' · ', SUBSTRING_INDEX(b.branch_name, '(', 1)) AS iit_branch, ROUND(AVG(f.closing_rank)) AS avg_closing_rank FROM fact_allotment f JOIN dim_iit i ON f.iit_id=i.iit_id JOIN dim_branch b ON f.branch_id=b.branch_id JOIN dim_seat_type s ON f.seat_type_id=s.seat_type_id JOIN dim_gender g ON f.gender_id=g.gender_id WHERE quota_id=(SELECT quota_id FROM dim_quota WHERE quota_code='AI') AND (b.branch_name LIKE '%5 Year%' OR b.branch_name LIKE '%Dual Degree%' OR b.branch_name LIKE '%Integrated%') AND s.seat_type_code='OPEN' AND (g.gender_code='Gender-Neutral' OR g.gender_code='NULL') AND (f.year, f.round_no) IN (SELECT year, MAX(round_no) FROM fact_allotment GROUP BY year) AND f.is_preparatory=0 GROUP BY i.iit_id, b.branch_id ORDER BY avg_closing_rank ASC LIMIT 20",
            'answer' => "5-year integrated and dual degree program cutoffs.",
            "response_type" => "table"
        ],
        [
            'keywords' => ['ropar', 'rupnagar'],
            'q' => 'Show branch cutoffs at IIT Ropar',
            'sql' => "SELECT SUBSTRING_INDEX(b.branch_name, '(', 1) AS branch, ROUND(AVG(f.closing_rank)) AS avg_closing_rank FROM fact_allotment f JOIN dim_iit i ON f.iit_id=i.iit_id JOIN dim_branch b ON f.branch_id=b.branch_id JOIN dim_seat_type s ON f.seat_type_id=s.seat_type_id JOIN dim_gender g ON f.gender_id=g.gender_id WHERE quota_id=(SELECT quota_id FROM dim_quota WHERE quota_code='AI') AND i.short_code='IIT Ropar' AND s.seat_type_code='OPEN' AND (g.gender_code='Gender-Neutral' OR g.gender_code='NULL') AND (f.year, f.round_no) IN (SELECT year, MAX(round_no) FROM fact_allotment GROUP BY year) AND f.is_preparatory=0 GROUP BY b.branch_id ORDER BY avg_closing_rank ASC",
            'answer' => "Branch cutoffs at IIT Ropar.",
            "response_type" => "chart",
            "chart" => ["type" => "horizontalBar", "x" => "branch", "y" => "avg_closing_rank", "title" => "IIT Ropar Branch Cutoffs"]
        ],
        [
            'keywords' => ['bhubaneswar', 'bbsr'],
            'q' => 'Show branch cutoffs at IIT Bhubaneswar',
            'sql' => "SELECT SUBSTRING_INDEX(b.branch_name, '(', 1) AS branch, ROUND(AVG(f.closing_rank)) AS avg_closing_rank FROM fact_allotment f JOIN dim_iit i ON f.iit_id=i.iit_id JOIN dim_branch b ON f.branch_id=b.branch_id JOIN dim_seat_type s ON f.seat_type_id=s.seat_type_id JOIN dim_gender g ON f.gender_id=g.gender_id WHERE quota_id=(SELECT quota_id FROM dim_quota WHERE quota_code='AI') AND i.short_code='IIT Bhubaneswar' AND s.seat_type_code='OPEN' AND (g.gender_code='Gender-Neutral' OR g.gender_code='NULL') AND (f.year, f.round_no) IN (SELECT year, MAX(round_no) FROM fact_allotment GROUP BY year) AND f.is_preparatory=0 GROUP BY b.branch_id ORDER BY avg_closing_rank ASC",
            'answer' => "Branch cutoffs at IIT Bhubaneswar.",
            "response_type" => "chart",
            "chart" => ["type" => "horizontalBar", "x" => "branch", "y" => "avg_closing_rank", "title" => "IIT Bhubaneswar Branch Cutoffs"]
        ],
        [
            'keywords' => ['dhanbad', 'ism', 'indian school of mines'],
            'q' => 'Show branch cutoffs at IIT Dhanbad (ISM)',
            'sql' => "SELECT SUBSTRING_INDEX(b.branch_name, '(', 1) AS branch, ROUND(AVG(f.closing_rank)) AS avg_closing_rank FROM fact_allotment f JOIN dim_iit i ON f.iit_id=i.iit_id JOIN dim_branch b ON f.branch_id=b.branch_id JOIN dim_seat_type s ON f.seat_type_id=s.seat_type_id JOIN dim_gender g ON f.gender_id=g.gender_id WHERE quota_id=(SELECT quota_id FROM dim_quota WHERE quota_code='AI') AND i.iit_name LIKE '%Dhanbad%' AND s.seat_type_code='OPEN' AND (g.gender_code='Gender-Neutral' OR g.gender_code='NULL') AND (f.year, f.round_no) IN (SELECT year, MAX(round_no) FROM fact_allotment GROUP BY year) AND f.is_preparatory=0 GROUP BY b.branch_id ORDER BY avg_closing_rank ASC",
            'answer' => "Branch cutoffs at IIT Dhanbad (ISM).",
            "response_type" => "chart",
            "chart" => ["type" => "horizontalBar", "x" => "branch", "y" => "avg_closing_rank", "title" => "IIT Dhanbad Branch Cutoffs"]
        ],
        [
            'keywords' => ['2000', 'rank 2000'],
            'q' => 'What can I get with rank around 2000?',
            'sql' => "SELECT CONCAT(i.short_code, ' · ', SUBSTRING_INDEX(b.branch_name, '(', 1)) AS iit_branch, f.closing_rank FROM fact_allotment f JOIN dim_iit i ON f.iit_id=i.iit_id JOIN dim_branch b ON f.branch_id=b.branch_id JOIN dim_seat_type s ON f.seat_type_id=s.seat_type_id JOIN dim_gender g ON f.gender_id=g.gender_id WHERE quota_id=(SELECT quota_id FROM dim_quota WHERE quota_code='AI') AND f.closing_rank BETWEEN 1800 AND 2200 AND s.seat_type_code='OPEN' AND (g.gender_code='Gender-Neutral' OR g.gender_code='NULL') AND f.year=(SELECT MAX(year) FROM fact_allotment) AND f.round_no=(SELECT MAX(round_no) FROM fact_allotment WHERE year=(SELECT MAX(year) FROM fact_allotment)) AND f.is_preparatory=0 ORDER BY f.closing_rank ASC LIMIT 20",
            'answer' => "Branches with closing rank around 2,000 in the latest year.",
            "response_type" => "table"
        ],
        [
            'keywords' => ['3000', 'rank 3000'],
            'q' => 'What can I get with rank around 3000?',
            'sql' => "SELECT CONCAT(i.short_code, ' · ', SUBSTRING_INDEX(b.branch_name, '(', 1)) AS iit_branch, f.closing_rank FROM fact_allotment f JOIN dim_iit i ON f.iit_id=i.iit_id JOIN dim_branch b ON f.branch_id=b.branch_id JOIN dim_seat_type s ON f.seat_type_id=s.seat_type_id JOIN dim_gender g ON f.gender_id=g.gender_id WHERE quota_id=(SELECT quota_id FROM dim_quota WHERE quota_code='AI') AND f.closing_rank BETWEEN 2700 AND 3300 AND s.seat_type_code='OPEN' AND (g.gender_code='Gender-Neutral' OR g.gender_code='NULL') AND f.year=(SELECT MAX(year) FROM fact_allotment) AND f.round_no=(SELECT MAX(round_no) FROM fact_allotment WHERE year=(SELECT MAX(year) FROM fact_allotment)) AND f.is_preparatory=0 ORDER BY f.closing_rank ASC LIMIT 20",
            'answer' => "Branches with closing rank around 3,000 in the latest year.",
            "response_type" => "table"
        ],
        [
            'keywords' => ['500', 'rank 500', 'top 500'],
            'q' => 'What are my options at rank 500?',
            'sql' => "SELECT CONCAT(i.short_code, ' · ', SUBSTRING_INDEX(b.branch_name, '(', 1)) AS iit_branch, f.closing_rank FROM fact_allotment f JOIN dim_iit i ON f.iit_id=i.iit_id JOIN dim_branch b ON f.branch_id=b.branch_id JOIN dim_seat_type s ON f.seat_type_id=s.seat_type_id JOIN dim_gender g ON f.gender_id=g.gender_id WHERE quota_id=(SELECT quota_id FROM dim_quota WHERE quota_code='AI') AND f.closing_rank BETWEEN 400 AND 600 AND s.seat_type_code='OPEN' AND (g.gender_code='Gender-Neutral' OR g.gender_code='NULL') AND f.year=(SELECT MAX(year) FROM fact_allotment) AND f.round_no=(SELECT MAX(round_no) FROM fact_allotment WHERE year=(SELECT MAX(year) FROM fact_allotment)) AND f.is_preparatory=0 ORDER BY f.closing_rank ASC LIMIT 20",
            'answer' => "Branches with closing rank around 500 in the latest year.",
            "response_type" => "table"
        ],
        [
            'keywords' => ['opening rank', 'opening', 'opening trend'],
            'q' => 'Show opening rank trend for CSE at IIT Bombay',
            'sql' => "SELECT f.year, ROUND(AVG(f.opening_rank)) AS avg_opening_rank FROM fact_allotment f JOIN dim_iit i ON f.iit_id=i.iit_id JOIN dim_branch b ON f.branch_id=b.branch_id JOIN dim_seat_type s ON f.seat_type_id=s.seat_type_id JOIN dim_gender g ON f.gender_id=g.gender_id WHERE quota_id=(SELECT quota_id FROM dim_quota WHERE quota_code='AI') AND i.short_code='IIT Bombay' AND b.category='cse_family' AND s.seat_type_code='OPEN' AND (g.gender_code='Gender-Neutral' OR g.gender_code='NULL') AND (f.year, f.round_no) IN (SELECT year, MAX(round_no) FROM fact_allotment GROUP BY year) AND f.is_preparatory=0 GROUP BY f.year ORDER BY f.year",
            'answer' => "Opening rank trend for CSE at IIT Bombay.",
            "response_type" => "chart",
            "chart" => ["type" => "line", "x" => "year", "y" => "avg_opening_rank", "title" => "CSE Opening Rank (IIT Bombay)", "y_reverse" => true]
        ],
        [
            'keywords' => ['delhi', 'iitd', 'branches'],
            'q' => 'Show all branch cutoffs at IIT Delhi',
            'sql' => "SELECT SUBSTRING_INDEX(b.branch_name, '(', 1) AS branch, ROUND(AVG(f.closing_rank)) AS avg_closing_rank FROM fact_allotment f JOIN dim_iit i ON f.iit_id=i.iit_id JOIN dim_branch b ON f.branch_id=b.branch_id JOIN dim_seat_type s ON f.seat_type_id=s.seat_type_id JOIN dim_gender g ON f.gender_id=g.gender_id WHERE quota_id=(SELECT quota_id FROM dim_quota WHERE quota_code='AI') AND i.short_code='IIT Delhi' AND s.seat_type_code='OPEN' AND (g.gender_code='Gender-Neutral' OR g.gender_code='NULL') AND (f.year, f.round_no) IN (SELECT year, MAX(round_no) FROM fact_allotment GROUP BY year) AND f.is_preparatory=0 GROUP BY b.branch_id ORDER BY avg_closing_rank ASC",
            'answer' => "All branch cutoffs at IIT Delhi.",
            "response_type" => "chart",
            "chart" => ["type" => "horizontalBar", "x" => "branch", "y" => "avg_closing_rank", "title" => "IIT Delhi Branch Cutoffs"]
        ]
    ];

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    /**
     * Injects glossary definitions and exact branch IDs into the user's question context.
     */
    public function augmentQuestion(string $question): string
    {
        $augmented = $question;
        $notes = [];

        // 1. Glossary replacement
        $words = preg_split('/\W+/', strtolower($question));
        foreach (self::GLOSSARY as $acronym => $meaning) {
            if (in_array($acronym, $words, true)) {
                $notes[] = "User mentioned '{$acronym}'. This implies: {$meaning}.";
            }
        }

        // 2. Pre-flight entity search for branches
        // Look for keywords that might be branch names (longer than 4 chars)
        $potentialBranches = [];
        $ignoreWords = ['what', 'show', 'list', 'find', 'with', 'from', 'over', 'year', 'rank', 'cutoff', 'easiest', 'toughest', 'closing', 'opening', 'ranks', 'branch', 'college', 'trend', 'data', 'institute', 'category', 'quota', 'seat', 'type', 'gender', 'female', 'neutral', 'supernumerary', 'round', 'compare', 'difference', 'decrease', 'increase', 'between'];
        foreach ($words as $word) {
            if (strlen($word) >= 4 && !in_array($word, $ignoreWords, true)) {
                $potentialBranches[] = $word;
            }
        }

        if (!empty($potentialBranches)) {
            $likes = [];
            $params = [];
            // Limit to top 3 potential keywords to avoid massive queries
            foreach (array_slice($potentialBranches, 0, 3) as $pb) {
                $likes[] = "branch_name LIKE ?";
                $params[] = "%{$pb}%";
            }
            $where = implode(' OR ', $likes);
            $stmt = $this->pdo->prepare("SELECT branch_id, branch_name FROM dim_branch WHERE {$where} LIMIT 5");
            $stmt->execute($params);
            $branches = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            if ($branches) {
                $branchInfo = array_map(fn($b) => "[id: {$b['branch_id']}] {$b['branch_name']}", $branches);
                $notes[] = "DB contains these exact branches matching terms: " . implode(' | ', $branchInfo) . ". Use these exact branch_id(s) if relevant.";
            }
        }

        // Append notes if any found
        if (!empty($notes)) {
            $augmented .= "\n\n[SYSTEM NOTE: To avoid hallucinations, note the following:\n- " . implode("\n- ", $notes) . "\n]";
        }

        return $augmented;
    }

    /**
     * Returns matching golden queries to be used as few-shot examples.
     */
    public function getRelevantGoldenQueries(string $question): array
    {
        $words = preg_split('/\W+/', strtolower($question));
        $scored = [];

        foreach (self::GOLDEN_QUERIES as $gq) {
            $score = 0;
            foreach ($gq['keywords'] as $kw) {
                if (stripos($question, $kw) !== false) {
                    $score++;
                }
            }
            if ($score > 0) {
                $scored[] = ['score' => $score, 'query' => $gq];
            }
        }

        usort($scored, fn($a, $b) => $b['score'] <=> $a['score']);
        
        $top = array_slice($scored, 0, 3);
        return array_map(fn($item) => $item['query'], $top);
    }
}
