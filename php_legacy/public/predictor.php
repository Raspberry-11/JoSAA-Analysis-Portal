<?php require_once 'navbar.php'; ?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>College Predictor | JOSAA Analytics</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="assets/css/custom.css" rel="stylesheet">
<style>
.predictor-results {
  max-height: 70vh;
  overflow-y: auto;
  border-radius: 8px;
  border: 1px solid var(--border);
  background: white;
}
.predictor-results .list-group-item {
  border-left: none;
  border-right: none;
  border-top: none;
}
</style>
</head>
<body class="bg-light">

<main class="container py-4">
  <div class="row g-4">
    <div class="col-lg-4">
      <div class="card shadow-sm border-0 bg-white">
        <div class="card-body p-4">
          <h4 class="mb-3 text-dark fw-bold">College Predictor</h4>
          <p class="text-muted text-sm mb-4">Enter your JEE rank, category, and gender to see likely IIT branch options based on historical cutoffs.</p>
          <form id="predictor-form">
            <div class="mb-3">
              <label class="form-label text-sm fw-medium">Category</label>
              <select id="pred-seat" name="category" class="form-select" required>
                <option value="OPEN">OPEN</option>
                <option value="OBC-NCL">OBC-NCL</option>
                <option value="SC">SC</option>
                <option value="ST">ST</option>
                <option value="EWS">EWS</option>
              </select>
            </div>
            <div class="mb-3">
              <label class="form-label text-sm fw-medium">Gender</label>
              <select id="pred-gender" name="gender" class="form-select" required>
                <option value="Gender-Neutral">Gender-Neutral</option>
                <option value="Female-only (including Supernumerary)">Female-only (including Supernumerary)</option>
              </select>
            </div>
            <div class="mb-4">
              <label class="form-label text-sm fw-medium">Your Rank</label>
              <input id="pred-rank" type="number" name="rank" class="form-control" placeholder="e.g. 2500" required>
            </div>
            <button type="submit" class="btn btn-success w-100 py-2">
              <span class="btn-text">Predict Colleges &rarr;</span>
              <span class="spinner-border spinner-border-sm d-none" role="status"></span>
            </button>
          </form>
        </div>
      </div>
    </div>

    <div class="col-lg-8">
      <div id="results-container">
        <div class="d-flex justify-content-between align-items-center mb-3">
          <h4 class="m-0 fw-bold">Predicted Options</h4>
        </div>
        <div class="predictor-results list-group shadow-sm" id="predictor-results">
          <div class="text-center text-muted py-5">
            Enter your details and click Predict Colleges.
          </div>
        </div>
      </div>
    </div>
  </div>
</main>

<script>
const form = document.getElementById('predictor-form');
const results = document.getElementById('predictor-results');

function escHtml(value) {
  return String(value ?? '').replace(/[&<>"']/g, char => ({
    '&': '&amp;',
    '<': '&lt;',
    '>': '&gt;',
    '"': '&quot;',
    "'": '&#39;'
  }[char]));
}

function fmt(value) {
  const num = Number(value);
  return Number.isFinite(num) ? num.toLocaleString() : escHtml(value);
}

function shortBranch(name) {
  const trimmed = String(name || '').split('(')[0].trim();
  return trimmed.length > 70 ? `${trimmed.slice(0, 67)}...` : trimmed;
}

function chanceClass(chance) {
  return {
    'Very High': 'success',
    'High': 'primary',
    'Moderate': 'warning',
    'Low': 'danger',
    'Very Low': 'secondary'
  }[chance] || 'secondary';
}

function renderPrediction(data) {
  if (!data.options || !data.options.length) {
    results.innerHTML = `
      <div class="alert alert-warning m-3">
        No realistic options found for AIR <strong>${fmt(data.user_rank)}</strong>
        in <strong>${escHtml(data.seat_type)}</strong> / <strong>${escHtml(data.gender)}</strong>.
      </div>`;
    return;
  }

  results.innerHTML = `
    <div class="list-group-item p-3 bg-light">
      <div class="row text-center g-3">
        <div class="col-4"><div class="fw-bold fs-4">${fmt(data.user_rank)}</div><div class="text-muted small">Your AIR</div></div>
        <div class="col-4"><div class="fw-bold fs-4">${fmt(data.total_options)}</div><div class="text-muted small">Options</div></div>
        <div class="col-4"><div class="fw-bold fs-6">${escHtml(data.seat_type)}</div><div class="text-muted small">Category</div></div>
      </div>
    </div>
    ${data.options.map(option => `
      <div class="list-group-item p-3">
        <div class="d-flex justify-content-between gap-3 align-items-start">
          <div>
            <h6 class="mb-1 fw-bold text-primary">${escHtml(option.iit_short || option.iit_name)}</h6>
            <div class="text-dark">${escHtml(shortBranch(option.branch_name))}</div>
            <div class="text-muted small mt-2">${escHtml(option.reasoning || '')}</div>
          </div>
          <span class="badge text-bg-${chanceClass(option.chance)}">${escHtml(option.chance)}</span>
        </div>
        <div class="row g-2 mt-3 text-center small">
          <div class="col-4"><div class="fw-semibold">${fmt(option.latest_close)}</div><div class="text-muted">Latest CR</div></div>
          <div class="col-4"><div class="fw-semibold">${fmt(option.avg_close)}</div><div class="text-muted">Avg CR</div></div>
          <div class="col-4"><div class="fw-semibold">${fmt(option.min_close)}-${fmt(option.max_close)}</div><div class="text-muted">Range</div></div>
        </div>
      </div>
    `).join('')}`;
}

async function runPrediction() {
  const rank = parseInt(form.elements.rank.value, 10);
  const seatType = form.elements.category.value;
  const gender = form.elements.gender.value;

  if (!rank || rank < 1) {
    results.innerHTML = '<div class="alert alert-warning m-3">Please enter a valid rank.</div>';
    return;
  }

  results.innerHTML = '<div class="text-center text-muted py-5"><div class="spinner-border text-success" role="status"></div><div class="mt-2">Finding predicted options...</div></div>';

  try {
    const response = await fetch('api.php?action=predict_by_rank', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ rank, seat_type: seatType, gender })
    });
    const json = await response.json();
    if (json.status !== 'ok') throw new Error(json.message || 'Prediction failed');
    renderPrediction(json.data);
  } catch (error) {
    results.innerHTML = `<div class="alert alert-danger m-3">${escHtml(error.message)}</div>`;
  }
}

form.addEventListener('submit', event => {
  event.preventDefault();
  runPrediction();
});

const params = new URLSearchParams(window.location.search);
if (params.has('rank')) {
  form.elements.rank.value = params.get('rank') || '';
  form.elements.category.value = params.get('category') || 'OPEN';
  form.elements.gender.value = params.get('gender') || 'Gender-Neutral';
  runPrediction();
}
</script>
</body>
</html>
