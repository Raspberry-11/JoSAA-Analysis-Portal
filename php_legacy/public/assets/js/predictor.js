/* =========================================================
 *  JOSAA College Predictor + Preference Check
 * ========================================================= */

const PRED_API = 'api.php';

// Cache the dropdown options once loaded
let predictorOptions = null;

// ---------------------------------------------------------
// API helper
// ---------------------------------------------------------
async function predFetch(action, body = null) {
  const opts = body
    ? { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body) }
    : { method: 'GET' };
  const res = await fetch(`${PRED_API}?action=${action}`, opts);
  const json = await res.json();
  if (json.status !== 'ok') throw new Error(json.message || 'API error');
  return json.data;
}

// ---------------------------------------------------------
// Helpers
// ---------------------------------------------------------
function escHtml(s) {
  return String(s ?? '').replace(/[&<>"']/g, c => ({
    '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'
  }[c]));
}

function fmt(n) {
  if (n === null || n === undefined || n === '') return '—';
  const num = Number(n);
  return isNaN(num) ? n : num.toLocaleString();
}

function shortBranch(name) {
  if (!name) return '';
  // Strip parenthesized suffix: "Computer Science (4 Years, BTech)" → "Computer Science"
  const trimmed = String(name).split('(')[0].trim();
  return trimmed.length > 60 ? trimmed.slice(0, 57) + '…' : trimmed;
}

function shortIIT(name) {
  return String(name || '').replace('Indian Institute of Technology', 'IIT').trim();
}

const CHANCE_COLORS = {
  'Very High': { bg: '#d1fae5', border: '#10b981', text: '#065f46', icon: '' },
  'High':      { bg: '#dbeafe', border: '#3b82f6', text: '#1e40af', icon: '🟢' },
  'Moderate':  { bg: '#fef3c7', border: '#f59e0b', text: '#92400e', icon: '🟡' },
  'Low':       { bg: '#fee2e2', border: '#ef4444', text: '#991b1b', icon: '🟠' },
  'Very Low':  { bg: '#f3f4f6', border: '#6b7280', text: '#374151', icon: '' },
};

function chanceBadge(chance) {
  const c = CHANCE_COLORS[chance] || CHANCE_COLORS['Moderate'];
  return `<span class="chance-badge" style="background:${c.bg};color:${c.text};border-color:${c.border};">
    ${c.icon} ${escHtml(chance)}
  </span>`;
}

function setLoading(btnSelector, loading) {
  const $btn = $(btnSelector);
  $btn.prop('disabled', loading);
  $btn.find('.btn-text').toggleClass('d-none', loading);
  $btn.find('.spinner-border').toggleClass('d-none', !loading);
}

// ---------------------------------------------------------
// Populate dropdowns from /api.php?action=predictor_options
// ---------------------------------------------------------
async function loadPredictorOptions() {
  try {
    predictorOptions = await predFetch('predictor_options');

    // Seat types (raw values, e.g. 'OPEN', 'OBC-NCL')
    const seatOpts = predictorOptions.seat_types
      .map(v => `<option value="${escHtml(v)}" ${v === 'OPEN' ? 'selected' : ''}>${escHtml(v)}</option>`)
      .join('');
    $('#pred-seat, #pref-seat').html(seatOpts);

    // Gender values
    const genderOpts = predictorOptions.genders
      .map(v => `<option value="${escHtml(v)}" ${v === 'Gender-Neutral' ? 'selected' : ''}>${escHtml(v)}</option>`)
      .join('');
    $('#pred-gender, #pref-gender').html(genderOpts);

    // IITs
    const iitOpts = '<option value="">— Select IIT —</option>' +
      predictorOptions.iits
        .map(i => `<option value="${i.id}">${escHtml(i.label)}</option>`)
        .join('');
    $('#pref-iit').html(iitOpts);

    // Branches
    const branchOpts = '<option value="">— Select Branch —</option>' +
      predictorOptions.branches
        .map(b => `<option value="${b.id}">${escHtml(shortBranch(b.label))}</option>`)
        .join('');
    $('#pref-branch').html(branchOpts);

    // Initialize Select2 on the bigger dropdowns
    $('#pref-iit, #pref-branch').select2({
      theme: 'bootstrap-5',
      width: '100%',
      placeholder: 'Search…',
    });
  } catch (err) {
    console.error('Failed to load predictor options:', err);
  }
}

// ---------------------------------------------------------
// MODE A: Predict by Rank
// ---------------------------------------------------------
async function runRankPrediction(e) {
  if (e) e.preventDefault();

  const rank   = parseInt($('#pred-rank').val(), 10);
  const seat   = $('#pred-seat').val();
  const gender = $('#pred-gender').val();

  if (!rank || rank < 1) {
    alert('Please enter a valid rank.');
    return;
  }

  setLoading('#predictor-form button[type=submit]', true);
  $('#predictor-results').html('<div class="text-center text-muted py-5"><div class="spinner-border" role="status"></div><div class="mt-2">Analyzing 4 years of data…</div></div>');

  try {
    const result = await predFetch('predict_by_rank', {
      rank, seat_type: seat, gender,
    });
    renderRankResults(result);
  } catch (err) {
    $('#predictor-results').html(`<div class="alert alert-danger">${escHtml(err.message)}</div>`);
  } finally {
    setLoading('#predictor-form button[type=submit]', false);
  }
}

function renderRankResults(result) {
  if (!result.options || !result.options.length) {
    $('#predictor-results').html(`
      <div class="alert alert-warning">
        No realistic options found for AIR <strong>${fmt(result.user_rank)}</strong>
        in <strong>${escHtml(result.seat_type)}</strong> · <strong>${escHtml(result.gender)}</strong>.
        Try a different seat type or check your rank.
      </div>
    `);
    return;
  }

  // Group by chance bucket
  const grouped = {};
  result.options.forEach(o => {
    if (!grouped[o.chance]) grouped[o.chance] = [];
    grouped[o.chance].push(o);
  });

  // Reverse order for low chances first
  const order = ['Unlikely', 'Very Low', 'Low', 'Moderate', 'High', 'Very High'];

  let html = `
    <div class="predictor-summary">
      <div class="summary-stat">
        <div class="stat-value">${fmt(result.user_rank)}</div>
        <div class="stat-label">Your AIR</div>
      </div>
      <div class="summary-stat">
        <div class="stat-value">${result.total_options}</div>
        <div class="stat-label">Options Found</div>
      </div>
      <div class="summary-stat">
        <div class="stat-value">${escHtml(result.seat_type)}</div>
        <div class="stat-label">Seat Type</div>
      </div>
      <div class="summary-stat">
        <div class="stat-value">${(grouped['Very High']?.length || 0) + (grouped['High']?.length || 0)}</div>
        <div class="stat-label">High Chance</div>
      </div>
    </div>
  `;

  for (const bucket of order) {
    if (!grouped[bucket]) continue;
    html += `
      <div class="chance-group">
        <h5 class="chance-group-title">
          ${chanceBadge(bucket)}
          <span class="chance-count">${grouped[bucket].length} option${grouped[bucket].length > 1 ? 's' : ''}</span>
        </h5>
        <div class="result-grid">
          ${grouped[bucket].map(o => renderOptionCard(o)).join('')}
        </div>
      </div>
    `;
  }

  $('#predictor-results').html(html);
}

function renderOptionCard(o) {
  return `
    <div class="result-card">
      <div class="result-card-header">
        <div class="result-iit">${escHtml(o.iit_short || shortIIT(o.iit_name))}</div>
        <div class="result-branch" title="${escHtml(o.branch_name)}">${escHtml(shortBranch(o.branch_name))}</div>
      </div>
      <div class="result-stats">
        <div class="stat-pair">
          <div class="stat-mini-label">Latest Closing</div>
          <div class="stat-mini-value">${fmt(o.latest_close)}</div>
        </div>
        <div class="stat-pair">
          <div class="stat-mini-label">Avg Closing</div>
          <div class="stat-mini-value">${fmt(o.avg_close)}</div>
        </div>
        <div class="stat-pair">
          <div class="stat-mini-label">Range</div>
          <div class="stat-mini-value">${fmt(o.min_close)}–${fmt(o.max_close)}</div>
        </div>
      </div>
      <div class="result-reasoning">${escHtml(o.reasoning || '')}</div>
    </div>
  `;
}

// ---------------------------------------------------------
// MODE B: Preference Check
// ---------------------------------------------------------
async function runPreferenceCheck(e) {
  if (e) e.preventDefault();

  const rank     = parseInt($('#pref-rank').val(), 10);
  const iitId    = parseInt($('#pref-iit').val(), 10);
  const branchId = parseInt($('#pref-branch').val(), 10);
  const seat     = $('#pref-seat').val();
  const gender   = $('#pref-gender').val();

  if (!rank || rank < 1) { alert('Enter a valid rank.'); return; }
  if (!iitId || !branchId) { alert('Select both IIT and Branch.'); return; }

  setLoading('#preference-form button[type=submit]', true);
  $('#preference-results').html('<div class="text-center text-muted py-5"><div class="spinner-border" role="status"></div><div class="mt-2">Checking your preference…</div></div>');

  try {
    const result = await predFetch('predict_for_preference', {
      rank, iit_id: iitId, branch_id: branchId,
      seat_type: seat, gender,
    });
    renderPreferenceResults(result, rank);
  } catch (err) {
    $('#preference-results').html(`<div class="alert alert-danger">${escHtml(err.message)}</div>`);
  } finally {
    setLoading('#preference-form button[type=submit]', false);
  }
}

function renderPreferenceResults(result, userRank) {
  if (!result.found) {
    let html = `
      <div class="alert alert-warning">
        <strong>${escHtml(result.message)}</strong>
        This combination may not have been offered, or had too few records.
        Showing alternatives below.
      </div>
    `;
    if (result.suggestions) {
      html += renderSuggestions(result.suggestions);
    }
    $('#preference-results').html(html);
    return;
  }

  const p = result.primary;
  const c = CHANCE_COLORS[p.chance] || CHANCE_COLORS['Moderate'];

  let html = `
    <div class="preference-primary" style="border-color:${c.border};">
      <div class="preference-header">
        <div>
          <h4 class="m-0">${escHtml(p.iit_short)} · ${escHtml(shortBranch(p.branch_name))}</h4>
          <div class="text-muted small">${escHtml(p.iit_name)}</div>
        </div>
        <div class="big-chance" style="background:${c.bg};color:${c.text};">
          ${c.icon} ${escHtml(p.chance)}
        </div>
      </div>

      <div class="preference-stats">
        <div class="pref-stat">
          <div class="pref-stat-label">Your AIR</div>
          <div class="pref-stat-value">${fmt(userRank)}</div>
        </div>
        <div class="pref-stat">
          <div class="pref-stat-label">Latest Closing</div>
          <div class="pref-stat-value">${fmt(p.latest_close)}</div>
        </div>
        <div class="pref-stat">
          <div class="pref-stat-label">Avg Closing</div>
          <div class="pref-stat-value">${fmt(p.avg_close)}</div>
        </div>
        <div class="pref-stat">
          <div class="pref-stat-label">Range</div>
          <div class="pref-stat-value">${fmt(p.min_close)}–${fmt(p.max_close)}</div>
        </div>
        <div class="pref-stat">
          <div class="pref-stat-label">Volatility (σ)</div>
          <div class="pref-stat-value">±${fmt(p.std_close)}</div>
        </div>
      </div>

      <div class="preference-reasoning">
         ${escHtml(p.reasoning)}
      </div>
    </div>
  `;

  // Yearly trend chart
  if (result.yearly_trend && result.yearly_trend.length > 0) {
    html += `
      <div class="preference-chart-card">
        <h6> Historical Closing Rank Trend</h6>
        <div class="preference-chart-wrap"><canvas id="preference-chart"></canvas></div>
      </div>
    `;
  }

  // Suggestions sections
  html += renderSuggestions({
    same_branch_other_iits:  result.same_branch_other_iits  || [],
    same_iit_other_branches: result.same_iit_other_branches || [],
  });

  $('#preference-results').html(html);

  // Render trend chart
  if (result.yearly_trend && result.yearly_trend.length > 0) {
    setTimeout(() => renderPreferenceChart(result.yearly_trend, userRank), 50);
  }
}

function renderSuggestions(s) {
  let html = '';

  if (s.same_branch_other_iits?.length) {
    html += `
      <div class="suggestions-block">
        <h5> Same branch at other IITs you can target</h5>
        <div class="result-grid">
          ${s.same_branch_other_iits.map(o => renderSuggestionCard(o, 'iit')).join('')}
        </div>
      </div>
    `;
  }

  if (s.same_iit_other_branches?.length) {
    html += `
      <div class="suggestions-block">
        <h5> Other branches at the same IIT you can target</h5>
        <div class="result-grid">
          ${s.same_iit_other_branches.map(o => renderSuggestionCard(o, 'branch')).join('')}
        </div>
      </div>
    `;
  }

  if (!html) {
    html = '<div class="alert alert-info">No close alternatives found for your rank range.</div>';
  }

  return html;
}

function renderSuggestionCard(o, focusOn) {
  const title = focusOn === 'iit'
    ? escHtml(o.iit_short || shortIIT(o.iit_name))
    : escHtml(shortBranch(o.branch_name));
  const subtitle = focusOn === 'iit'
    ? escHtml(o.iit_name || '')
    : escHtml(o.iit_short || '');

  return `
    <div class="result-card">
      <div class="result-card-header">
        <div class="result-iit">${title}</div>
        <div class="result-branch">${subtitle}</div>
      </div>
      <div class="suggestion-chance">${chanceBadge(o.chance)}</div>
      <div class="result-stats">
        <div class="stat-pair">
          <div class="stat-mini-label">Latest</div>
          <div class="stat-mini-value">${fmt(o.latest_close)}</div>
        </div>
        <div class="stat-pair">
          <div class="stat-mini-label">Avg</div>
          <div class="stat-mini-value">${fmt(o.avg_close)}</div>
        </div>
        <div class="stat-pair">
          <div class="stat-mini-label">σ</div>
          <div class="stat-mini-value">±${fmt(o.std_close)}</div>
        </div>
      </div>
    </div>
  `;
}

// Trend chart for preference check
let preferenceChartInstance = null;
function renderPreferenceChart(yearly, userRank) {
  const canvas = document.getElementById('preference-chart');
  if (!canvas) return;

  if (preferenceChartInstance) preferenceChartInstance.destroy();

  const years = yearly.map(y => y.year);
  const closing = yearly.map(y => +y.closing);
  const opening = yearly.map(y => +y.opening);

  preferenceChartInstance = new Chart(canvas, {
    type: 'line',
    data: {
      labels: years,
      datasets: [
        {
          label: 'Closing Rank',
          data: closing,
          borderColor: '#ef4444',
          backgroundColor: '#ef444422',
          tension: 0.3,
          fill: true,
        },
        {
          label: 'Opening Rank',
          data: opening,
          borderColor: '#10b981',
          backgroundColor: '#10b98122',
          tension: 0.3,
          fill: false,
        },
        {
          label: `Your AIR (${userRank.toLocaleString()})`,
          data: years.map(() => userRank),
          borderColor: '#4f46e5',
          borderDash: [6, 4],
          pointRadius: 0,
          fill: false,
        },
      ],
    },
    options: {
      responsive: true,
      maintainAspectRatio: false,
      plugins: { legend: { position: 'bottom' } },
      scales: {
        y: {
          reverse: true,
          title: { display: true, text: 'Rank (lower = better)' },
        },
      },
    },
  });
}

// ---------------------------------------------------------
// Boot
// ---------------------------------------------------------
$(() => {
  if (!$.fn.select2) $.fn.select2 = function () { return this; };

  const optionsPromise = loadPredictorOptions();
  $('#predictor-form').on('submit', runRankPrediction);
  $('#preference-form').on('submit', runPreferenceCheck);

  const params = new URLSearchParams(window.location.search);
  const rank = params.get('rank');
  const seat = params.get('category') || params.get('seat_type');
  const gender = params.get('gender');

  if ($('#predictor-form').length && rank) {
    $('#pred-rank').val(rank);
    if (seat) $('#pred-seat').val(seat);
    if (gender) $('#pred-gender').val(gender);

    optionsPromise.finally(() => {
      if (seat) $('#pred-seat').val(seat);
      if (gender) $('#pred-gender').val(gender);
      runRankPrediction();
    });
  }
});
