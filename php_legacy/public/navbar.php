<!-- ============ HEADER ============ -->
<nav class="navbar bg-white border-bottom shadow-sm px-4">
  <div class="d-flex align-items-center gap-3">
    <a href="index.php" style="text-decoration: none;">
      <span class="navbar-brand m-0 fw-bold" style="color: var(--brand);">JOSAA Analytics</span>
    </a>
    <span class="text-muted small">IIT Seat Allotment (Last Round Only)</span>
  </div>
  <div class="d-flex gap-2 flex-wrap">
    <a href="ai_chat.php" class="btn btn-sm <?= basename($_SERVER['PHP_SELF']) == 'ai_chat.php' ? 'btn-primary' : 'btn-outline-primary' ?>">AI Analyst</a>
    <a href="dashboard.php" class="btn btn-sm <?= basename($_SERVER['PHP_SELF']) == 'dashboard.php' ? 'btn-secondary' : 'btn-outline-secondary' ?>">Dashboard</a>
    <a href="predictor.php" class="btn btn-sm <?= basename($_SERVER['PHP_SELF']) == 'predictor.php' ? 'btn-success' : 'btn-outline-success' ?>">Predictor</a>
    <a href="preference.php" class="btn btn-sm <?= basename($_SERVER['PHP_SELF']) == 'preference.php' ? 'btn-warning' : 'btn-outline-warning' ?>">Preference Check</a>
  </div>
</nav>