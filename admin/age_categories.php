<?php
/**
 * admin/age_categories.php
 * Age Categories & Event Eligibility Matrix Management.
 * Allows Supreme Admin to configure allowed event age categories for each shooter age group.
 */
require_once dirname(__DIR__) . '/config/db.php';
require_once 'includes/auth.php';

checkAdminAuth();
requireSuperAdmin(); // Enforce superadmin / supremeadmin access

$pdo = getDB();
$activeChampionship = getActiveChampionship($pdo);
$pageTitle = 'Age Categories & Eligibility Rules';
require_once 'includes/header.php';
?>

<!-- SweetAlert2 -->
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>



<!-- Championship Band -->
<div class="championship-band"><?= htmlspecialchars($activeChampionship['championship_name'] ?? '51st Tamil Nadu State Shooting Championship') ?> &bull; Saragarhi Shooting Academy &bull; Chennai</div>

<!-- Page Header & Action Controls -->
<div class="admin-page-header" style="margin-bottom: 24px; display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:14px;">
  <div>
    <div class="admin-page-title" style="display:flex; align-items:center; gap:10px;">
      <i class="bi bi-people-fill" style="color:var(--gold-400);"></i>
      <span>Age Categories &amp; Event Eligibility Rules</span>
    </div>
    <div style="font-size:12px; color:var(--text-secondary); margin-top:4px;">Configure which event age categories shooters of each age group can participate in</div>
  </div>

  <div style="display:flex; align-items:center; gap:12px; flex-wrap:wrap;">
    <!-- Reset Defaults Button -->
    <button type="button" class="btn" onclick="resetRules()" style="font-size:13px; font-family:'Rajdhani',sans-serif; text-transform:uppercase; letter-spacing:0.5px; padding:10px 22px; background:linear-gradient(135deg, rgba(231,76,60,0.2) 0%, rgba(192,57,43,0.3) 100%); border:1.5px solid #e74c3c; color:#ff9999; font-weight:700; border-radius:8px; cursor:pointer; display:inline-flex; align-items:center; gap:8px; box-shadow:0 4px 14px rgba(231,76,60,0.2); transition:all 0.25s ease;">
      <i class="bi bi-arrow-counterclockwise" style="font-size:15px;"></i> Reset Defaults
    </button>

    <!-- Save Rules Button -->
    <button type="button" class="btn" onclick="saveRules()" style="font-size:13px; font-family:'Rajdhani',sans-serif; text-transform:uppercase; letter-spacing:0.5px; padding:10px 24px; background:linear-gradient(135deg, #d4af37 0%, #aa882c 100%); border:none; color:#000; font-weight:800; border-radius:8px; cursor:pointer; display:inline-flex; align-items:center; gap:8px; box-shadow:0 4px 16px rgba(212,175,55,0.35); transition:all 0.25s ease;">
      <i class="bi bi-check-circle-fill" style="font-size:15px;"></i> Save Rules Configuration
    </button>
  </div>
</div>

<!-- Guidance Card -->
<div style="background: rgba(212,175,55,0.08); border: 1px solid rgba(212,175,55,0.25); border-radius: 12px; padding: 16px 20px; margin-bottom: 28px; display: flex; align-items: flex-start; gap: 14px;">
  <i class="bi bi-info-circle-fill" style="font-size:22px; color:var(--gold-400); margin-top:2px;"></i>
  <div style="font-size:13px; color:rgba(255,255,255,0.85); line-height:1.5;">
    <strong style="color:var(--gold-400); font-family:'Rajdhani',sans-serif; text-transform:uppercase; font-size:14px;">Eligibility Rules &amp; Min/Max Age Guide:</strong><br>
    Set the <strong>Min Age</strong> and <strong>Max Age</strong> range for each category. The category age condition will dynamically update in real time. Select checkboxes for <strong>Event Age Categories</strong> shooters in each age category are eligible to enter.
  </div>
</div>

<!-- Rules Table Matrix Card -->
<div class="table-wrap" style="background: rgba(18,18,26,0.85); border-radius: 12px; border: 1px solid rgba(255,255,255,0.08); overflow: hidden; margin-bottom: 32px;">
  <table class="admin-table" style="width:100%; border-collapse:collapse;">
    <thead>
      <tr>
        <th style="width:170px; padding:16px 20px; font-size:13px;">Shooter Category</th>
        <th style="width:230px; padding:16px 20px; font-size:13px; text-align:center;">Set Age (Min – Max)</th>
        <th style="padding:16px 20px; font-size:13px;">Allowed Event Age Categories (Check all that apply)</th>
        <th style="width:190px; padding:16px 20px; font-size:13px; text-align:right;">Active Summary</th>
      </tr>
    </thead>
    <tbody id="rulesTableBody">
      <tr>
        <td colspan="4" style="text-align:center; padding:40px; color:var(--text-muted);">
          <div class="spinner-border text-warning" role="status" style="width:2rem; height:2rem; margin-bottom:10px;"></div>
          <div>Loading Age Category Rules...</div>
        </td>
      </tr>
    </tbody>
  </table>
</div>

<!-- Bottom Floating Action Bar -->
<div style="display:flex; justify-content:flex-end; gap:12px; margin-top:20px; padding-top:20px; border-top:1px solid rgba(255,255,255,0.08);">
  <button type="button" class="btn" onclick="saveRules()" style="font-size:13px; font-family:'Rajdhani',sans-serif; text-transform:uppercase; letter-spacing:0.5px; padding:10px 24px; background:linear-gradient(135deg, #d4af37 0%, #aa882c 100%); border:none; color:#000; font-weight:800; border-radius:8px; cursor:pointer; display:inline-flex; align-items:center; gap:8px; box-shadow:0 4px 16px rgba(212,175,55,0.35); transition:all 0.25s ease;">
    <i class="bi bi-check-circle-fill" style="font-size:15px;"></i> Save Rules Configuration
  </button>
</div>

<script>
let ALL_CATEGORIES = [];
let CURRENT_RULES = {};

const DEFAULT_AGES = {
    'Sub Youth':     { min: 0,  max: 16 },
    'Youth':         { min: 17, max: 19 },
    'Junior':        { min: 20, max: 21 },
    'Senior':        { min: 22, max: 44 },
    'Master':        { min: 45, max: 59 },
    'Senior Master': { min: 60, max: 69 },
    'Super Master':  { min: 70, max: 120 }
};

document.addEventListener('DOMContentLoaded', function() {
    loadCategoryRules();
});

function loadCategoryRules() {
    fetch('actions/age_categories_action.php?action=get')
        .then(r => r.json())
        .then(res => {
            if (!res.success) {
                Swal.fire('Error', res.message || 'Failed to load rules.', 'error');
                return;
            }

            ALL_CATEGORIES = res.all_categories || [];
            CURRENT_RULES = res.rules || {};
            renderRulesMatrix();
        })
        .catch(err => {
            console.error('Fetch error:', err);
            Swal.fire('Error', 'Server communication failure.', 'error');
        });
}

function getConditionLabel(shooterCat, minVal, maxVal) {
    const minV = (minVal !== undefined && minVal !== null && minVal !== '') ? parseInt(minVal) : 0;
    const maxV = (maxVal !== undefined && maxVal !== null && maxVal !== '') ? parseInt(maxVal) : 120;
    
    if (minV <= 0 && maxV < 120) {
        return `Age ≤ ${maxV} Yrs`;
    }
    if (minV > 0 && maxV >= 100) {
        return `Age ≥ ${minV} Yrs`;
    }
    if (minV > 0 && maxV < 100) {
        return `Age ${minV} – ${maxV} Yrs`;
    }
    return `Age ${minV} – ${maxV} Yrs`;
}

function updateMinMaxAge(shooterCat, type, val) {
    const numVal = parseInt(val) || 0;
    const defaultDef = DEFAULT_AGES[shooterCat] || { min: 0, max: 120 };

    if (!CURRENT_RULES[shooterCat]) {
        CURRENT_RULES[shooterCat] = { allowed: [], min_age: defaultDef.min, max_age: defaultDef.max };
    } else if (Array.isArray(CURRENT_RULES[shooterCat])) {
        CURRENT_RULES[shooterCat] = { allowed: CURRENT_RULES[shooterCat], min_age: defaultDef.min, max_age: defaultDef.max };
    }

    if (type === 'min') {
        CURRENT_RULES[shooterCat].min_age = numVal;
    } else {
        CURRENT_RULES[shooterCat].max_age = numVal;
    }

    const curMin = CURRENT_RULES[shooterCat].min_age !== undefined ? CURRENT_RULES[shooterCat].min_age : defaultDef.min;
    const curMax = CURRENT_RULES[shooterCat].max_age !== undefined ? CURRENT_RULES[shooterCat].max_age : defaultDef.max;

    const condEl = document.getElementById(`cond_${sanitizeId(shooterCat)}`);
    if (condEl) {
        condEl.innerText = getConditionLabel(shooterCat, curMin, curMax);
    }
}

function renderRulesMatrix() {
    const tbody = document.getElementById('rulesTableBody');
    let html = '';

    ALL_CATEGORIES.forEach(shooterCat => {
        const catData = CURRENT_RULES[shooterCat] || {};
        const allowedArr = Array.isArray(catData) ? catData : (catData.allowed || []);
        const defaultDef = DEFAULT_AGES[shooterCat] || { min: 0, max: 120 };
        const minAgeVal = (catData && catData.min_age !== undefined && catData.min_age !== null) ? catData.min_age : defaultDef.min;
        const maxAgeVal = (catData && catData.max_age !== undefined && catData.max_age !== null) ? catData.max_age : defaultDef.max;
        
        let checkboxesHtml = '<div style="display:flex; flex-wrap:wrap; gap:12px; align-items:center;">';
        
        ALL_CATEGORIES.forEach(eventCat => {
            const isChecked = allowedArr.includes(eventCat);
            const inputId = `chk_${sanitizeId(shooterCat)}_${sanitizeId(eventCat)}`;

            const bgStyle = isChecked 
                ? 'background:linear-gradient(135deg, rgba(212,175,55,0.22) 0%, rgba(212,175,55,0.1) 100%); border:1.5px solid var(--gold-400); color:#fff; font-weight:700; box-shadow:0 0 10px rgba(212,175,55,0.2);' 
                : 'background:rgba(18,18,26,0.6); border:1.5px solid rgba(255,255,255,0.12); color:rgba(255,255,255,0.55); font-weight:600;';

            checkboxesHtml += `
            <label for="${inputId}" style="display:inline-flex; align-items:center; gap:8px; ${bgStyle} padding:7px 14px; border-radius:8px; cursor:pointer; font-size:12.5px; font-family:'Rajdhani',sans-serif; letter-spacing:0.3px; transition:all 0.2s ease;">
              <input type="checkbox" id="${inputId}" value="${escapeHtml(eventCat)}" ${isChecked ? 'checked' : ''} onchange="updateRuleState('${escapeJs(shooterCat)}', '${escapeJs(eventCat)}', this.checked)" style="width:16px; height:16px; accent-color:var(--gold-400); cursor:pointer;">
              <span>${escapeHtml(eventCat)}</span>
            </label>`;
        });
        checkboxesHtml += '</div>';

        // Summary Badges
        let summaryHtml = '<div style="display:flex; flex-wrap:wrap; justify-content:flex-end; gap:4px;">';
        if (allowedArr.length === 0) {
            summaryHtml += '<span style="font-size:11px; color:#e74c3c; font-style:italic;">None Allowed</span>';
        } else {
            allowedArr.forEach(ac => {
                summaryHtml += `<span style="font-size:10px; font-weight:700; font-family:'Rajdhani',sans-serif; background:rgba(212,175,55,0.15); color:var(--gold-400); border:1px solid rgba(212,175,55,0.3); padding:2px 8px; border-radius:10px;">${escapeHtml(ac)}</span>`;
            });
        }
        summaryHtml += '</div>';

        html += `
        <tr id="row_${sanitizeId(shooterCat)}">
          <td style="padding:18px 20px; font-weight:700; font-size:14px; font-family:'Rajdhani',sans-serif; color:var(--gold-400); vertical-align:middle;">
            <i class="bi bi-person-badge-fill" style="margin-right:6px;"></i> ${escapeHtml(shooterCat)}
          </td>
          <td style="padding:18px 16px; text-align:center; vertical-align:middle;">
            <div style="display:inline-flex; flex-direction:column; align-items:center; gap:6px;">
              <div style="display:flex; align-items:center; gap:8px;">
                <div style="display:flex; align-items:center; gap:4px;">
                  <span style="font-size:11px; color:var(--text-secondary); font-weight:700;">Min:</span>
                  <input type="number" id="min_${sanitizeId(shooterCat)}" value="${minAgeVal}" min="0" max="120" oninput="updateMinMaxAge('${escapeJs(shooterCat)}', 'min', this.value)" style="width:52px; background:rgba(0,0,0,0.6); border:1px solid rgba(212,175,55,0.4); color:var(--gold-400); font-weight:700; font-family:'Rajdhani',sans-serif; text-align:center; padding:5px 4px; border-radius:6px; font-size:13px; outline:none;">
                </div>
                <span style="color:var(--gold-400); font-weight:700;">-</span>
                <div style="display:flex; align-items:center; gap:4px;">
                  <span style="font-size:11px; color:var(--text-secondary); font-weight:700;">Max:</span>
                  <input type="number" id="max_${sanitizeId(shooterCat)}" value="${maxAgeVal}" min="0" max="120" oninput="updateMinMaxAge('${escapeJs(shooterCat)}', 'max', this.value)" style="width:52px; background:rgba(0,0,0,0.6); border:1px solid rgba(212,175,55,0.4); color:var(--gold-400); font-weight:700; font-family:'Rajdhani',sans-serif; text-align:center; padding:5px 4px; border-radius:6px; font-size:13px; outline:none;">
                </div>
              </div>
              <span id="cond_${sanitizeId(shooterCat)}" style="font-size:10.5px; font-weight:700; font-family:'Rajdhani',sans-serif; color:#3498db; background:rgba(52,152,219,0.12); border:1px solid rgba(52,152,219,0.3); padding:2px 8px; border-radius:10px; letter-spacing:0.3px;">
                ${getConditionLabel(shooterCat, minAgeVal, maxAgeVal)}
              </span>
            </div>
          </td>
          <td style="padding:18px 20px; vertical-align:middle;">
            ${checkboxesHtml}
          </td>
          <td style="padding:18px 20px; text-align:right; vertical-align:middle;">
            ${summaryHtml}
          </td>
        </tr>`;
    });

    tbody.innerHTML = html;
}

function updateRuleState(shooterCat, eventCat, isChecked) {
    const defaultDef = DEFAULT_AGES[shooterCat] || { min: 0, max: 120 };
    if (!CURRENT_RULES[shooterCat]) {
        CURRENT_RULES[shooterCat] = { allowed: [], min_age: defaultDef.min, max_age: defaultDef.max };
    } else if (Array.isArray(CURRENT_RULES[shooterCat])) {
        CURRENT_RULES[shooterCat] = { allowed: CURRENT_RULES[shooterCat], min_age: defaultDef.min, max_age: defaultDef.max };
    }

    if (isChecked) {
        if (!CURRENT_RULES[shooterCat].allowed.includes(eventCat)) {
            CURRENT_RULES[shooterCat].allowed.push(eventCat);
        }
    } else {
        CURRENT_RULES[shooterCat].allowed = CURRENT_RULES[shooterCat].allowed.filter(c => c !== eventCat);
    }

    renderRulesMatrix();
}

function saveRules() {
    Swal.fire({
        title: 'Saving Eligibility Rules...',
        text: 'Updating age category rules in database...',
        allowOutsideClick: false,
        didOpen: () => { Swal.showLoading(); }
    });

    fetch('actions/age_categories_action.php?action=save', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ rules: CURRENT_RULES })
    })
    .then(r => r.json())
    .then(res => {
        if (res.success) {
            Swal.fire({ icon: 'success', title: 'Rules Saved!', text: res.message, timer: 1500, showConfirmButton: false });
            loadCategoryRules();
        } else {
            Swal.fire({ icon: 'error', title: 'Error', text: res.message });
        }
    })
    .catch(err => {
        Swal.fire({ icon: 'error', title: 'Error', text: 'Failed to save rules.' });
    });
}

function resetRules() {
    Swal.fire({
        title: 'Reset Rules?',
        text: 'Reset all age category eligibility rules to standard ISSF/NRAI defaults?',
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#d33',
        cancelButtonColor: '#444',
        confirmButtonText: 'Yes, Reset Defaults'
    }).then((result) => {
        if (result.isConfirmed) {
            fetch('actions/age_categories_action.php?action=reset', {
                method: 'POST'
            })
            .then(r => r.json())
            .then(res => {
                if (res.success) {
                    Swal.fire({ icon: 'success', title: 'Reset Complete', text: res.message, timer: 1500, showConfirmButton: false });
                    loadCategoryRules();
                } else {
                    Swal.fire({ icon: 'error', title: 'Error', text: res.message });
                }
            });
        }
    });
}

function sanitizeId(str) {
    return String(str).replace(/[^a-zA-Z0-9]/g, '_');
}
function escapeHtml(str) {
    return String(str).replace(/&/g, "&amp;").replace(/</g, "&lt;").replace(/>/g, "&gt;").replace(/"/g, "&quot;");
}
function escapeJs(str) {
    return String(str).replace(/'/g, "\\'").replace(/"/g, '\\"');
}
</script>

<?php require_once 'includes/footer.php'; ?>
