<?php 
require_once 'navbar.php'; 
require_once '../config/Database.php';

$pdo = Database::connect();
$iits = $pdo->query("SELECT iit_id, iit_name FROM dim_iit ORDER BY iit_name")->fetchAll(PDO::FETCH_ASSOC);

// Fetch branches along with the IIT they belong to
$branchesResult = $pdo->query("
    SELECT DISTINCT db.branch_id, db.branch_name, fa.iit_id 
    FROM dim_branch db 
    JOIN fact_allotment fa ON fa.branch_id = db.branch_id 
    ORDER BY db.branch_name
")->fetchAll(PDO::FETCH_ASSOC);

$branchesByIit = [];
foreach ($branchesResult as $row) {
    $branchesByIit[$row['iit_id']][] = [
        'id' => $row['branch_id'],
        'name' => $row['branch_name']
    ];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Preference Check | JOSAA Analytics</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="assets/css/custom.css" rel="stylesheet">
<style>
.preference-results {
  max-height: 70vh;
  overflow-y: auto;
  border-radius: 8px;
  border: 1px solid var(--border);
  background: white;
}
.preference-results .list-group-item {
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
          <h4 class="mb-3 text-dark fw-bold">Preference Check</h4>
          <p class="text-muted text-sm mb-4">Select your target IIT and branch, enter your category and rank, and we'll check your chances.</p>
          <form id="preference-form">
            <div class="mb-3">
              <label class="form-label text-sm fw-medium">Category</label>
              <select name="seat_type" class="form-select" required>
                <option value="OPEN">OPEN</option>
                <option value="OBC-NCL">OBC-NCL</option>
                <option value="SC">SC</option>
                <option value="ST">ST</option>
                <option value="EWS">EWS</option>
              </select>
            </div>
            <div class="mb-3">
              <label class="form-label text-sm fw-medium">Gender</label>
              <select name="gender" class="form-select" required>
                <option value="Gender-Neutral">Gender-Neutral</option>
                <option value="Female-only (including Supernumerary)">Female-only (including Supernumerary)</option>
              </select>
            </div>
            <div class="mb-3">
              <label class="form-label text-sm fw-medium">Your Rank</label>
              <input type="number" name="rank" class="form-control" placeholder="e.g. 2500" required>
            </div>
            <div class="mb-3">
              <label class="form-label text-sm fw-medium">Target IIT</label>
              <select name="iit_id" class="form-select" id="iit-select" required>
                  <option value="">Select IIT</option>
                  <?php foreach ($iits as $iit): ?>
                      <option value="<?= htmlspecialchars((string)$iit['iit_id']) ?>"><?= htmlspecialchars((string)$iit['iit_name']) ?></option>
                  <?php endforeach; ?>
              </select>
            </div>
            <div class="mb-4">
              <label class="form-label text-sm fw-medium">Target Branch</label>
              <select name="branch_id" class="form-select" id="branch-select" required>
                  <option value="">Select an IIT first</option>
              </select>
            </div>
            <button type="submit" class="btn btn-primary w-100 py-2">Check Preference Analysis ➔</button>
          </form>
        </div>
      </div>
    </div>
    
    <div class="col-lg-8">
      <div id="preference-results-container" style="display:none;">
          <h4 class="mb-3 fw-bold">Analysis Results</h4>
          <div id="preference-results-content"></div>
      </div>
    </div>
  </div>
</main>
<script>
    const branchesByIit = <?= json_encode($branchesByIit) ?>;
    
    document.getElementById('iit-select').addEventListener('change', function() {
        const branchSelect = document.getElementById('branch-select');
        const selectedIit = this.value;
        
        branchSelect.innerHTML = '<option value="">Select Branch</option>';
        
        if (selectedIit && branchesByIit[selectedIit]) {
            branchesByIit[selectedIit].forEach(branch => {
                const opt = document.createElement('option');
                opt.value = branch.id;
                opt.textContent = branch.name;
                branchSelect.appendChild(opt);
            });
        } else {
            branchSelect.innerHTML = '<option value="">Select an IIT first</option>';
        }
    });

    document.getElementById('preference-form').addEventListener('submit', async (e) => {
        e.preventDefault();
        const fd = new FormData(e.target);
        const payload = Object.fromEntries(fd.entries());
        
        const resContainer = document.getElementById('preference-results-container');
        const resContent = document.getElementById('preference-results-content');
        
        resContainer.style.display = 'block';
        resContent.innerHTML = '<div class="text-center py-5"><div class="spinner-border text-primary" role="status"></div></div>';
        
        try {
            const res = await fetch('api.php?action=predict_for_preference', { 
                method: 'POST', 
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(payload)
            });
            const data = await res.json();
            
            if(data.status !== 'ok') throw new Error(data.message || 'Unknown processing error');
            
            const ans = data.data;
            if (!ans.found) throw new Error(ans.message || 'No data found for this exact combination.');
            
            let html = `
                <div class="card mb-4 border-0 shadow-sm">
                    <div class="card-body text-center p-4">
            `;
            
            const c = ans.primary.chance;
            if (c === 'Very High' || c === 'High') {
                html += `<div class="display-1 mb-3"></div><h4 class="text-success fw-bold">${c} Chance</h4>`;
            } else if (c === 'Moderate') {
                html += `<div class="display-1 mb-3"></div><h4 class="text-warning fw-bold">Borderline / Moderate</h4>`;
            } else {
                html += `<div class="display-1 mb-3"></div><h4 class="text-danger fw-bold">${c} Chance</h4>`;
            }
            
            html += `<p class="mb-0 mt-3 text-muted"><strong>Reasoning:</strong> ${ans.primary.reasoning}</p>`;
            
            if (ans.yearly_trend && ans.yearly_trend.length > 0) {
                html += `<div class="mt-3 p-3 bg-light rounded text-start">
                            <strong>Historical Closing Ranks:</strong><br>
                            ${ans.yearly_trend.map(y => `<span>${y.year}: ${y.closing}</span>`).join(' | ')}
                         </div>`;
            }
            
            html += `</div></div>`;
            
            if(ans.same_branch_other_iits && ans.same_branch_other_iits.length > 0) {
                 html += `<h5 class="fw-bold mb-3">Same Branch At Other IITs Consider</h5>
                          <div class="preference-results list-group shadow-sm mb-4">`;
                 
                 ans.same_branch_other_iits.forEach(alt => {
                     html += `
                        <div class="list-group-item p-3">
                            <div class="d-flex justify-content-between align-items-start">
                                <div>
                                    <h6 class="mb-1 text-primary fw-bold">${alt.iit_name}</h6>
                                    <div class="text-muted small">${alt.branch_name}</div>
                                </div>
                                <div class="text-end">
                                    <span class="badge bg-${alt.chance === 'High' || alt.chance === 'Very High' ? 'success' : (alt.chance === 'Moderate' ? 'warning' : 'secondary')} text-white mb-1">${alt.chance}</span>
                                    <div class="small fw-semibold mt-1">Avg CR: ${Math.round(alt.avg_close)}</div>
                                </div>
                            </div>
                        </div>
                     `;
                 });
                 html += `</div>`;
            }
            
            if(ans.same_iit_other_branches && ans.same_iit_other_branches.length > 0) {
                 html += `<h5 class="fw-bold mb-3">Other Branches At Same IIT</h5>
                          <div class="preference-results list-group shadow-sm">`;
                 
                 ans.same_iit_other_branches.forEach(alt => {
                     html += `
                        <div class="list-group-item p-3">
                            <div class="d-flex justify-content-between align-items-start">
                                <div>
                                    <h6 class="mb-1 text-primary fw-bold">${alt.iit_name}</h6>
                                    <div class="text-muted small">${alt.branch_name}</div>
                                </div>
                                <div class="text-end">
                                    <span class="badge bg-${alt.chance === 'High' || alt.chance === 'Very High' ? 'success' : (alt.chance === 'Moderate' ? 'warning' : 'secondary')} text-white mb-1">${alt.chance}</span>
                                    <div class="small fw-semibold mt-1">Avg CR: ${Math.round(alt.avg_close)}</div>
                                </div>
                            </div>
                        </div>
                     `;
                 });
                 html += `</div>`;
            }
            
            resContent.innerHTML = html;
        } catch(e) {
            resContent.innerHTML = `<div class="alert alert-danger">${e.message}</div>`;
        }
    });
</script>
</body>
</html>