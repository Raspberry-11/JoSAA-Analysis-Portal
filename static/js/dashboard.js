/* =========================================================
 *  JOSAA Analytics Dashboard
 *  Vanilla JS + jQuery + Chart.js
 * ========================================================= */

const API = '/api';
const charts = {};

const PALETTE = ['#4f46e5', '#ef4444', '#10b981', '#f59e0b', '#0ea5e9',
  '#8b5cf6', '#ec4899', '#14b8a6', '#f97316', '#06b6d4'];

// ---------------------------------------------------------
//  API helpers
// ---------------------------------------------------------
async function apiCall(action, body = null) {
  const opts = body
    ? { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body) }
    : { method: 'GET' };
  const res = await fetch(`${API}?action=${action}`, opts);
  const json = await res.json();
  if (json.status !== 'ok') throw new Error(json.message || 'API error');
  return json.data;
}

function setStatus(text, cls = 'bg-secondary') {
  $('#status-pill').removeClass().addClass(`badge ${cls}`).text(text);
}

// ---------------------------------------------------------
//  Filter helpers
// ---------------------------------------------------------
function fillSelect(id, items, valKey = 'id', labelKey = 'label') {
  const $el = $(id).empty();
  items.forEach(it => {
    const v = typeof it === 'object' ? it[valKey] : it;
    const l = typeof it === 'object' ? it[labelKey] : it;
    $el.append(new Option(l, v));
  });
  $el.select2({ theme: 'bootstrap-5', placeholder: 'All', allowClear: true, width: '100%' });
}

function currentFilters() {
  const asInts = sel => ($(sel).val() || []).map(Number);
  return {
    years: asInts('#f-year'),
    iits: asInts('#f-iit'),
    branches: asInts('#f-branch'),
    quotas: asInts('#f-quota'),
    seatTypes: asInts('#f-seat'),
    genders: asInts('#f-gender'),
    rounds: asInts('#f-round'),
  };
}

function resetFilters() {
  ['#f-year', '#f-iit', '#f-branch', '#f-quota', '#f-seat', '#f-gender', '#f-round']
    .forEach(sel => $(sel).val(null).trigger('change'));
}

// ---------------------------------------------------------
//  Chart helpers
// ---------------------------------------------------------
function makeChart(ctxId, config) {
  const el = document.getElementById(ctxId);
  if (!el) return;
  clearChartEmptyState(ctxId);
  if (charts[ctxId]) charts[ctxId].destroy();
  charts[ctxId] = new Chart(el, config);
}

function shortIIT(name) {
  return String(name).replace('Indian Institute of Technology', 'IIT').trim();
}

function clearChartEmptyState(ctxId) {
  const el = document.getElementById(ctxId);
  const wrap = el?.parentElement;
  wrap?.querySelector('.chart-empty-state')?.remove();
  if (el) el.hidden = false;
}

function renderChartEmptyState(ctxId, message) {
  const el = document.getElementById(ctxId);
  const wrap = el?.parentElement;
  if (!el || !wrap) return;
  if (charts[ctxId]) {
    charts[ctxId].destroy();
    delete charts[ctxId];
  }
  el.hidden = true;
  wrap.querySelector('.chart-empty-state')?.remove();
  const empty = document.createElement('div');
  empty.className = 'chart-empty-state';
  empty.textContent = message;
  wrap.appendChild(empty);
}

// ---------------------------------------------------------
//  Chart Renderers
// ---------------------------------------------------------
function renderCseTrend(rows) {
  const years = [...new Set(rows.map(r => r.year))].sort();
  const iits = [...new Set(rows.map(r => r.iit_name))];

  const datasets = iits.map((name, i) => ({
    label: shortIIT(name),
    data: years.map(y => {
      const row = rows.find(r => r.year === y && r.iit_name === name);
      return row ? +row.avg_close : null;
    }),
    borderColor: PALETTE[i % PALETTE.length],
    backgroundColor: PALETTE[i % PALETTE.length] + '22',
    tension: 0.3,
    fill: false,
  }));

  makeChart('chart-cse-trend', {
    type: 'line',
    data: { labels: years, datasets },
    options: {
      responsive: true,
      maintainAspectRatio: false,
      plugins: { legend: { position: 'bottom' } },
      scales: {
        y: { title: { display: true, text: 'Avg Closing Rank' }, reverse: true },
        x: { title: { display: true, text: 'Year' } },
      },
    },
  });
}

function renderIITHierarchy(rows) {
  const top = rows.slice(0, 15);
  makeChart('chart-iit-rank', {
    type: 'bar',
    data: {
      labels: top.map(r => shortIIT(r.iit_name)),
      datasets: [{
        label: 'Median Closing Rank',
        data: top.map(r => +r.median_close),
        backgroundColor: '#4f46e5',
      }],
    },
    options: {
      indexAxis: 'y',
      responsive: true,
      maintainAspectRatio: false,
      plugins: { legend: { display: false } },
      scales: { x: { title: { display: true, text: 'Median Closing Rank (lower = better)' } } },
    },
  });
}

function renderToughest(rows) {
  makeChart('chart-toughest', {
    type: 'bar',
    data: {
      labels: rows.map(r => r.branch_name.length > 45
        ? r.branch_name.slice(0, 42) + '…' : r.branch_name),
      datasets: [{
        label: 'Median Closing Rank',
        data: rows.map(r => +r.median_close),
        backgroundColor: '#ef4444',
      }],
    },
    options: {
      indexAxis: 'y',
      responsive: true,
      maintainAspectRatio: false,
      plugins: { legend: { display: false } },
    },
  });
}

function renderNewAge(rows) {
  const years = [...new Set(rows.map(r => r.year))].sort();
  const categories = [...new Set(rows.map(r => r.category))];
  const colors = { new_age: '#10b981', core: '#f59e0b', cse_family: '#4f46e5' };
  const labels = { new_age: 'New-Age (AI / DS / M&C)', core: 'Core (Mech / Civil / Chem)', cse_family: 'CSE Family' };

  const datasets = categories.map(c => ({
    label: labels[c] || c,
    data: years.map(y => {
      const row = rows.find(r => r.year === y && r.category === c);
      return row ? +row.avg_close : null;
    }),
    borderColor: colors[c] || '#888',
    backgroundColor: (colors[c] || '#888') + '22',
    tension: 0.3,
  }));

  makeChart('chart-newage', {
    type: 'line',
    data: { labels: years, datasets },
    options: {
      responsive: true,
      maintainAspectRatio: false,
      plugins: { legend: { position: 'bottom' } },
      scales: { y: { reverse: true, title: { display: true, text: 'Avg Closing Rank' } } },
    },
  });
}

function renderRoundDrop(rows) {
  if (!rows.length) {
    renderChartEmptyState(
      'chart-round-drop',
      'No Round 1 rows are available in the database, so Round 1 to final-round inflation cannot be calculated.'
    );
    return;
  }

  const usesFallback = rows.some(r => r.metric_basis === 'final_opening_to_closing');
  const label = usesFallback ? 'Avg Final-Round Rank Spread (Open -> Close)' : 'Avg Rank Inflation (R1 -> Final)';

  makeChart('chart-round-drop', {
    type: 'bar',
    data: {
      labels: rows.map(r => r.year),
      datasets: [{
        label,
        data: rows.map(r => +r.avg_rank_inflation),
        backgroundColor: '#f59e0b',
      }],
    },
    options: {
      responsive: true,
      maintainAspectRatio: false,
      plugins: {
        legend: { display: false },
        tooltip: {
          callbacks: {
            afterLabel: ctx => {
              const row = rows[ctx.dataIndex];
              return row?.metric_basis === 'final_opening_to_closing'
                ? 'Basis: final-round closing rank - opening rank'
                : 'Basis: final-round closing rank - Round 1 closing rank';
            },
          },
        },
      },
      scales: { y: { title: { display: true, text: usesFallback ? 'Rank Spread' : 'Rank Drift' } } },
    },
  });
}

function renderVolatility(rows) {
  const top = rows.slice(0, 12);
  makeChart('chart-volatility', {
    type: 'bar',
    data: {
      labels: top.map(r => `${shortIIT(r.iit_name)} · ${r.branch_name.slice(0, 25)}`),
      datasets: [{
        label: 'Rank Volatility (StdDev)',
        data: top.map(r => +r.volatility),
        backgroundColor: '#8b5cf6',
      }],
    },
    options: {
      indexAxis: 'y',
      responsive: true,
      maintainAspectRatio: false,
      plugins: { legend: { display: false } },
    },
  });
}

function renderTop100(rows) {
  const years = [...new Set(rows.map(r => r.year))].sort();
  const iits = [...new Set(rows.map(r => r.iit_name))];

  const datasets = iits.map((name, i) => ({
    label: shortIIT(name),
    data: years.map(y => {
      const row = rows.find(r => r.year === y && r.iit_name === name);
      return row ? +row.top100_seats : 0;
    }),
    backgroundColor: PALETTE[i % PALETTE.length],
  }));

  makeChart('chart-top100', {
    type: 'bar',
    data: { labels: years, datasets },
    options: {
      responsive: true,
      maintainAspectRatio: false,
      plugins: { legend: { position: 'bottom' } },
      scales: {
        x: { stacked: true },
        y: { stacked: true, title: { display: true, text: '# Seats filled at AIR ≤ 100' } },
      },
    },
  });
}

// ---------------------------------------------------------
//  Table + KPIs
// ---------------------------------------------------------
function renderTable(rows) {
  const $tb = $('#data-table tbody').empty();
  rows.slice(0, 300).forEach(r => {
    $tb.append(`
      <tr>
        <td>${shortIIT(r.iit_name)}</td>
        <td>${r.branch_name}</td>
        <td>${r.quota_code}</td>
        <td>${r.seat_type_code}</td>
        <td>${r.gender_code}</td>
        <td>${r.year}</td>
        <td>${r.opening_rank}</td>
        <td>${r.closing_rank}</td>
      </tr>`);
  });
  const shown = Math.min(rows.length, 300);
  $('#table-row-count').text(`Showing ${shown} of ${rows.length} rows`);
}

function renderKPIs(rows) {
  if (!rows.length) {
    $('#kpi-rows, #kpi-avg-close, #kpi-best-open, #kpi-branches').text('—');
    return;
  }
  const closes = rows.map(r => +r.closing_rank);
  const opens = rows.map(r => +r.opening_rank);
  const branches = new Set(rows.map(r => r.branch_name));

  $('#kpi-rows').text(rows.length.toLocaleString());
  $('#kpi-avg-close').text(Math.round(closes.reduce((a, b) => a + b, 0) / closes.length).toLocaleString());
  $('#kpi-best-open').text(Math.min(...opens).toLocaleString());
  $('#kpi-branches').text(branches.size);
}

// ---------------------------------------------------------
//  Bootstrap flow
// ---------------------------------------------------------
async function loadAnalytics() {
  try {
    setStatus('Loading analytics…', 'bg-warning');
    const [cse, hier, tough, newage, roundDrop, vol, top100] = await Promise.all([
      apiCall('q1_cse_trend'),
      apiCall('q5_iit_hierarchy'),
      apiCall('q2_toughest'),
      apiCall('q4_newage_core'),
      apiCall('q6_round_drop'),
      apiCall('q9_volatility'),
      apiCall('q10_top100'),
    ]);
    renderCseTrend(cse);
    renderIITHierarchy(hier);
    renderToughest(tough);
    renderNewAge(newage);
    renderRoundDrop(roundDrop);
    renderVolatility(vol);
    renderTop100(top100);
    setStatus('Ready', 'bg-success');
  } catch (e) {
    console.error(e);
    setStatus('Error: ' + e.message, 'bg-danger');
  }
}

async function loadFiltered() {
  try {
    setStatus('Applying filters…', 'bg-warning');
    const rows = await apiCall('rows', currentFilters());
    renderTable(rows);
    renderKPIs(rows);
    setStatus(`Ready · ${rows.length} rows`, 'bg-success');
  } catch (e) {
    console.error(e);
    setStatus('Error: ' + e.message, 'bg-danger');
  }
}

async function init() {
  try {
    setStatus('Initializing…', 'bg-secondary');
    const opts = await apiCall('filters');
    fillSelect('#f-year', opts.years);
    fillSelect('#f-iit', opts.iits);
    fillSelect('#f-branch', opts.branches);
    fillSelect('#f-quota', opts.quotas);
    fillSelect('#f-seat', opts.seatTypes);
    fillSelect('#f-gender', opts.genders);
    fillSelect('#f-round', opts.rounds);

    await Promise.all([loadAnalytics(), loadFiltered()]);
  } catch (e) {
    console.error(e);
    setStatus('Init failed: ' + e.message, 'bg-danger');
  }
}

// ---------------------------------------------------------
//  Event bindings
// ---------------------------------------------------------
$('#apply-filters').on('click', loadFiltered);
$('#reset-filters').on('click', () => { resetFilters(); loadFiltered(); });

$('#export-csv').on('click', () => {
  const f = encodeURIComponent(JSON.stringify(currentFilters()));
  window.location = `${API}?action=export_csv&f=${f}`;
});

$('#export-pdf').on('click', () => {
  const f = encodeURIComponent(JSON.stringify(currentFilters()));
  window.open(`${API}?action=export_pdf&f=${f}`, '_blank');
});

// Kick off
$(init);
