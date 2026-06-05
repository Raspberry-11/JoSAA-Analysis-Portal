<?php
declare(strict_types=1);

require __DIR__ . '/../config/Database.php';

$pdo = Database::connect();

$stats = [
    'rows' => 0,
    'iits' => 0,
    'branches' => 0,
    'years' => 0,
];

try {
    $stats['rows'] = (int) $pdo->query('SELECT COUNT(*) FROM fact_allotment')->fetchColumn();
    $stats['iits'] = (int) $pdo->query('SELECT COUNT(*) FROM dim_iit')->fetchColumn();
    $stats['branches'] = (int) $pdo->query('SELECT COUNT(*) FROM dim_branch')->fetchColumn();
    $stats['years'] = (int) $pdo->query('SELECT COUNT(DISTINCT year) FROM fact_allotment')->fetchColumn();
} catch (Throwable $e) {
    // Keep the landing page usable even if the database is temporarily unavailable.
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>JOSAA Analytics Portal</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="assets/css/custom.css" rel="stylesheet">
<style>
  body {
    background:
      radial-gradient(circle at top left, rgba(79, 70, 229, 0.16), transparent 32%),
      radial-gradient(circle at top right, rgba(16, 185, 129, 0.12), transparent 26%),
      linear-gradient(180deg, #f8fafc 0%, #eef2ff 100%);
    min-height: 100vh;
  }
  .hero {
    padding: 4.5rem 0 2rem;
  }
  .hero-card, .stat-card, .tool-card {
    background: rgba(255,255,255,0.92);
    border: 1px solid rgba(229,231,235,0.9);
    border-radius: 20px;
    box-shadow: 0 18px 50px rgba(15, 23, 42, 0.08);
  }
  .hero-card { padding: 2rem; }
  .eyebrow {
    display: inline-flex;
    align-items: center;
    gap: .5rem;
    padding: .35rem .8rem;
    border-radius: 999px;
    background: #eef2ff;
    color: #3730a3;
    font-weight: 600;
    font-size: .85rem;
  }
  .tool-card {
    height: 100%;
    padding: 1.25rem;
  }
  .tool-card h5 { margin-bottom: .5rem; }
  .stat-value {
    font-size: 2rem;
    font-weight: 800;
    line-height: 1;
  }
  .stat-label { color: #6b7280; font-size: .9rem; }
  .stats-grid {
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    gap: 1rem;
  }
  @media (max-width: 576px) {
    .stats-grid { grid-template-columns: 1fr; }
  }
</style>
</head>
<body>

<nav class="navbar bg-white border-bottom shadow-sm px-4">
  <div class="d-flex align-items-center gap-3">
    <span class="navbar-brand m-0 fw-bold" style="color: var(--brand);">JOSAA Analytics</span>
    <span class="text-muted small">IIT Seat Allotment data, last round only</span>
  </div>
  <div class="d-flex gap-2 flex-wrap">
    <a href="ai_chat.php" class="btn btn-sm btn-outline-primary">AI Analyst</a>
    <a href="dashboard.php" class="btn btn-sm btn-outline-secondary">Dashboard</a>
    <a href="predictor.php" class="btn btn-sm btn-outline-success">Predictor</a>
    <a href="preference.php" class="btn btn-sm btn-outline-warning">Preference Check</a>
  </div>
</nav>

<main class="container py-4">
  <section class="hero">
    <div class="row g-4 align-items-center">
      <div class="col-lg-7">
        <div class="hero-card">
          <!-- <span class="eyebrow mb-3">Live JOSAA intelligence hub</span> -->
          <h1 class="display-5 fw-bold mb-3">Explore JOSAA data with specialized tools.</h1>
          <p class="lead text-muted mb-4">
            Ask questions in natural language, inspect the analytics dashboard, predicting 
            college options and checking likelihood of preferences based on historical data.
          </p>
          <div class="d-flex gap-2 flex-wrap">
            <a href="ai_chat.php" class="btn btn-primary btn-lg">Open AI Chat</a>
            <a href="dashboard.php" class="btn btn-outline-secondary btn-lg">Dashboard</a>
            <a href="predictor.php" class="btn btn-outline-success btn-lg">Predictor</a>
            <a href="preference.php" class="btn btn-outline-warning btn-lg">Preference</a>
          </div>
        </div>
      </div>
      <div class="col-lg-5">
        <div class="stats-grid">
          <div class="stat-card p-3 text-center">
            <div class="stat-value"><?= number_format($stats['rows']) ?></div>
            <div class="stat-label">Allotment rows retained</div>
          </div>
          <div class="stat-card p-3 text-center">
            <div class="stat-value"><?= number_format($stats['iits']) ?></div>
            <div class="stat-label">IITs in the dataset</div>
          </div>
          <div class="stat-card p-3 text-center">
            <div class="stat-value"><?= number_format($stats['branches']) ?></div>
            <div class="stat-label">Branches tracked</div>
          </div>
          <div class="stat-card p-3 text-center">
            <div class="stat-value"><?= number_format($stats['years']) ?></div>
            <div class="stat-label">Admission years covered</div>
          </div>
        </div>
      </div>
    </div>
  </section>

  <section class="mb-4">
    <div class="row g-3">
      <div class="col-md-3">
        <div class="tool-card">
          <h5>AI Chat</h5>
          <p class="text-muted mb-3 text-sm">Ask questions in English and get SQL-backed answers with charts.</p>
          <a href="ai_chat.php" class="btn btn-primary w-100 mt-auto">Go to Chat</a>
        </div>
      </div>
      <div class="col-md-3">
        <div class="tool-card">
          <h5>Dashboard</h5>
          <p class="text-muted mb-3 text-sm">Browse curated JOSAA insights and filter the data interactively.</p>
          <a href="dashboard.php" class="btn btn-secondary w-100 mt-auto">Go to Dashboard</a>
        </div>
      </div>
      <div class="col-md-3">
        <div class="tool-card">
          <h5>College Predictor</h5>
          <p class="text-muted mb-3 text-sm">Check likely IIT branch options based on JEE rank.</p>
          <a href="predictor.php" class="btn btn-success w-100 mt-auto">Go to Predictor</a>
        </div>
      </div>
      <div class="col-md-3">
        <div class="tool-card">
          <h5>Preference Check</h5>
          <p class="text-muted mb-3 text-sm">Check specific chances and alternatives for IIT/Branch combos.</p>
          <a href="preference.php" class="btn btn-warning w-100 mt-auto">Go to Preference Check</a>
        </div>
      </div>
    </div>
  </section>
</main>

</body>
</html>