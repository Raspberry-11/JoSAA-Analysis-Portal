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
    .forEach(sel => $(sel).val(null).trigger('change.select2'));
}

// Rebuild a select's options from `items`, keeping any still-valid current
// selection, WITHOUT firing the plain 'change' event (avoids cascade recursion).
function repopulate(sel, items, valKey = 'id', labelKey = 'label') {
  const $el = $(sel);
  const current = ($el.val() || []).map(String);
  $el.empty();
  const avail = new Set();
  items.forEach(it => {
    const v = typeof it === 'object' ? it[valKey] : it;
    const l = typeof it === 'object' ? it[labelKey] : it;
    avail.add(String(v));
    $el.append(new Option(l, v));
  });
  $el.val(current.filter(v => avail.has(v))).trigger('change.select2');
}

// Cascading filters: when a filter changes, narrow every OTHER filter to the
// options still available given the current selection (e.g. pick an IIT → Branch
// shows only branches offered there), then refresh the table/KPIs.
let cascading = false;
async function refreshCascade() {
  if (cascading) return;
  cascading = true;
  try {
    const opts = await apiCall('filter_options', currentFilters());
    repopulate('#f-year', opts.years);
    repopulate('#f-iit', opts.iits);
    repopulate('#f-branch', opts.branches);
    repopulate('#f-quota', opts.quotas);
    repopulate('#f-seat', opts.seatTypes);
    repopulate('#f-gender', opts.genders);
    repopulate('#f-round', opts.rounds);
  } catch (e) {
    console.error('cascade failed', e);
  } finally {
    cascading = false;
  }
  loadFiltered();
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

function renderBranchOrder(rows) {
  makeChart('chart-toughest', {
    type: 'bar',
    data: {
      labels: rows.map(r => r.branch.trim()),
      datasets: [{
        label: 'Avg Closing Rank',
        data: rows.map(r => +r.avg_close),
        backgroundColor: '#ef4444',
      }],
    },
    options: {
      indexAxis: 'y',
      responsive: true,
      maintainAspectRatio: false,
      plugins: {
        legend: { display: false },
        tooltip: {
          callbacks: {
            afterLabel: ctx => `Offered at ${rows[ctx.dataIndex].iit_coverage} IITs`,
          },
        },
      },
      scales: { x: { title: { display: true, text: 'Avg Closing Rank (lower = more preferred)' } } },
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

function renderOldVsNew(rows) {
  const years = [...new Set(rows.map(r => r.year))].sort();
  const labels = { old: 'Old IITs (original 8)', new: 'New IITs' };
  const colors = { old: '#4f46e5', new: '#f59e0b' };
  const gens = [...new Set(rows.map(r => r.generation))];

  const datasets = gens.map(gen => ({
    label: labels[gen] || gen,
    data: years.map(y => {
      const row = rows.find(r => r.year === y && r.generation === gen);
      return row ? +row.avg_close : null;
    }),
    borderColor: colors[gen] || '#888',
    backgroundColor: (colors[gen] || '#888') + '22',
    tension: 0.3,
  }));

  makeChart('chart-round-drop', {
    type: 'line',
    data: { labels: years, datasets },
    options: {
      responsive: true,
      maintainAspectRatio: false,
      plugins: { legend: { position: 'bottom' } },
      scales: { y: { reverse: true, title: { display: true, text: 'Avg Closing Rank (lower = tougher)' } } },
    },
  });
}

function renderGenderGap(rows) {
  const years = [...new Set(rows.map(r => r.year))].sort();
  const isFemale = g => /female/i.test(g);
  const series = [
    { code: rows.find(r => !isFemale(r.gender_code))?.gender_code, label: 'Gender-Neutral', color: '#0ea5e9' },
    { code: rows.find(r => isFemale(r.gender_code))?.gender_code, label: 'Female-only (Supernumerary)', color: '#ec4899' },
  ].filter(s => s.code);

  const datasets = series.map(s => ({
    label: s.label,
    data: years.map(y => {
      const row = rows.find(r => r.year === y && r.gender_code === s.code);
      return row ? +row.avg_close : null;
    }),
    borderColor: s.color,
    backgroundColor: s.color + '22',
    tension: 0.3,
  }));

  makeChart('chart-volatility', {
    type: 'line',
    data: { labels: years, datasets },
    options: {
      responsive: true,
      maintainAspectRatio: false,
      plugins: { legend: { position: 'bottom' } },
      scales: { y: { reverse: true, title: { display: true, text: 'Avg CSE Closing Rank (lower = tougher)' } } },
    },
  });
}

function renderNewAgeGrowth(rows) {
  makeChart('chart-top100', {
    type: 'bar',
    data: {
      labels: rows.map(r => r.year),
      datasets: [{
        label: 'New-Age program offerings',
        data: rows.map(r => +r.offerings),
        backgroundColor: '#10b981',
      }],
    },
    options: {
      responsive: true,
      maintainAspectRatio: false,
      plugins: {
        legend: { display: false },
        tooltip: {
          callbacks: {
            afterLabel: ctx => `Across ${rows[ctx.dataIndex].iits_offering} IITs`,
          },
        },
      },
      scales: { y: { title: { display: true, text: '# distinct AI / DS / M&C programs' }, beginAtZero: true } },
    },
  });
}

function renderGenderImpact(rows) {
  // Q3: per-year gap = avg(Female-only closing rank) − avg(Gender-Neutral) across branches.
  const years = [...new Set(rows.map(r => r.year))].sort();
  const isF = g => /female/i.test(g);
  const mean = a => (a.length ? a.reduce((x, y) => x + y, 0) / a.length : null);
  const gap = years.map(y => {
    const yr = rows.filter(r => r.year === y);
    const fa = mean(yr.filter(r => isF(r.gender_code)).map(r => +r.avg_close));
    const na = mean(yr.filter(r => !isF(r.gender_code)).map(r => +r.avg_close));
    return (fa != null && na != null) ? Math.round(fa - na) : null;
  });
  makeChart('chart-gender', {
    type: 'bar',
    data: { labels: years, datasets: [{
      label: 'Female-only − Gender-Neutral (avg closing-rank gap)',
      data: gap, backgroundColor: '#ec4899',
    }] },
    options: {
      responsive: true, maintainAspectRatio: false,
      plugins: { legend: { display: false } },
      scales: { y: { title: { display: true, text: 'Rank gap (higher = bigger female disadvantage)' } } },
    },
  });
}

function renderTradeoff(rows) {
  // Q7: grouped bar — old vs new IITs × CSE-family vs other branch.
  const gens = [...new Set(rows.map(r => r.generation))];
  const tiers = [...new Set(rows.map(r => r.branch_tier))];
  const colors = { 'Top Branch (CSE Family)': '#4f46e5', 'Other Branch': '#f59e0b' };
  const datasets = tiers.map(t => ({
    label: t,
    data: gens.map(g => {
      const row = rows.find(r => r.generation === g && r.branch_tier === t);
      return row ? +row.avg_close : null;
    }),
    backgroundColor: colors[t] || '#888',
  }));
  makeChart('chart-tradeoff', {
    type: 'bar',
    data: { labels: gens.map(g => g === 'old' ? 'Old IITs' : 'New IITs'), datasets },
    options: {
      responsive: true, maintainAspectRatio: false,
      plugins: { legend: { position: 'bottom' } },
      scales: { y: { reverse: true, title: { display: true, text: 'Avg Closing Rank (top-5000 seats)' } } },
    },
  });
}

function renderCategoryGaps(rows) {
  // Q8: avg closing rank by seat-type category (aggregated across IIT-branch).
  // NOTE: OPEN uses the Common Rank List; reserved categories use category-specific
  // rank lists, so magnitudes are NOT directly comparable — labelled accordingly.
  const order = ['OPEN', 'OBC-NCL', 'SC', 'ST'];
  const byCat = {};
  rows.forEach(r => { (byCat[r.seat_type_code] = byCat[r.seat_type_code] || []).push(+r.avg_close); });
  const cats = order.filter(c => byCat[c]);
  const data = cats.map(c => Math.round(byCat[c].reduce((a, b) => a + b, 0) / byCat[c].length));
  makeChart('chart-category', {
    type: 'bar',
    data: { labels: cats, datasets: [{
      label: 'Avg Closing Rank', data,
      backgroundColor: ['#4f46e5', '#10b981', '#f59e0b', '#ef4444'],
    }] },
    options: {
      indexAxis: 'y', responsive: true, maintainAspectRatio: false,
      plugins: { legend: { display: false } },
      scales: { x: { title: { display: true, text: 'Avg closing rank (category-specific rank lists — not directly comparable)' } } },
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
  setStatus('Loading analytics…', 'bg-warning');
  // Each chart loads independently so one failing endpoint can't blank the rest.
  const jobs = [
    ['q1_cse_trend',       renderCseTrend,     'chart-cse-trend'],
    ['q5_hierarchy',       renderIITHierarchy, 'chart-iit-rank'],
    ['q2_branch_order',    renderBranchOrder,  'chart-toughest'],
    ['q4_newage',          renderNewAge,       'chart-newage'],
    ['q6_old_vs_new',      renderOldVsNew,     'chart-round-drop'],
    ['q9_gender_gap',      renderGenderGap,    'chart-volatility'],
    ['q3_gender',          renderGenderImpact, 'chart-gender'],
    ['q7_tradeoff',        renderTradeoff,     'chart-tradeoff'],
    ['q8_category',        renderCategoryGaps, 'chart-category'],
    ['q10_new_age_growth', renderNewAgeGrowth, 'chart-top100'],
  ];
  const results = await Promise.allSettled(
    jobs.map(async ([action, render, ctxId]) => {
      const data = await apiCall(action);
      render(data);
    })
  );
  const failed = results.filter(r => r.status === 'rejected');
  failed.forEach((r, i) => console.error('Chart failed:', jobs[i], r.reason));
  if (failed.length) {
    setStatus(`Loaded with ${failed.length} chart error(s)`, 'bg-warning');
  } else {
    setStatus('Ready', 'bg-success');
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
$('#reset-filters').on('click', () => { resetFilters(); refreshCascade(); });

// Cascade filter options whenever any filter selection changes.
$('#f-year, #f-iit, #f-branch, #f-quota, #f-seat, #f-gender, #f-round')
  .on('change', () => { if (!cascading) refreshCascade(); });

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
