<?php
declare(strict_types=1);
?>
<!DOCTYPE html>
<html lang="en">

<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Dashboard - JOSAA Analytics</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/select2-bootstrap-5-theme@1.3.0/dist/select2-bootstrap-5-theme.min.css"
    rel="stylesheet">
  <link href="assets/css/custom.css" rel="stylesheet">
</head>

<body>

  <?php include 'navbar.php'; ?>

  <!-- ============ CLASSIC DASHBOARD SECTION ============ -->
  <section id="dashboard-section" class="dashboard-section">
    <div class="container-fluid">
      <div class="row">

        <!-- Sidebar -->
        <aside class="col-lg-3 col-md-4 sidebar">
          <div class="sidebar-header">
            <h5 class="m-0">Pre-built Analytics</h5>
            <small class="text-muted">Filter the classic dashboard</small>
          </div>

          <div class="mb-3">
            <label class="form-label">Year</label>
            <select id="f-year" multiple class="form-select"></select>
          </div>
          <div class="mb-3">
            <label class="form-label">IIT</label>
            <select id="f-iit" multiple class="form-select"></select>
          </div>
          <div class="mb-3">
            <label class="form-label">Branch</label>
            <select id="f-branch" multiple class="form-select"></select>
          </div>
          <div class="mb-3">
            <label class="form-label">Quota</label>
            <select id="f-quota" multiple class="form-select"></select>
          </div>
          <div class="mb-3">
            <label class="form-label">Seat Type</label>
            <select id="f-seat" multiple class="form-select"></select>
          </div>
          <div class="mb-3">
            <label class="form-label">Gender</label>
            <select id="f-gender" multiple class="form-select"></select>
          </div>
          <div class="mb-3">
            <label class="form-label">Round</label>
            <select id="f-round" multiple class="form-select"></select>
          </div>

          <button id="apply-filters" class="btn btn-primary w-100 mb-2">Apply Filters</button>
          <button id="reset-filters" class="btn btn-outline-secondary w-100 mb-3">Reset</button>
          <hr>
          <button id="export-csv" class="btn btn-success w-100 mb-2">Export CSV</button>
          <button id="export-pdf" class="btn btn-outline-success w-100">Export PDF</button>
        </aside>

        <!-- Main dashboard -->
        <main class="col-lg-9 col-md-8 p-4">
          <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap">
            <div>
              <h3 class="mb-1">Insightful Analytics Dashboard</h3>
              <div class="text-muted">Explore 10 curated insights and filter records</div>
            </div>
            <div id="status-pill" class="badge bg-secondary">Loading…</div>
          </div>

          <!-- KPI Row -->
          <div class="row g-3 mb-4" id="kpi-row">
            <div class="col-md-3">
              <div class="card card-metric shadow-sm">
                <div class="card-body">
                  <div class="kpi-label">Filtered Rows</div>
                  <div class="kpi-value" id="kpi-rows">—</div>
                </div>
              </div>
            </div>
            <div class="col-md-3">
              <div class="card card-metric shadow-sm">
                <div class="card-body">
                  <div class="kpi-label">Avg Closing Rank</div>
                  <div class="kpi-value" id="kpi-avg-close">—</div>
                </div>
              </div>
            </div>
            <div class="col-md-3">
              <div class="card card-metric shadow-sm">
                <div class="card-body">
                  <div class="kpi-label">Best Opening Rank</div>
                  <div class="kpi-value" id="kpi-best-open">—</div>
                </div>
              </div>
            </div>
            <div class="col-md-3">
              <div class="card card-metric shadow-sm">
                <div class="card-body">
                  <div class="kpi-label">Unique Branches</div>
                  <div class="kpi-value" id="kpi-branches">—</div>
                </div>
              </div>
            </div>
          </div>

          <!-- Chart Grid -->
          <div class="row g-3 mb-4">
            <div class="col-xl-6">
              <div class="chart-wrap">
                <h6>Q1 · CSE Closing Rank Trend — Top 5 Old IITs</h6>
                <div class="chart-canvas-container"><canvas id="chart-cse-trend"></canvas></div>
              </div>
            </div>
            <div class="col-xl-6">
              <div class="chart-wrap">
                <h6>Q5 · IIT Preference Hierarchy (median CR, lower = more preferred)</h6>
                <div class="chart-canvas-container"><canvas id="chart-iit-rank"></canvas></div>
              </div>
            </div>
          </div>

          <div class="row g-3 mb-4">
            <div class="col-xl-6">
              <div class="chart-wrap">
                <h6>Q2 · Toughest Branches to get into (Lowest Median CR)</h6>
                <div class="chart-canvas-container"><canvas id="chart-toughest"></canvas></div>
              </div>
            </div>
            <div class="col-xl-6">
              <div class="chart-wrap">
                <h6>Q4 · New Age vs Core Branches (Seats Over Time)</h6>
                <div class="chart-canvas-container"><canvas id="chart-newage"></canvas></div>
              </div>
            </div>
          </div>

          <div class="row g-3 mb-4">
            <div class="col-xl-6">
              <div class="chart-wrap">
                <h6>Q6 · Average Rank Inflation (Round 1 → Final Round)</h6>
                <div class="chart-canvas-container"><canvas id="chart-round-drop"></canvas></div>
              </div>
            </div>
            <div class="col-xl-6">
              <div class="chart-wrap">
                <h6>Q9 · Most Volatile IIT-Branch Combinations</h6>
                <div class="chart-canvas-container"><canvas id="chart-volatility"></canvas></div>
              </div>
            </div>
          </div>

          <div class="row g-3 mb-4">
            <div class="col-12">
              <div class="chart-wrap">
                <h6>Q10 · Top 100 AIR Monopoly (by year)</h6>
                <div class="chart-canvas-container"><canvas id="chart-top100"></canvas></div>
              </div>
            </div>
          </div>

          <!-- Data Table -->
          <div class="chart-wrap chart-wrap--table">
            <div class="d-flex justify-content-between align-items-center mb-3">
              <h6 class="m-0">Filtered Records Preview</h6>
              <small class="text-muted" id="table-row-count">—</small>
            </div>
            <div class="table-responsive" style="max-height: 420px;">
              <table class="table table-sm table-hover" id="data-table">
                <thead class="table-light sticky-top">
                  <tr>
                    <th>IIT</th>
                    <th>Branch</th>
                    <th>Quota</th>
                    <th>Seat</th>
                    <th>Gender</th>
                    <th>Year</th>
                    <th>Open</th>
                    <th>Close</th>
                  </tr>
                </thead>
                <tbody></tbody>
              </table>
            </div>
          </div>

        </main>
      </div>
    </div>
  </section>

  <script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
  <script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
  <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
  <script src="assets/js/dashboard.js"></script>
</body>

</html>