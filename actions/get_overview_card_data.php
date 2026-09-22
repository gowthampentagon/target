<?php
require_once dirname(__DIR__) . '/config/db.php';
if (session_status() === PHP_SESSION_NONE) session_start();

if (empty($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Please log in again.']);
    exit;
}

$card = $_GET['card'] ?? '';
if (!in_array($card, ['competitor', 'history', 'certificates'], true)) {
    echo json_encode(['success' => false, 'message' => 'Invalid card requested.']);
    exit;
}

try {
    $pdo = getDB();
    $userId = (int) $_SESSION['user_id'];

    $userStmt = $pdo->prepare("SELECT * FROM registrations WHERE id = ? LIMIT 1");
    $userStmt->execute([$userId]);
    $user = $userStmt->fetch();

    $approvedSessionStmt = $pdo->prepare(
        "SELECT id, session_id, approval_status, payment_status, approved_at, created_at
         FROM registration_sessions
         WHERE user_id = ? AND approval_status = 'approved'
         ORDER BY approved_at DESC, created_at DESC LIMIT 1"
    );
    $approvedSessionStmt->execute([$userId]);
    $approvedSession = $approvedSessionStmt->fetch();

    $approvedEventsStmt = $pdo->prepare(
        "SELECT event_reg_id, category, event_name, weapon_type, age_group, best_score, entry_fee, status, match_no,
                issf_number, mqs_score, competition_name, shooting_year, certificate_path, created_at
         FROM event_registrations
         WHERE user_id = ? AND status = 'approved'
         ORDER BY created_at DESC"
    );
    $approvedEventsStmt->execute([$userId]);
    $approvedEvents = $approvedEventsStmt->fetchAll();

    $approvedCompetitor = !empty($approvedSession);
    $issfHistory = array_values(array_filter($approvedEvents, static function ($event) {
        return ($event['category'] ?? '') === 'ISSF';
    }));

    $targetEvent = null;
    foreach ($approvedEvents as $event) {
        $eventName = strtolower((string) ($event['event_name'] ?? ''));
        $competitionName = strtolower((string) ($event['competition_name'] ?? ''));
        if (strpos($eventName, '51st tamil nadu state championship 2026') !== false || strpos($competitionName, '51st tamil nadu state championship 2026') !== false) {
            $targetEvent = $event;
            break;
        }
    }
    if ($targetEvent === null) {
        $targetEvent = $approvedEvents[0] ?? null;
    }

    $certificateLabel = 'Certificate Not Available Yet';
    $certificateUrl = null;
    $certificateEvent = null;
    if ($targetEvent && !empty($targetEvent['certificate_path'])) {
        $certificateLabel = 'Participation Certificate';
        $certificateUrl = $targetEvent['certificate_path'];
        $certificateEvent = $targetEvent;
    }

    ob_start();
    if ($card === 'competitor') {
        if ($approvedCompetitor) {
            echo '<div class="overview-detail-stack">';
            echo '<div class="overview-detail-row"><span class="overview-detail-label">Athlete Name</span><span class="overview-detail-value">' . htmlspecialchars($user['first_name'] . ' ' . $user['last_name']) . '</span></div>';
            echo '<div class="overview-detail-row"><span class="overview-detail-label">Registration ID</span><span class="overview-detail-value">' . htmlspecialchars($user['reg_id'] ?? 'TBD') . '</span></div>';
            echo '<div class="overview-detail-row"><span class="overview-detail-label">District</span><span class="overview-detail-value">' . htmlspecialchars($user['district'] ?? '—') . '</span></div>';
            echo '<div class="overview-detail-row"><span class="overview-detail-label">Club</span><span class="overview-detail-value">' . htmlspecialchars($user['club_name'] ?? '—') . '</span></div>';
            echo '<div class="overview-detail-row"><span class="overview-detail-label">Association</span><span class="overview-detail-value">' . htmlspecialchars($user['association'] ?? '—') . '</span></div>';
            echo '<div class="overview-detail-row"><span class="overview-detail-label">Approval</span><span class="overview-detail-value"><span class="status-badge approved" style="font-size:10px;display:inline-block;padding:2px 8px;line-height:1.2;">Approved</span></span></div>';
            echo '</div>';
        } else {
            echo '<div class="overview-card-modal-empty">Waiting for Super Admin Approval</div>';
        }
    } elseif ($card === 'history') {
        if (!empty($issfHistory)) {
            echo '<div style="overflow-x:auto;"><table style="width:100%;border-collapse:collapse;font-size:13px;"><thead><tr style="border-bottom:1px solid rgba(255,255,255,0.06);"><th class="ptcp-th" style="text-align:left;">ISSF Event Name</th><th class="ptcp-th" style="text-align:left;">MQS Score</th></tr></thead><tbody>';
            foreach ($issfHistory as $historyItem) {
                $mqsValue = (!empty($historyItem['mqs_score']) && ($historyItem['category'] ?? '') === 'ISSF') ? number_format((float) $historyItem['mqs_score'], 2) : '—';
                echo '<tr style="border-bottom:1px solid rgba(255,255,255,0.04);"><td class="ptcp-td">' . htmlspecialchars($historyItem['event_name'] ?? '—') . '</td><td class="ptcp-td">' . $mqsValue . '</td></tr>';
            }
            echo '</tbody></table></div>';
        } else {
            echo '<div class="overview-card-modal-empty">No ISSF national score history available yet.</div>';
        }
    } else {
        if ($certificateEvent && !empty($certificateUrl)) {
            echo '<div class="overview-detail-stack">';
            echo '<div class="overview-detail-row"><span class="overview-detail-label">Event</span><span class="overview-detail-value">' . htmlspecialchars($certificateEvent['event_name'] ?? '—') . '</span></div>';
            echo '<div class="overview-detail-row"><span class="overview-detail-label">Competition</span><span class="overview-detail-value">' . htmlspecialchars($certificateEvent['competition_name'] ?? '—') . '</span></div>';
            echo '<div class="overview-detail-row"><span class="overview-detail-label">Certificate Type</span><span class="overview-detail-value">Participation Certificate</span></div>';
            echo '<div class="overview-detail-row"><span class="overview-detail-label">Document</span><span class="overview-detail-value"><a href="' . htmlspecialchars($certificateUrl) . '" target="_blank" style="color:var(--info);text-decoration:underline;">View Certificate ↗</a></span></div>';
            echo '</div>';
        } else {
            echo '<div class="overview-card-modal-empty">Certificate Not Available Yet</div>';
        }
    }

    $content = ob_get_clean();
    echo json_encode(['success' => true, 'content' => $content]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'Unable to load details at the moment.']);
}
