<?php
// admin/actions/relay_schedule_action.php
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');

require_once dirname(__DIR__, 2) . '/config/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
checkAdminAuth();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit(json_encode(['success' => false, 'message' => 'Method not allowed.']));
}

$action = trim($_POST['action'] ?? '');

try {
    $pdo = getDB();

    switch ($action) {

        // ── Save active schedule for an event ──
        case 'save':
            $eventName = trim($_POST['event_name'] ?? '');
            $rowsJson  = trim($_POST['rows'] ?? '');
            if (empty($eventName)) {
                exit(json_encode(['success' => false, 'message' => 'Event name is required.']));
            }
            $rows = json_decode($rowsJson, true);
            if (!is_array($rows) || empty($rows)) {
                exit(json_encode(['success' => false, 'message' => 'At least one schedule row is required.']));
            }

            $pdo->beginTransaction();
            try {
                // Remove existing active schedule for this event
                $del = $pdo->prepare("DELETE FROM relay_schedules WHERE event_name = ? AND schedule_name = ''");
                $del->execute([$eventName]);

                // Insert new rows
                $ins = $pdo->prepare("INSERT INTO relay_schedules (event_name, schedule_name, scheduled_date, relay_no, reporting_time, start_time) VALUES (?, '', ?, ?, ?, ?)");
                foreach ($rows as $r) {
                    $date = trim($r['scheduled_date'] ?? '');
                    $relay = (int)($r['relay_no'] ?? 0);
                    $rep = trim($r['reporting_time'] ?? '');
                    $start = trim($r['start_time'] ?? '');
                    if (empty($date) || $relay <= 0 || empty($rep) || empty($start)) continue;
                    $ins->execute([$eventName, $date, $relay, $rep, $start]);
                }

                $pdo->commit();
                exit(json_encode(['success' => true, 'message' => 'Schedule saved successfully.']));
            } catch (Exception $e) {
                $pdo->rollBack();
                throw $e;
            }

        // ── Save current schedule as a named template ──
        case 'save_template':
            $eventName   = trim($_POST['event_name'] ?? '');
            $templateName = trim($_POST['template_name'] ?? '');
            if (empty($eventName) || empty($templateName)) {
                exit(json_encode(['success' => false, 'message' => 'Event name and template name are required.']));
            }

            // Copy current event schedule to template
            $stmt = $pdo->prepare("SELECT * FROM relay_schedules WHERE event_name = ? AND schedule_name = '' ORDER BY id ASC");
            $stmt->execute([$eventName]);
            $sourceRows = $stmt->fetchAll();

            if (empty($sourceRows)) {
                exit(json_encode(['success' => false, 'message' => 'No active schedule to save as template.']));
            }

            $pdo->beginTransaction();
            try {
                // Remove existing template with same name
                $del = $pdo->prepare("DELETE FROM relay_schedules WHERE schedule_name = ?");
                $del->execute([$templateName]);

                $ins = $pdo->prepare("INSERT INTO relay_schedules (event_name, schedule_name, scheduled_date, relay_no, reporting_time, start_time) VALUES (?, ?, ?, ?, ?, ?)");
                foreach ($sourceRows as $r) {
                    $ins->execute([$eventName, $templateName, $r['scheduled_date'], $r['relay_no'], $r['reporting_time'], $r['start_time']]);
                }

                $pdo->commit();
                exit(json_encode(['success' => true, 'message' => "Template '{$templateName}' saved successfully."]));
            } catch (Exception $e) {
                $pdo->rollBack();
                throw $e;
            }

        // ── Load active schedule for an event ──
        case 'load':
            $eventName = trim($_POST['event_name'] ?? '');
            if (empty($eventName)) {
                exit(json_encode(['success' => false, 'message' => 'Event name is required.']));
            }
            $stmt = $pdo->prepare("SELECT * FROM relay_schedules WHERE event_name = ? AND schedule_name = '' ORDER BY scheduled_date ASC, relay_no ASC");
            $stmt->execute([$eventName]);
            $rows = $stmt->fetchAll();
            exit(json_encode(['success' => true, 'rows' => $rows]));

        // ── Load a named template ──
        case 'load_template':
            $templateName = trim($_POST['template_name'] ?? '');
            if (empty($templateName)) {
                exit(json_encode(['success' => false, 'message' => 'Template name is required.']));
            }
            $stmt = $pdo->prepare("SELECT * FROM relay_schedules WHERE schedule_name = ? ORDER BY scheduled_date ASC, relay_no ASC");
            $stmt->execute([$templateName]);
            $rows = $stmt->fetchAll();
            exit(json_encode(['success' => true, 'rows' => $rows]));

        // ── List all named templates ──
        case 'list_templates':
            $stmt = $pdo->query("SELECT DISTINCT schedule_name FROM relay_schedules WHERE schedule_name != '' ORDER BY schedule_name ASC");
            $names = $stmt->fetchAll(PDO::FETCH_COLUMN);
            exit(json_encode(['success' => true, 'templates' => $names]));

        // ── Apply a named template to an event ──
        case 'apply_template':
            $eventName   = trim($_POST['event_name'] ?? '');
            $templateName = trim($_POST['template_name'] ?? '');
            if (empty($eventName) || empty($templateName)) {
                exit(json_encode(['success' => false, 'message' => 'Event name and template name are required.']));
            }

            // Fetch template rows
            $stmt = $pdo->prepare("SELECT * FROM relay_schedules WHERE schedule_name = ? ORDER BY id ASC");
            $stmt->execute([$templateName]);
            $templateRows = $stmt->fetchAll();

            if (empty($templateRows)) {
                exit(json_encode(['success' => false, 'message' => 'Template not found.']));
            }

            $pdo->beginTransaction();
            try {
                // Remove existing active schedule
                $del = $pdo->prepare("DELETE FROM relay_schedules WHERE event_name = ? AND schedule_name = ''");
                $del->execute([$eventName]);

                // Insert template rows as active schedule
                $ins = $pdo->prepare("INSERT INTO relay_schedules (event_name, schedule_name, scheduled_date, relay_no, reporting_time, start_time) VALUES (?, '', ?, ?, ?, ?)");
                foreach ($templateRows as $r) {
                    $ins->execute([$eventName, $r['scheduled_date'], $r['relay_no'], $r['reporting_time'], $r['start_time']]);
                }

                $pdo->commit();
                exit(json_encode(['success' => true, 'message' => "Template '{$templateName}' applied successfully."]));
            } catch (Exception $e) {
                $pdo->rollBack();
                throw $e;
            }

        // ── Delete a named template ──
        case 'delete_template':
            $templateName = trim($_POST['template_name'] ?? '');
            if (empty($templateName)) {
                exit(json_encode(['success' => false, 'message' => 'Template name is required.']));
            }
            $del = $pdo->prepare("DELETE FROM relay_schedules WHERE schedule_name = ?");
            $del->execute([$templateName]);
            exit(json_encode(['success' => true, 'message' => "Template '{$templateName}' deleted."]));

        // ── Clear active schedule for an event ──
        case 'clear':
            $eventName = trim($_POST['event_name'] ?? '');
            if (empty($eventName)) {
                exit(json_encode(['success' => false, 'message' => 'Event name is required.']));
            }
            $del = $pdo->prepare("DELETE FROM relay_schedules WHERE event_name = ? AND schedule_name = ''");
            $del->execute([$eventName]);
            exit(json_encode(['success' => true, 'message' => 'Schedule cleared.']));

        default:
            exit(json_encode(['success' => false, 'message' => 'Unknown action.']));
    }

} catch (Exception $e) {
    exit(json_encode(['success' => false, 'message' => 'Server error: ' . $e->getMessage()]));
}
