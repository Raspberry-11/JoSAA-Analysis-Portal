/* =========================================================
 *  JOSAA AI Chat
 *  NL → SQL → Chart rendering
 * ========================================================= */

const AI_API = '/api';

// Conversation state (in-memory; up to 3 turns = 6 messages sent to backend)
const conversation = [];
let aiChartCounter = 0;           // unique id for each generated chart
const aiCharts = {};              // id → Chart.js instance

// ---------------------------------------------------------
// Utilities
// ---------------------------------------------------------
async function aiFetch(action, body = null) {
  const opts = body
    ? { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body) }
    : { method: 'GET' };
  const res = await fetch(`${AI_API}?action=${action}`, opts);
  const json = await res.json();
  if (json.status !== 'ok') throw new Error(json.message || 'API error');
  return json.data;
}

function setAILoading(loading) {
  const $send = $('#ai-send');
  if (loading) {
    $send.prop('disabled', true);
    $send.find('.btn-text').text('Thinking…');
    $send.find('.spinner-border').removeClass('d-none');
  } else {
    $send.prop('disabled', false);
    $send.find('.btn-text').text('Ask');
    $send.find('.spinner-border').addClass('d-none');
  }
}

function escapeHtml(s) {
  return String(s).replace(/[&<>"']/g, c => ({
    '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'
  }[c]));
}

function updateTurnCount() {
  const turns = Math.floor(conversation.length / 2);
  $('#ai-turn-count').text(
    turns === 0 ? 'No messages yet' :
    turns === 1 ? '1 exchange' : `${turns} exchanges`
  );
  if (turns === 0) {
    $('#ai-context-status').removeClass().addClass('badge bg-secondary').text('Fresh session');
  } else if (turns >= 3) {
    $('#ai-context-status').removeClass().addClass('badge bg-warning').text('Context full (3 turns)');
  } else {
    $('#ai-context-status').removeClass().addClass('badge bg-info').text(`${turns} in context`);
  }
}

// ---------------------------------------------------------
// Render: user + assistant messages
// ---------------------------------------------------------
function renderUserMessage(question) {
  $('#ai-thread').append(`
    <div class="msg msg-user">
      <div class="msg-bubble">${escapeHtml(question)}</div>
    </div>
  `);
  scrollThread();
}

function renderError(message) {
  $('#ai-thread').append(`
    <div class="msg msg-assistant">
      <div class="msg-bubble msg-error">
         <strong>Error:</strong> ${escapeHtml(message)}
      </div>
    </div>
  `);
  scrollThread();
}

function renderAssistantMessage(response) {
  const id = ++aiChartCounter;
  const chartId = `ai-chart-${id}`;

  // Determine what to show based on response_type from backend
  const type = response.response_type || 'text';
  const hasData = response.data && response.data.length > 0;

  // ── Visual block (chart, table, or nothing — text answers stand alone)
  let visualHtml = '';

  if (type === 'chart' && response.chart && hasData) {
    visualHtml = `<div class="ai-chart-wrap"><canvas id="${chartId}"></canvas></div>`;
  } else if (type === 'table' && hasData) {
    visualHtml = `<div class="ai-table-block">${buildTableHtml(response.data, 25)}</div>`;
  }
  // type === 'text' → no visual, just the answer

  // ── "View data" details (always available if there's data, always visible now)
  const dataDetailsHtml = hasData
    ? `<div class="ai-raw-data mt-3">
         <div class="fw-bold mb-2 text-muted"> Raw data (${response.row_count} row${response.row_count === 1 ? '' : 's'})</div>
         ${buildTableHtml(response.data, 50)}
       </div>`
    : '';

  // ── Provenance badges
  const cachedBadge = response.cached
    ? '<span class="badge bg-success ms-1">⚡ Cached</span>'
    : `<span class="badge bg-info ms-1"> ${escapeHtml(response.model || 'LLM')}</span>`;

  const typeBadge = {
    text:  '<span class="badge bg-secondary ms-1"> Text</span>',
    table: '<span class="badge bg-secondary ms-1"> Table</span>',
    chart: '<span class="badge bg-secondary ms-1"> Chart</span>',
  }[type] || '';

  // ── Title (for chart) or count (for table) or nothing (for text)
  const headerLine =
    type === 'chart' && response.chart?.title
      ? `<div class="ai-result-title">${escapeHtml(response.chart.title)}</div>`
      : type === 'table'
      ? `<div class="ai-result-title">${response.row_count} result${response.row_count === 1 ? '' : 's'}</div>`
      : '';

  // ── The main answer text — always shown, prominent for "text" type
  const answerHtml = response.answer
    ? `<div class="ai-answer ${type === 'text' ? 'ai-answer-prominent' : ''}">${escapeHtml(response.answer)}</div>`
    : '';

  const followupsHtml = (response.suggested_followups && response.suggested_followups.length > 0)
    ? `<div class="mt-3 ai-followups">
         <div class="small text-muted mb-2">Suggested follow-ups:</div>
         ${response.suggested_followups.map(q => `<button class="btn btn-sm btn-outline-primary rounded-pill mb-1 me-1 suggestion-chip" data-q="${escapeHtml(q)}">${escapeHtml(q)}</button>`).join('')}
       </div>`
    : '';

  $('#ai-thread').append(`
    <div class="msg msg-assistant" data-msg-id="${id}">
      <div class="msg-bubble">
        <div class="ai-meta-row">
          ${cachedBadge}${typeBadge}
        </div>

        ${headerLine}
        ${answerHtml}
        ${visualHtml}
        ${dataDetailsHtml}
        ${followupsHtml}

        <div class="ai-answer-actions">
          <button class="btn btn-sm btn-outline-success ai-rate-good" data-msg-id="${id}" title="Good response">
            👍🏻
          </button>
          <button class="btn btn-sm btn-outline-danger ai-rate-bad" data-msg-id="${id}" title="Bad response — removes from cache">
            👎🏻
          </button>
          <button class="btn btn-sm btn-outline-primary ai-export-pdf" data-msg-id="${id}">
             Export PDF
          </button>
          <button class="btn btn-sm btn-outline-secondary ai-copy-sql" data-msg-id="${id}">
             Copy SQL
          </button>
        </div>
      </div>
    </div>
  `);

  // Stash the full response on the element so export/copy can access it
  $(`.msg[data-msg-id="${id}"]`).data('response', response);

  // Render the chart if applicable
  if (type === 'chart' && response.chart && hasData) {
    renderAIChart(chartId, response);
  }

  scrollThread();
}

function scrollThread() {
  const el = document.getElementById('ai-thread');
  el.scrollTop = el.scrollHeight;
}

function buildTableHtml(data, rowLimit = 10) {
  if (!data.length) return '';
  const cols = Object.keys(data[0]);
  const shown = data.slice(0, rowLimit);

  let html = '<div class="table-responsive"><table class="table table-sm ai-data-table"><thead><tr>';
  cols.forEach(c => html += `<th>${escapeHtml(c)}</th>`);
  html += '</tr></thead><tbody>';
  shown.forEach(row => {
    html += '<tr>';
    cols.forEach(c => html += `<td>${escapeHtml(row[c] ?? '')}</td>`);
    html += '</tr>';
  });
  html += '</tbody></table></div>';
  if (data.length > rowLimit) {
    html += `<div class="small text-muted mt-1">Showing ${rowLimit} of ${data.length} rows.</div>`;
  }
  return html;
}

// ---------------------------------------------------------
// Chart renderer — interprets the LLM's chart spec
// ---------------------------------------------------------
function renderAIChart(canvasId, response) {
  const spec = response.chart;
  const data = response.data;
  if (!spec || !data.length) return;

  const canvas = document.getElementById(canvasId);
  if (!canvas) return;

  // Guard: if column names in spec don't match data, bail gracefully
  const cols = Object.keys(data[0]);
  if (spec.x && !cols.includes(spec.x)) {
    console.warn('Chart x column not in data:', spec.x, 'Available:', cols);
    return;
  }
  if (spec.y && !cols.includes(spec.y)) {
    console.warn('Chart y column not in data:', spec.y, 'Available:', cols);
    return;
  }

  const palette = ['#4f46e5', '#ef4444', '#10b981', '#f59e0b', '#0ea5e9',
                   '#8b5cf6', '#ec4899', '#14b8a6', '#f97316', '#06b6d4'];

  let chartConfig;

  // ─── With a `series` column, produce one dataset per series value
  if (spec.series && cols.includes(spec.series)) {
    const seriesValues = [...new Set(data.map(r => r[spec.series]))];
    const xValues = [...new Set(data.map(r => r[spec.x]))].sort((a, b) => {
      // Try numeric sort first
      if (!isNaN(+a) && !isNaN(+b)) return +a - +b;
      return String(a).localeCompare(String(b));
    });

    const datasets = seriesValues.map((sv, i) => ({
      label: String(sv),
      data: xValues.map(x => {
        const row = data.find(r => r[spec.x] === x && r[spec.series] === sv);
        return row ? +row[spec.y] : null;
      }),
      borderColor: palette[i % palette.length],
      backgroundColor: palette[i % palette.length] + (spec.type === 'line' ? '22' : 'cc'),
      tension: 0.3,
      fill: false,
    }));

    chartConfig = {
      type: spec.type === 'horizontalBar' ? 'bar' : spec.type,
      data: { labels: xValues, datasets },
      options: {
        responsive: true,
        maintainAspectRatio: false,
        indexAxis: spec.type === 'horizontalBar' ? 'y' : 'x',
        plugins: { legend: { position: 'bottom' } },
        scales: {
          y: { reverse: !!spec.y_reverse, title: { display: true, text: spec.y } },
          x: { title: { display: true, text: spec.x } },
        },
      },
    };
  }
  // ─── No series: one dataset
  else {
    chartConfig = {
      type: spec.type === 'horizontalBar' ? 'bar' : spec.type,
      data: {
        labels: data.map(r => {
          const val = r[spec.x];
          const s = String(val);
          return s.length > 40 ? s.slice(0, 37) + '…' : s;
        }),
        datasets: [{
          label: spec.y || 'value',
          data: data.map(r => +r[spec.y]),
          backgroundColor: spec.type === 'line' ? palette[0] + '22' : palette[0],
          borderColor: palette[0],
          tension: 0.3,
          fill: spec.type === 'line' ? false : undefined,
        }],
      },
      options: {
        responsive: true,
        maintainAspectRatio: false,
        indexAxis: spec.type === 'horizontalBar' ? 'y' : 'x',
        plugins: { legend: { display: false } },
        scales: {
          y: { reverse: !!spec.y_reverse, title: { display: true, text: spec.y } },
          x: { title: { display: true, text: spec.x } },
        },
      },
    };
  }

  // Clean up old chart on same canvas (shouldn't happen but safety)
  if (aiCharts[canvasId]) aiCharts[canvasId].destroy();
  aiCharts[canvasId] = new Chart(canvas, chartConfig);
}

// ---------------------------------------------------------
// Ask flow
// ---------------------------------------------------------
async function askAI(question) {
  const q = question.trim();
  if (!q) return;

  renderUserMessage(q);
  setAILoading(true);

  // Hide welcome message on first ask
  $('.ai-welcome').fadeOut(200, function () { $(this).remove(); });

  // Show typing indicator
  $('#ai-thread').append(`
    <div class="msg msg-assistant ai-typing-indicator">
      <div class="msg-bubble">
        <div class="typing-dots"><span></span><span></span><span></span></div>
      </div>
    </div>
  `);
  scrollThread();

  try {
    const response = await aiFetch('ai_ask', {
      question: q,
      conversation,
    });

    // Update in-memory conversation
    conversation.push({ role: 'user', content: q });
    conversation.push({
      role: 'assistant',
      content: JSON.stringify({
        sql: response.sql,
        answer: response.answer,
        response_type: response.response_type,
        row_count: response.row_count,
      }),
    });
    // Keep only last 6 (3 turns)
    while (conversation.length > 6) conversation.shift();

    updateTurnCount();
    renderAssistantMessage(response);

    // Refresh history sidebar
    loadHistory();
  } catch (err) {
    renderError(err.message);
  } finally {
    // Remove typing indicator
    $('.ai-typing-indicator').remove();
    setAILoading(false);
  }
}

// ---------------------------------------------------------
// History sidebar
// ---------------------------------------------------------
async function loadHistory() {
  try {
    const history = await aiFetch('ai_history');
    const $list = $('#ai-history').empty();
    if (!history.length) {
      $list.html('<div class="text-muted small">No queries yet.</div>');
      return;
    }
    history.slice(0, 8).forEach(h => {
      const q = h.question.length > 60 ? h.question.slice(0, 57) + '…' : h.question;
      $list.append(`
        <div class="history-item" data-q="${escapeHtml(h.question)}" title="${escapeHtml(h.question)}">
          <div class="history-q">${escapeHtml(q)}</div>
          <div class="history-meta"> ${h.hit_count}× · ${new Date(h.created_at).toLocaleDateString()}</div>
        </div>
      `);
    });
  } catch (e) {
    console.warn('Could not load history:', e);
  }
}

// ---------------------------------------------------------
// PDF export from an assistant message
// ---------------------------------------------------------
function exportAIMessageToPdf(msgId) {
  const $msg = $(`.msg[data-msg-id="${msgId}"]`);
  const response = $msg.data('response');
  if (!response) return;

  // Grab chart-as-image (base64 PNG) if there's a chart rendered
  const canvasId = `ai-chart-${msgId}`;
  const chart = aiCharts[canvasId];
  const chartImage = chart ? chart.toBase64Image() : '';

  const payload = {
    question:    response.question || '',
    sql:         response.sql || '',
    explanation: response.answer || '',  // server-side aiReportHtml uses 'explanation' field
    answer:      response.answer || '',
    model:       response.model || '',
    data:        response.data || [],
    chart_image: chartImage,
  };

  fetch(`${AI_API}?action=ai_export_pdf`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify(payload),
  })
    .then(r => r.text())
    .then(html => {
      const blob = new Blob([html], { type: 'text/html' });
      const url = URL.createObjectURL(blob);
      window.open(url, '_blank');
    });
}

// ---------------------------------------------------------
// Event bindings
// ---------------------------------------------------------
$('#ai-send').on('click', () => {
  askAI($('#ai-question').val());
  $('#ai-question').val('');
});

// Enter to send, Shift+Enter for newline
$('#ai-question').on('keydown', e => {
  if (e.key === 'Enter' && !e.shiftKey) {
    e.preventDefault();
    $('#ai-send').click();
  }
});

// Suggestion chips
$(document).on('click', '.suggestion-chip', function () {
  const q = $(this).data('q');
  askAI(q);
});

// History items
$(document).on('click', '.history-item', function () {
  const q = $(this).data('q');
  $('#ai-question').val(q).focus();
});

// Clear conversation
$('#ai-clear').on('click', () => {
  conversation.length = 0;
  Object.values(aiCharts).forEach(c => c.destroy());
  Object.keys(aiCharts).forEach(k => delete aiCharts[k]);
  $('#ai-thread').empty().append(`
    <div class="ai-welcome">
      <div class="ai-welcome-icon"></div>
      <p>Conversation cleared. Ask a fresh question below.</p>
    </div>
  `);
  updateTurnCount();
});

// Export PDF
$(document).on('click', '.ai-export-pdf', function () {
  const id = $(this).data('msg-id');
  exportAIMessageToPdf(id);
});

// Copy SQL
$(document).on('click', '.ai-copy-sql', function () {
  const id = $(this).data('msg-id');
  const $msg = $(`.msg[data-msg-id="${id}"]`);
  const response = $msg.data('response');
  if (!response) return;

  navigator.clipboard.writeText(response.sql).then(() => {
    const $btn = $(this);
    const orig = $btn.html();
    $btn.html('✓ Copied').addClass('btn-success').removeClass('btn-outline-secondary');
    setTimeout(() => $btn.html(orig).removeClass('btn-success').addClass('btn-outline-secondary'), 1500);
  });
});

// Rating (thumbs up/down)
$(document).on('click', '.ai-rate-good, .ai-rate-bad', async function () {
  const id = $(this).data('msg-id');
  const $msg = $(`.msg[data-msg-id="${id}"]`);
  const response = $msg.data('response');
  if (!response || !response.cache_key) return;

  const rating = $(this).hasClass('ai-rate-good') ? 'good' : 'bad';
  const $btn = $(this);

  try {
    await aiFetch('ai_rate', { cache_key: response.cache_key, rating });
    // Visual feedback
    $msg.find('.ai-rate-good, .ai-rate-bad').prop('disabled', true).addClass('opacity-50');
    $btn.removeClass('opacity-50').addClass(rating === 'good' ? 'btn-success' : 'btn-danger')
        .removeClass('btn-outline-success btn-outline-danger');
  } catch (e) {
    console.warn('Rating failed:', e);
  }
});

// Clear cache button
$(document).on('click', '#ai-clear-cache', async function () {
  if (!confirm('Clear all cached AI responses? Fresh queries will be generated on next ask.')) return;
  try {
    const res = await fetch('clear_cache.php');
    if (res.ok) {
      $(this).text(' Cleared!').addClass('btn-success').removeClass('btn-outline-warning');
      setTimeout(() => $(this).text(' Clear Cache').removeClass('btn-success').addClass('btn-outline-warning'), 2000);
    }
  } catch (e) {
    console.warn('Cache clear failed:', e);
  }
});

// Boot
$(() => {
  loadHistory();
  updateTurnCount();
});
