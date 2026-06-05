<?php
declare(strict_types=1);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>AI Chat - JOSAA Analytics</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/select2-bootstrap-5-theme@1.3.0/dist/select2-bootstrap-5-theme.min.css" rel="stylesheet">
<link href="assets/css/custom.css" rel="stylesheet">
<style>
/* Custom styles for AI chat layout if needed */
.ai-section {
    height: calc(100vh - 56px); /* subtract height of the navbar */
    display: flex;
    flex-direction: column;
}
.ai-chat-history {
    flex: 1;
    overflow-y: auto;
}
</style>
</head>
<body>

<?php include 'navbar.php'; ?>

<!-- ============ AI CHAT SECTION ============ -->
<section id="ai-section" class="ai-section mt-4">
  <div class="container-fluid py-4">
    <div class="row">
      <div class="col-lg-9">
        <div class="ai-panel">
          <div class="ai-header">
            <h4 class="m-0"> Ask Anything About JOSAA Data</h4>
            <p class="text-muted m-0 small">Natural language → validated SQL → auto-generated chart</p>
          </div>

          <!-- Suggested starter questions -->
          <div class="ai-suggestions" id="ai-suggestions">
            <button class="suggestion-chip" data-q="Show me the closing rank trend for CSE at IIT Bombay over the years">
               CSE trend at IIT Bombay
            </button>
            <button class="suggestion-chip" data-q="Which IIT has the most seats for rank under 500 in 2022?">
               Top IITs for rank &lt; 500 (2022)
            </button>
            <button class="suggestion-chip" data-q="Compare closing ranks between female-only and gender-neutral seats in CSE">
               Gender comparison in CSE
            </button>
            <button class="suggestion-chip" data-q="What are the top 10 branches with lowest closing ranks?">
               Toughest branches
            </button>
            <button class="suggestion-chip" data-q="Average closing rank for Mechanical Engineering across all IITs by year">
               Mechanical by year
            </button>
          </div>

          <!-- Conversation thread -->
          <div class="ai-thread" id="ai-thread">
            <div class="ai-welcome">
              <div class="ai-welcome-icon"></div>
              <p>Ask a question in plain English. I'll write the SQL, run it against the database, and pick the best chart type for the answer.</p>
              <p class="small text-muted">Try one of the suggestions above, or type your own below.</p>
            </div>
          </div>

          <!-- Input -->
          <div class="ai-input-bar">
            <textarea id="ai-question" class="form-control" rows="2"
              placeholder="e.g. How has CSE closing rank changed at IIT Delhi from 2016 to 2022?"></textarea>
            <div class="ai-input-actions">
              <button id="ai-send" class="btn btn-primary">
                <span class="btn-text">Ask</span>
                <span class="spinner-border spinner-border-sm d-none" role="status"></span>
              </button>
              <button id="ai-clear" class="btn btn-outline-secondary" title="Clear conversation"></button>
            </div>
          </div>
        </div>
      </div>

      <!-- Side panel: history + conversation status -->
      <div class="col-lg-3">
        <div class="ai-side">
          <h6> Conversation</h6>
          <div id="ai-turn-count" class="text-muted small mb-2">No messages yet</div>
          <div id="ai-context-status" class="badge bg-secondary">Fresh session</div>

          <hr>

          <h6 class="mt-3"> Recent Queries</h6>
          <div id="ai-history" class="ai-history-list">
            <div class="text-muted small">Loading history…</div>
          </div>

          <hr>
          <button id="ai-clear-cache" class="btn btn-sm btn-outline-warning w-100"> Clear Cache</button>
        </div>
      </div>
    </div>
  </div>
</section>

<!-- <footer class="text-center text-muted my-4 small">
  Built with PHP 8 · MySQL · Chart.js · Bootstrap 5 · Groq LLM
</footer> -->

<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
<script src="assets/js/ai_chat.js"></script>

</body>
</html>