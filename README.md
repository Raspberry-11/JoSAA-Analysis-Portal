# JoSAA AI Analytics Portal (2016–2022)

A data-analytics portal for JoSAA seat-allotment statistics across all IITs, featuring a natural-language AI interface that turns plain-English questions into SQL, charts, and tables.

> **Stack:** Django · MySQL/MariaDB · Groq LLM (Llama 3.3 70B) · Bootstrap 5 · Chart.js

---

## What's Inside

This is a four-layer analytics product:

**Layer 1 — AI Analyst (Natural Language → SQL → Chart)**
- Ask questions in plain English
- Groq LLM generates MySQL queries grounded in the schema
- **Keyword-retrieved few-shot prompting** from a **42-query golden library** ([`rag_service.py`](analytics/services/rag_service.py))
- **Multi-layer SQL validator** rejects unsafe queries before execution ([`sql_validator.py`](analytics/services/sql_validator.py))
- **Self-healing retry loop** — failed SQL is fed back to the model to fix itself
- Auto-selects response type: text answer, table, or chart based on data shape
- Multi-turn conversation — follow-up questions remember context
- **SHA-256 query cache** — same question = instant response, no tokens spent

**Layer 2 — Classic Analytics Dashboard**
- Star-schema MySQL database tuned for OLAP
- Interactive Chart.js visualizations answering 10 curated insights
- Multi-select filters, CSV + PDF exports

**Layer 3 — College Predictor**
- Enter your JEE Advanced rank → get all IIT-branch combos you can realistically get
- **z-score model**: `Threshold = (0.6 × Latest CR) + (0.4 × Mean CR)`, normalized by historical volatility, bucketed into **5 confidence tiers** (Very High → Very Low)

**Layer 4 — Preference Check**
- Pick a specific IIT + Branch + Rank → probability assessment with historical trend chart and **branch-bucketed alternatives**

---

## Project Structure

```
JoSAA-Analysis/
├── manage.py
├── requirements.txt
├── .env.example
├── josaa/                       # Django project (settings, urls, wsgi)
│   ├── __init__.py              # PyMySQL shim (install_as_MySQLdb)
│   └── settings.py
├── analytics/                   # Main app
│   ├── models.py                # Star schema (managed=False)
│   ├── urls.py
│   ├── views/
│   │   ├── api.py               # ?action= API dispatcher
│   │   └── pages.py             # Page views
│   ├── services/
│   │   ├── nl_query_service.py  # NL → SQL orchestration (+ cache, retry loop)
│   │   ├── groq_provider.py     # Groq LLM client
│   │   ├── rag_service.py       # 42 golden queries + keyword retrieval
│   │   ├── sql_validator.py     # 6-layer SQL-injection defense (★ critical)
│   │   ├── predictor_service.py # z-score predictor
│   │   ├── allotment_queries.py # Dashboard SQL
│   │   └── export_service.py    # CSV + PDF
│   ├── management/commands/
│   │   └── import_csv.py        # CSV → star-schema ETL (python manage.py import_csv)
│   └── tests.py                 # SQL-injection test suite (25+ attack vectors)
├── scripts/
│   ├── schema.sql               # Create the database + tables
│   └── migrate_add_ai_cache.sql # Add just the AI cache table
├── templates/                   # Django templates (Bootstrap 5 + Chart.js)
├── static/                      # CSS + JS
└── data/                        # Put josaa_2016_2022.csv here
```

---

## Setup

### 1. Install dependencies
```bash
python -m venv venv
source venv/bin/activate            # Windows: venv\Scripts\activate
pip install -r requirements.txt
```

### 2. Configure environment
Copy `.env.example` to `.env` and set your values:
```
DB_HOST=127.0.0.1
DB_PORT=3306
DB_NAME=josaa_portal
DB_USER=root
DB_PASS=
GROQ_API_KEY=gsk_your_key_here      # free key at https://console.groq.com/keys
```

### 3. Create the database schema
The models are `managed=False`, so create the tables from the SQL script:
```bash
mysql -u root -p < scripts/schema.sql
```

### 4. Load the data
Place your dataset at `data/josaa_2016_2022.csv` (columns: `id, iit, branch, quota, seat_type, gender, or, cr, year, round`), then run the ETL command:
```bash
python manage.py import_csv data/josaa_2016_2022.csv
```

### 5. Run the server
```bash
python manage.py runserver
```
Open <http://127.0.0.1:8000/>.

---

## Testing

The SQL-injection defense is covered by a test suite (25+ attack vectors + legitimate-query guards). It's pure unit-level — no database required:

```bash
python -m unittest analytics.tests -v
```

---

## API Reference

All endpoints are served from `/api/?action=<name>`.

### AI Endpoints
| Action | Method | Description |
|---|---|---|
| `ai_ask` | POST | `{question, conversation?}` → SQL + data + chart |
| `ai_history` | GET | Recent queries |
| `ai_rate` | POST | Rate / evict a cached response |
| `ai_export_pdf` | POST | Generate PDF report from an AI response |

### Predictor Endpoints
| Action | Method | Description |
|---|---|---|
| `predictor_options` | GET | Dropdown options |
| `predict_by_rank` | POST | `{rank, seat_type, gender}` → ranked options bucketed by chance |
| `predict_for_preference` | POST | `{rank, iit_id, branch_id, ...}` → primary chance + alternatives |

### Classic Analytics Endpoints
| Action | Method | Description |
|---|---|---|
| `filters` | GET | Filter dropdown options |
| `rows` | POST | Filtered records |
| `q1_cse_trend` … `q10_top100` | GET | 10 curated insights |
| `export_csv` / `export_pdf` | GET | Filtered data download |

---

## Architectural Highlights

- **Multi-layer SQL-injection defense on LLM output** — single-statement enforcement (quote-aware), read-only SELECT/WITH, forbidden-keyword blocklist, 6-table whitelist (CTE-aware), and a 1000-row cap. Tested against 25+ attack vectors.
- **Self-healing query loop** — validation/execution errors are fed back to the model for automatic correction (bounded retries).
- **Schema-grounded prompting** — DDL + business rules + keyword-retrieved few-shot examples injected into the system prompt to curb hallucination.
- **SHA-256 multi-turn query cache** — cache key over the normalized question + conversation context, with a hit counter.
- **Star-schema OLAP design** — narrow all-integer fact table; tiny dimensions with composite indexes tuned to query shapes.
- **ETL-time pre-classification** — branch `category` and IIT `generation` tagged at import so queries filter on clean enums.
- **z-score predictor** — weighted-threshold scoring normalized by historical volatility, bucketed into 5 confidence tiers.

---

## Tech Stack

**Backend:** Python 3.11+, Django 4.2+, MySQL 8 / MariaDB 10.4+, PyMySQL
**AI:** Groq API (Llama 3.3 70B) via `httpx`
**Frontend:** Bootstrap 5, Chart.js, Select2, jQuery

## License

MIT.
