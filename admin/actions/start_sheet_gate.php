<?php
require_once dirname(__DIR__) . '/includes/auth.php';
checkAdminAuth();

if (!isSuperAdmin()) {
    http_response_code(403);
    exit;
}

if (!isset($_SESSION['start_sheet_allowed'])) {
    $_SESSION['start_sheet_allowed'] = [];
}

$discipline = $_GET['discipline'] ?? '';
if ($discipline) {
    $_SESSION['start_sheet_allowed'][$discipline] = true;
}

header('Content-Type: application/json');
echo json_encode(['success' => true, 'discipline' => $discipline]);
