<?php
/**
 * admin/includes/auth.php
 * Handles admin authentication checks and role authorization.
 */
require_once dirname(__DIR__, 2) . '/config/db.php';

if (session_status() === PHP_SESSION_NONE) session_start();

function checkAdminAuth() {
    // Determine relative path to login page (root index.php)
    $isAction = (strpos($_SERVER['PHP_SELF'], '/actions/') !== false);
    $loginUrl = $isAction ? '../../login.php' : '../login.php';

    // If it's an AJAX request (either POST, has application/json accept header, or request path contains /actions/)
    $isAjax = ((isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] === 'POST') || 
               (isset($_SERVER['HTTP_ACCEPT']) && strpos($_SERVER['HTTP_ACCEPT'], 'application/json') !== false) ||
               (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') ||
               $isAction);

    // If not logged in as admin
    if (empty($_SESSION['admin_id'])) {
        if ($isAjax) {
            header('Content-Type: application/json; charset=utf-8');
            http_response_code(401);
            exit(json_encode(['success' => false, 'message' => 'Session expired. Please log in again.']));
        }
        header("Location: $loginUrl");
        exit;
    }

    // Session timeout (30 min)
    if (!empty($_SESSION['admin_last_activity']) && (time() - $_SESSION['admin_last_activity']) > SESSION_TIMEOUT) {
        session_destroy();
        if ($isAjax) {
            header('Content-Type: application/json; charset=utf-8');
            http_response_code(401);
            exit(json_encode(['success' => false, 'message' => 'Session expired. Please log in again.']));
        }
        header("Location: {$loginUrl}?expired=1");
        exit;
    }
    $_SESSION['admin_last_activity'] = time(); // Slide session

    // Temporary passcode enforcement for standard admins
    if (isset($_SESSION['admin_role']) && !isSuperAdmin()) {
        if (basename($_SERVER['PHP_SELF']) !== 'passcode_verify.php') {
            if (empty($_SESSION['passcode_verified'])) {
                try {
                    $pdo = getDB();
                    // Check if they have an active (non-expired) passcode generated
                    $stmt = $pdo->prepare("SELECT COUNT(*) FROM admin_passcodes WHERE admin_id = ? AND expires_at > NOW()");
                    $stmt->execute([$_SESSION['admin_id']]);
                    $hasAccess = (int)$stmt->fetchColumn();

                    if ($hasAccess === 0) {
                        // Passcode access not configured or has expired
                        session_destroy();
                        if ($isAjax) {
                            header('Content-Type: application/json; charset=utf-8');
                            http_response_code(403);
                            exit(json_encode(['success' => false, 'message' => 'Passcode expired.']));
                        }
                        header("Location: {$loginUrl}?error=passcode_expired");
                        exit;
                    }

                    // Has valid access but hasn't entered the code in this session yet
                    if ($isAjax) {
                        header('Content-Type: application/json; charset=utf-8');
                        http_response_code(403);
                        exit(json_encode(['success' => false, 'message' => 'Passcode verification required.']));
                    }
                    $currentPage = basename($_SERVER['PHP_SELF']);
                    $verifyUrl = $isAction ? '../passcode_verify.php' : 'passcode_verify.php';
                    if ($currentPage !== 'index.php') {
                        $verifyUrl .= '?redirect=' . urlencode($currentPage);
                    }
                    header("Location: $verifyUrl");
                    exit;
                } catch (Exception $e) {
                    // Fail-safe default: allow if database errors block query
                }
            } else {
                // If verified, check dynamic database permission lively (without passcode regeneration)
                try {
                    $pdo = getDB();
                    // Self-migration: ensure used_writes column and passcode_writes table exist
                    try {
                        $pdo->exec("ALTER TABLE `admin_passcodes` ADD COLUMN `used_writes` INT NOT NULL DEFAULT 0 AFTER `allow_edit`");
                        $pdo->exec("CREATE TABLE IF NOT EXISTS `passcode_writes` (
                            `id` INT AUTO_INCREMENT PRIMARY KEY,
                            `passcode_id` INT NOT NULL,
                            `module` VARCHAR(100) NOT NULL,
                            `record_id` VARCHAR(100) NOT NULL,
                            `action` VARCHAR(20) NOT NULL,
                            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
                        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
                    } catch (Exception $migEx) {}

                    $passcodeId = $_SESSION['passcode_id'] ?? 0;
                    if ($passcodeId > 0) {
                        $stmt = $pdo->prepare("SELECT allowed_modules, allow_edit FROM admin_passcodes WHERE id = ? AND expires_at > NOW() LIMIT 1");
                        $stmt->execute([$passcodeId]);
                    } else {
                        $stmt = $pdo->prepare("SELECT allowed_modules, allow_edit FROM admin_passcodes WHERE admin_id = ? AND expires_at > NOW() LIMIT 1");
                        $stmt->execute([$_SESSION['admin_id']]);
                    }
                    $row = $stmt->fetch();
                    if ($row) {
                        $_SESSION['allowed_modules'] = explode(',', (string)$row['allowed_modules']);
                        $_SESSION['allow_edit'] = (int)$row['allow_edit'];
                    } else {
                        // Passcode expired, revoked or deleted
                        session_destroy();
                        if ($isAjax) {
                            header('Content-Type: application/json; charset=utf-8');
                            http_response_code(403);
                            exit(json_encode(['success' => false, 'message' => 'Your temporary access passcode has expired or been revoked.']));
                        }
                        header("Location: {$loginUrl}?error=passcode_expired");
                        exit;
                    }
                } catch (Exception $dbEx) {
                    // Fail-safe fallback to session if database is temporarily down
                }

                // Enforce permitted modules
                $currentPage = basename($_SERVER['PHP_SELF']);
                $protectedPages = [
                    'registrations.php',
                    'event_registrations.php',
                    'clubs.php',
                    'payment_sessions.php',
                    'lane_allocations.php',
                    'start_sheet.php',
                    'team_events.php',
                    'rank_list.php',
                    'manage.php',
                    'manage_updates.php',
                    'profile_change_requests.php',
                    'document_editor.php',
                    'events.php',
                    'age_categories.php',
                    'championship_manager.php',
                    'championship_archives.php'
                ];
                if (in_array($currentPage, $protectedPages, true)) {
                    $allowedBasenames = !empty($_SESSION['allowed_modules']) ? array_map('basename', $_SESSION['allowed_modules']) : [];
                    if (!in_array($currentPage, $allowedBasenames, true)) {
                        if ($isAjax) {
                            header('Content-Type: application/json; charset=utf-8');
                            http_response_code(403);
                            exit(json_encode(['success' => false, 'message' => 'Access Denied: You do not have permission for this module.']));
                        }
                        $indexUrl = $isAction ? '../index.php?error=no_permission' : 'index.php?error=no_permission';
                        header("Location: $indexUrl");
                        exit;
                    }
                }
            }
        }
    }

    // Block POST mutation requests for standard admins without edit permissions
    if (isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] === 'POST' && !isSuperAdmin()) {
        $currentPage = basename($_SERVER['PHP_SELF']);
        if ($currentPage !== 'passcode_verify.php') {
            
            $level = isset($_SESSION['allow_edit']) ? (int)$_SESSION['allow_edit'] : 0;
            
            // Extract record ID from POST payload
            $recordId = $_POST['id'] ?? $_POST['event_reg_id'] ?? $_POST['registration_id'] ?? $_POST['update_id'] ?? $_POST['club_id'] ?? $_POST['template_id'] ?? $_POST['request_id'] ?? $_POST['alloc_id'] ?? $_POST['news_id'] ?? $_POST['event_id'] ?? $_POST['gallery_id'] ?? $_POST['document_type'] ?? null;
            
            // Determine action type
            $action = trim($_POST['action'] ?? '');
            $isAdd = ($action === 'add' || $action === 'create' || $action === 'save_update' || strpos($action, 'add') !== false);
            $isDelete = ($action === 'delete' || strpos($action, 'delete') !== false);
            
            if (!canEdit()) {
                header('Content-Type: application/json; charset=utf-8');
                http_response_code(403);
                exit(json_encode(['success' => false, 'message' => 'Access Denied: You do not have edit/write permissions or your one-time permission has been used.']));
            }
            
            // Role specific checks:
            if ($currentPage !== 'save_score_sheet.php') {
                if ($level === 1) {
                    // Input Once: can only ADD. Cannot edit/update or delete.
                    if (!$isAdd) {
                        header('Content-Type: application/json; charset=utf-8');
                        http_response_code(403);
                        exit(json_encode(['success' => false, 'message' => 'Access Denied: Input Once permission only allows adding new records.']));
                    }
                }
                
                if ($level === 2) {
                    // Edit: can only EDIT/UPDATE. Cannot add/insert new records or delete.
                    if ($isAdd || $isDelete) {
                        header('Content-Type: application/json; charset=utf-8');
                        http_response_code(403);
                        exit(json_encode(['success' => false, 'message' => 'Access Denied: Edit permission only allows editing existing records.']));
                    }
                }
            }
            
            // Log/Increment write count
            try {
                $pdo = getDB();
                $passcodeId = $_SESSION['passcode_id'] ?? 0;
                
                if ($level === 1 || $level === 2) {
                    if ($passcodeId > 0) {
                        $pdo->prepare("UPDATE admin_passcodes SET used_writes = used_writes + 1 WHERE id = ?")->execute([$passcodeId]);
                    } else {
                        $pdo->prepare("UPDATE admin_passcodes SET used_writes = used_writes + 1 WHERE admin_id = ?")->execute([$_SESSION['admin_id'] ?? 0]);
                    }
                }
            } catch (Exception $e) {
                // Suppress
            }
        }
    }
}

function isSupremeAdmin(): bool {
    return isset($_SESSION['admin_role']) && $_SESSION['admin_role'] === 'supremeadmin';
}

function isSuperAdmin(): bool {
    return isset($_SESSION['admin_role']) && ($_SESSION['admin_role'] === 'superadmin' || $_SESSION['admin_role'] === 'supremeadmin');
}

function requireSuperAdmin() {
    if (!isSuperAdmin()) {
        header('HTTP/1.1 403 Forbidden');
        exit('Access Denied: Superadmin privileges required.');
    }
}

function requireSupremeAdmin() {
    if (!isSupremeAdmin()) {
        header('HTTP/1.1 403 Forbidden');
        exit('Access Denied: Supreme Admin privileges required.');
    }
}

function canEdit(?int $allocId = null): bool {
    if (isSuperAdmin()) {
        return true;
    }
    
    $level = isset($_SESSION['allow_edit']) ? (int)$_SESSION['allow_edit'] : 0;
    
    if ($level === 0) {
        return false;
    }
    
    if ($level === 3) {
        return true;
    }
    
    // If it is a score sheet check (allocId is provided), bypass the global write counter checks
    if ($allocId !== null) {
        try {
            $pdo = getDB();
            $stmt = $pdo->prepare("SELECT id, edit_count FROM score_sheets WHERE lane_alloc_id = ? LIMIT 1");
            $stmt->execute([$allocId]);
            $row = $stmt->fetch();
            
            if (!$row) {
                // If it doesn't exist yet, we can input it!
                return true;
            }
            
            if ($level === 1) {
                // Level 1: Input Once. No edits allowed on existing score sheets.
                return false;
            }
            
            if ($level === 2) {
                // Level 2: Edit. Allow exactly 1 edit after initial input (i.e., edit_count < 1).
                return ((int)$row['edit_count'] < 1);
            }
        } catch (Exception $e) {
            // Fallback
        }
        return false;
    }
    
    // Validate passcode-wide write operations globally for other pages/actions (Level 1 & 2)
    if ($level === 1 || $level === 2) {
        try {
            $pdo = getDB();
            $passcodeId = $_SESSION['passcode_id'] ?? 0;
            if ($passcodeId > 0) {
                $stmt = $pdo->prepare("SELECT used_writes FROM admin_passcodes WHERE id = ? LIMIT 1");
                $stmt->execute([$passcodeId]);
                $passRow = $stmt->fetch();
                if ($passRow && (int)$passRow['used_writes'] >= 1) {
                    return false;
                }
            }
        } catch (Exception $e) {
            return false;
        }
    }
    
    return true;
}

function canEditRecord(string $module, $recordId): bool {
    return canEdit();
}

function checkModuleAccess(string $module) {
    if (isSuperAdmin()) {
        return; // Superadmin has access to everything
    }

    $isAction = (strpos($_SERVER['PHP_SELF'] ?? '', '/actions/') !== false);

    // Standard admin permissions check
    if (empty($_SESSION['passcode_verified']) || empty($_SESSION['allowed_modules']) || !in_array($module, $_SESSION['allowed_modules'], true)) {
        if ($_SERVER['REQUEST_METHOD'] === 'POST' || (isset($_SERVER['HTTP_ACCEPT']) && strpos($_SERVER['HTTP_ACCEPT'], 'application/json') !== false)) {
            header('Content-Type: application/json; charset=utf-8');
            http_response_code(403);
            exit(json_encode(['success' => false, 'message' => 'Access Denied: You do not have permission for this module.']));
        } else {
            $indexUrl = $isAction ? '../index.php?error=no_permission' : 'index.php?error=no_permission';
            header("Location: $indexUrl");
            exit;
        }
    }
}
