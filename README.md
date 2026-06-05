# JOSAA AI Analytics Portal (2016–2022)

A comprehensive data analytics portal for JOSAA seat allotment statistics across all IITs from 2016 to 2022, featuring a natural-language AI interface.

> **Built for XAMPP / LAMP / MAMP.** Pure PHP (no Python/Node required).

---

## What Makes This Stand Out

This isn't just a dashboard — it's a four-layer analytics product:

**Layer 1 — AI Analyst (Natural Language → SQL → Chart)**
- Ask questions in plain English
- Groq LLM (Llama 3.3 70B) generates MySQL queries
- **Multi-layer SQL validator** rejects unsafe queries before execution
- Auto-selects response type: text answer, table, or chart based on data shape
- Multi-turn conversation — follow-up questions remember context
- Query caching — same question = instant response, no tokens spent
- PDF export of any AI answer

**Layer 2 — Classic Analytics Dashboard**
- Star-schema MySQL database tuned for OLAP
- interactive Chart.js visualizations answering 10 curated insights
- Sidebar with multi-select filters (Select2)
- CSV + PDF exports

**Layer 3 — College Predictor**
- Enter your JEE Advanced rank → get all IIT-branch combos you can realistically get
- **Prediction Algorithm & z-score classification:**
  - **Threshold**: `(0.6 × Latest Closing Rank) + (0.4 × Mean Closing Rank)`
  - **Z-Score**: `(Threshold - User Rank) / Max(Historical StdDev, 50)`
  - **Bucketing**:
    - `z >=  1.5` → **Very High** chance
    - `z >=  0.5` → **High** chance
    - `z >= -0.5` → **Moderate** chance
    - `z >= -1.5` → **Low** chance
    - `z <  -1.5` → Very Low chance (Filtered out to only show realistic options)
  - **Sorting**: Results are sorted by relevance—specifically the absolute difference between the weighted threshold and the user's rank (`|Threshold - User Rank|`). This pushes the perfect "target" schools to the top, smoothly followed by the closest "reach" and "safety" options.
- Filtered to last 4 years + final round only for relevance
- Shows latest closing rank, average, range, and reasoning per option

**Layer 4 — Preference Check**
- Pick a specific IIT + Branch + Rank combination
- Get probability assessment with **historical trend chart** vs your rank
- Smart suggestions based on intelligent branch "bucketing":
  - Same/similar branch at other IITs in your rank range
  - Same IIT with other branches you can target
- Groups similar branches accurately (e.g. 4-year CSE, 5-year Dual Degree CSE, AI Data Analytics) together so you never miss relevant options

---

## XAMPP Setup

### Step 1 — Start XAMPP
Start **Apache** + **MySQL** from the XAMPP Control Panel.

### Step 2 — Place Project in htdocs

| OS      | Path                                          |
|---------|-----------------------------------------------|
| Windows | `C:\xampp\htdocs\josaa-portal`                |
| macOS   | `/Applications/XAMPP/xamppfiles/htdocs/josaa-portal` |
| Linux   | `/opt/lampp/htdocs/josaa-portal`              |

### Step 3 — Create the Database

Open <http://localhost/phpmyadmin> → SQL tab → paste `scripts/schema.sql` → **Go**.

You'll get 7 tables: the 6 analytics tables plus `ai_queries` (the LLM cache).

> **Already have the DB from an older version?** Run `scripts/migrate_add_ai_cache.sql` instead to add just the cache table.

### Step 4 — Drop the CSV and Import

Place your dataset at `data/josaa_2016_2022.csv` (columns: `id, iit, branch, quota, seat_type, gender, or, cr, year, round`).

Then visit: <http://localhost/josaa-portal/public/import.php>

### Step 5 — Get a Groq API Key

1. Go to <https://console.groq.com/keys>
2. Sign up (free)
3. Create a new API key

### Step 6 — Configure the Key

Copy `.env.example` to `.env` in the project root, then set your key:

```
GROQ_API_KEY=gsk_your_key_here
```

Alternatively, hardcode into `config/ai_config.php` (easier for local dev):
```php
putenv('GROQ_API_KEY=gsk_your_key_here');
```

### Step 7 — Open the Portal

Visit: <http://localhost/josaa-portal/>

You'll see the AI analyst on top and the classic dashboard below.

---

## Using the AI Analyst

Just type questions in English. The AI picks the right response format:

**Text answers** (for single facts):
- *"What was the closing rank for CSE at IIT Bombay in 2022?"* → "In 2022, the average closing rank was approximately 67..."

**Tables** (for multi-column lists):
- *"List all seat types with their counts"* → renders a sortable table

**Charts** (for trends and rankings):
- *"Show CSE closing rank trend at IIT Bombay from 2016 to 2022"* → line chart
- *"Top 10 toughest IIT-branch combinations"* → horizontal bar (with IIT names included!)

**Smart context preservation:**
- *"What are the toughest branches?"* → groups by **IIT × branch** so you see "IIT Bombay · CSE" not just "CSE" three times
- Branch names get auto-trimmed of their long parenthesis suffixes for cleaner labels

**Server-side fallback logic** auto-corrects the LLM:
- If the model picks "chart" but data has only 1 row → falls back to text
- If chart spec doesn't match data shape → falls back to table
- If no rows returned → text-only answer with explanation

**Follow-up questions work:**
- *"Show closing ranks for CSE at IIT Bombay"*
- *"Now just for 2022"*  ← uses previous context

---

## SQL Injection Defense

The LLM output is **never trusted blindly**. Every generated query passes through `SqlValidator` which enforces:

1. **Syntax validity** — rejects malformed SQL
2. **SELECT/WITH only** — no INSERT/UPDATE/DELETE/DROP/ALTER/etc.
3. **Single statement** — scans for unquoted semicolons (stacked query attacks)
4. **Forbidden keyword scan** — blocks DDL/DML + `SHOW`/`DESCRIBE`/`SLEEP`/`BENCHMARK`/`INTO OUTFILE`
5. **Table whitelist** — only the 6 allowed tables; `mysql.user`, `information_schema.*`, etc. rejected
6. **LIMIT injection** — caps result sets at 1000 rows, injects LIMIT if missing

The validator is CTE-aware (recognizes CTE aliases as non-real tables) and quote-aware (handles escaped quotes in the semicolon scanner). Tested against 20+ attack vectors.

---

## Project Structure

```
josaa-portal/
├── config/
│   ├── Database.php              # PDO singleton
│   └── ai_config.php             # Groq API key loader
├── src/
│   ├── Core/Response.php
│   ├── Models/
│   │   └── AllotmentModel.php    # Dashboard SQL
│   ├── Controllers/
│   │   ├── AnalyticsController.php
│   │   └── AIController.php      # Natural-language endpoint
│   └── Services/
│       ├── ExportService.php     # CSV + PDF
│       ├── LLMProvider.php       # Provider interface
│       ├── GroqProvider.php      # Groq implementation
│       ├── SqlValidator.php      # Security gate (★ critical)
│       └── NLQueryService.php    # NL → SQL orchestration
├── public/
│   ├── index.php                 # Hybrid dashboard
│   ├── api.php                   # API front-controller
│   ├── import.php                # Browser-based CSV importer
│   └── assets/
│       ├── css/custom.css
│       └── js/
│           ├── dashboard.js      # Classic dashboard
│           └── ai_chat.js        # AI chat + dynamic charts
├── scripts/
│   ├── schema.sql                # Fresh install
│   ├── migrate_add_ai_cache.sql  # Upgrade from older version
│   └── import_csv.php            # CLI ETL
├── data/
│   └── josaa_2016_2022.csv
├── .env.example
└── README.md
```

---

## API Reference

### Predictor Endpoints
| Action | Method | Description |
|---|---|---|
| `predictor_options` | GET | Dropdown options (IITs, branches, seat types, genders) |
| `predict_by_rank` | POST | `{rank, seat_type, gender}` → ranked list of options bucketed by chance |
| `predict_for_preference` | POST | `{rank, iit_id, branch_id, seat_type, gender}` → primary chance + alternatives |

### AI Endpoints
| Action | Method | Description |
|---|---|---|
| `ai_ask` | POST | `{question, conversation?}` → SQL + data + chart |
| `ai_history` | GET | Recent queries (for sidebar) |
| `ai_export_pdf` | POST | Generate PDF report from AI response |

### Classic Analytics Endpoints
| Action | Method | Description |
|---|---|---|
| `filters` | GET | Filter dropdown options |
| `rows` | POST | Filtered records |
| `q1_cse_trend` ... `q10_top100` | GET | 10 curated insights |
| `export_csv` / `export_pdf` | GET | Filtered data download |

---

## 🧠 Architectural Highlights

- **Multi-layer SQL injection defense on LLM output** — AST-style validation with 6 guardrails, CTE-aware table extraction, quote-aware stacked-statement detection.
- **Provider-agnostic LLM abstraction** — `LLMProvider` interface + `GroqProvider` implementation. Swapping to Anthropic/OpenAI = one new class.
- **Schema-grounded prompting** — the DDL + 3 few-shot examples + business rules injected into the system prompt. Dramatically reduces hallucination.
- **Query result caching with hit counter** — SHA-256 cache key over normalized question + conversation context. Multi-turn safe.
- **Star schema OLAP design** — fact table keeps only integer FKs; dimensions are tiny with composite indexes tuned to query shapes.
- **Branch/IIT pre-classification at ETL time** — avoids per-query `CASE` expressions.
- **MySQL medians via window functions** — `ROW_NUMBER() OVER (...)` with even/odd midpoint trick for Q2/Q5.
- **Parameterized dynamic filter builder** — zero string concatenation of user input, fully PDO-bound.

---

## 📊 The 10 Curated Questions (Classic Dashboard)

| # | Question | Chart |
|---|---|---|
| Q1 | CSE fluctuation across Top 5 old IITs | Line |
| Q2 | Top 10 toughest branches by median CR | Horizontal Bar |
| Q3 | Female-only vs gender-neutral CR impact | API only |
| Q4 | New-age vs core branch trajectories | Line |
| Q5 | IIT preference hierarchy | Horizontal Bar |
| Q6 | Round-wise rank drop by year | Bar |
| Q7 | Branch-vs-IIT tradeoff | API only |
| Q8 | Category cutoff gaps | API only |
| Q9 | Most volatile IIT-Branch combos | Horizontal Bar |
| Q10 | Top 100 AIR monopoly by year | Stacked Bar |

Anything Q3/Q7/Q8 offers is just one English sentence away through the AI Analyst.

---

## ⚠️ Troubleshooting

**"GROQ_API_KEY not configured"**
→ Get a key at <https://console.groq.com/keys>, then set it in `.env` or `config/ai_config.php`.

**"LLM returned malformed response"**
→ The model didn't follow the JSON output spec. Try rephrasing your question, or switch models via `GROQ_MODEL`.

**"Forbidden keyword detected: XYZ"**
→ The LLM tried to generate a DDL/DML query. This is the validator working as intended. Rephrase as a pure read query.

**"Disallowed table: XYZ"**
→ Same as above — LLM tried to hit `information_schema` or `mysql.*`. Validator rejects it.

**"SQLSTATE[HY000] [1045] Access denied for user 'root'@'localhost'"**
→ You have a MySQL password set. Edit `config/Database.php` or set `DB_PASS` in `.env`.

**AI response times are slow**
→ First query: ~2–4s (LLM call). Cached: <100ms. Groq is generally the fastest provider.

---

## 🛠 Tech Stack

**Backend:** PHP 8.1, MySQL 8 / MariaDB 10.4+, PDO
**AI:** Groq API (Llama 3.3 70B) via pure PHP + cURL
**Frontend:** Bootstrap 5, Select2, Chart.js 4, jQuery 3.7
**Environment:** XAMPP

---

## 📝 License

MIT.
