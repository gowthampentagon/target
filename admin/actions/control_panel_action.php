<?php
/**
 * admin/actions/control_panel_action.php
 * Handles superadmin settings updates: field controls, custom fields, passcode access.
 */
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');

require_once dirname(dirname(__DIR__)) . '/config/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';

checkAdminAuth();
requireSuperAdmin(); // Enforce superadmin role

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit(json_encode(['success' => false, 'message' => 'Method not allowed.']));
}

$action = $_POST['action'] ?? '';

try {
    $pdo = getDB();

    // 1. Update existing field controls (enabled/mandatory status)
    if ($action === 'update_fields') {
        $fields = $_POST['fields'] ?? [];
        
        $pdo->beginTransaction();
        
        // Reset all fields to disabled/optional by default, then enable based on submission
        $formName = $_POST['form_name'] ?? 'registration';
        $pdo->prepare("UPDATE field_controls SET is_enabled = 0, is_mandatory = 0 WHERE form_name = ?")->execute([$formName]);
        
        $stmt = $pdo->prepare("
            UPDATE field_controls 
            SET is_enabled = ?, is_mandatory = ? 
            WHERE form_name = ? AND field_id = ?
        ");
        
        foreach ($fields as $fieldId => $opts) {
            $enabled = isset($opts['enabled']) ? 1 : 0;
            $mandatory = isset($opts['mandatory']) ? 1 : 0;
            $stmt->execute([$enabled, $mandatory, $formName, $fieldId]);
        }
        
        $pdo->commit();
        echo json_encode(['success' => true, 'message' => 'Fields configuration updated successfully.']);
        exit;
    }

    // 2. Add a new custom field
    if ($action === 'add_custom_field') {
        $formName = trim($_POST['form_name'] ?? '');
        $fieldLabel = trim($_POST['field_label'] ?? '');
        $isMandatory = isset($_POST['is_mandatory']) ? 1 : 0;

        if (empty($formName) || empty($fieldLabel)) {
            echo json_encode(['success' => false, 'message' => 'Field Label is required.']);
            exit;
        }

        // Generate unique clean field_id from label
        $fieldId = 'custom_' . strtolower(preg_replace('/[^a-zA-Z0-9]/', '_', $fieldLabel));
        // Truncate to 50 chars limit
        $fieldId = substr($fieldId, 0, 50);

        // Check if duplicate field ID exists in this form
        $chk = $pdo->prepare("SELECT COUNT(*) FROM field_controls WHERE form_name = ? AND field_id = ?");
        $chk->execute([$formName, $fieldId]);
        if ($chk->fetchColumn() > 0) {
            echo json_encode(['success' => false, 'message' => 'A field with a similar label already exists.']);
            exit;
        }

        $stmt = $pdo->prepare("
            INSERT INTO field_controls (form_name, field_id, field_label, is_enabled, is_mandatory, is_custom)
            VALUES (?, ?, ?, 1, ?, 1)
        ");
        $stmt->execute([$formName, $fieldId, $fieldLabel, $isMandatory]);
        
        echo json_encode(['success' => true, 'message' => 'Custom field added successfully.']);
        exit;
    }

    // 3. Delete a custom field
    if ($action === 'delete_custom_field') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id <= 0) {
            echo json_encode(['success' => false, 'message' => 'Invalid field ID.']);
            exit;
        }

        // Get details to clean up values
        $stmt = $pdo->prepare("SELECT form_name, field_id, is_custom FROM field_controls WHERE id = ?");
        $stmt->execute([$id]);
        $field = $stmt->fetch();

        if (!$field || (int)$field['is_custom'] !== 1) {
            echo json_encode(['success' => false, 'message' => 'Field not found or cannot be deleted.']);
            exit;
        }

        $pdo->beginTransaction();
        
        // Delete field control definition
        $pdo->prepare("DELETE FROM field_controls WHERE id = ?")->execute([$id]);
        
        // Delete associated custom field values
        $pdo->prepare("DELETE FROM custom_field_values WHERE field_id = ?")->execute([$field['field_id']]);
        
        $pdo->commit();
        
        echo json_encode(['success' => true, 'message' => 'Custom field deleted successfully.']);
        exit;
    }

    // 4. Generate passcode access for an admin
    if ($action === 'generate_passcode') {
        $adminId = (int)($_POST['admin_id'] ?? 0);
        $expiryDate = trim($_POST['expiry_date'] ?? '');
        $expiryTime = trim($_POST['expiry_time'] ?? '23:59');
        $modules = $_POST['modules'] ?? [];
        $allowEdit = (int)($_POST['allow_edit'] ?? 0);

        if ($adminId <= 0 || empty($expiryDate)) {
            echo json_encode(['success' => false, 'message' => 'Admin ID and Expiry Date are required.']);
            exit;
        }

        if (empty($modules) || !is_array($modules)) {
            echo json_encode(['success' => false, 'message' => 'Please select at least one module to grant access.']);
            exit;
        }

        // Check if admin exists
        $chkAdmin = $pdo->prepare("SELECT id, name, email, role FROM admins WHERE id = ? LIMIT 1");
        $chkAdmin->execute([$adminId]);
        $adm = $chkAdmin->fetch();
        if (!$adm) {
            echo json_encode(['success' => false, 'message' => 'Admin user not found.']);
            exit;
        }
        if ($adm['role'] === 'superadmin') {
            echo json_encode(['success' => false, 'message' => 'Superadmins do not need passcode access.']);
            exit;
        }

        $expiresAt = $expiryDate . ' ' . $expiryTime . ':00';
        
        // Check if selected expiry is in the past
        if (strtotime($expiresAt) <= time()) {
            echo json_encode(['success' => false, 'message' => 'Expiration date/time must be in the future.']);
            exit;
        }

        // Generate passcode (format: SSA-XXXXXX where X is numeric)
        $passcode = 'SSA-' . random_int(100000, 999999);

        // Delete any existing passcode for this admin
        $pdo->prepare("DELETE FROM admin_passcodes WHERE admin_id = ?")->execute([$adminId]);

        $allowedModules = implode(',', $modules);

        // Insert new passcode record
        $stmt = $pdo->prepare("
            INSERT INTO admin_passcodes (passcode, admin_id, expires_at, allowed_modules, allow_edit)
            VALUES (?, ?, ?, ?, ?)
        ");
        $stmt->execute([$passcode, $adminId, $expiresAt, $allowedModules, $allowEdit]);

        // Send email containing passcode to the admin
        try {
            require_once dirname(dirname(__DIR__)) . '/config/mail.php';
            
            $mapping = [
                'registrations.php' => 'Registrations',
                'event_registrations.php' => 'Event Reg',
                'clubs.php' => 'Clubs',
                'payment_sessions.php' => 'Payments',
                'lane_allocations.php' => 'Lanes',
                'start_sheet.php' => 'Start Lists',
                'team_events.php' => 'Teams',
                'rank_list.php' => 'Rank List',
                '../ssa-dashboard/manage.php' => 'Landing News/Events',
                'manage_updates.php' => 'Updates & Results',
                'profile_change_requests.php' => 'Support Requests',
                'document_editor.php' => 'Document Designer'
            ];
            
            $moduleLabels = [];
            foreach ($modules as $mod) {
                $moduleLabels[] = $mapping[$mod] ?? basename($mod);
            }
            $modulesListHtml = '<ul><li>' . implode('</li><li>', $moduleLabels) . '</li></ul>';
            
            $levelLabels = [
                0 => 'View Only',
                1 => 'Input Once',
                2 => 'Edit Once',
                3 => 'Dynamic Access'
            ];
            $levelText = $levelLabels[$allowEdit] ?? 'View Only';
            
            $subject = '🔑 Your Temporary Access Passcode – SSA Championship';
            $emailBody = "
                <div style='font-family: Arial, sans-serif; max-width: 600px; color: #333;'>
                    <h2 style='color: #d4af37;'>Temporary Access Granted</h2>
                    <p>Dear " . htmlspecialchars($adm['name']) . ",</p>
                    <p>You have been granted temporary administrative access to the <strong>SSA Championship Portal</strong>. Below are your credentials:</p>
                    <div style='background: #f7f7f7; border: 1px dashed #d4af37; padding: 16px; border-radius: 6px; font-size: 18px; text-align: center; margin: 20px 0;'>
                        <strong>Passcode:</strong> <span style='color: #d4af37; font-family: monospace; font-size: 22px; font-weight: bold;'>" . htmlspecialchars($passcode) . "</span>
                    </div>
                    <p><strong>Access Details:</strong></p>
                    <table style='width: 100%; border-collapse: collapse;'>
                        <tr>
                            <td style='padding: 8px; border-bottom: 1px solid #eee; font-weight: bold; width: 150px;'>Write Permission:</td>
                            <td style='padding: 8px; border-bottom: 1px solid #eee;'>" . htmlspecialchars($levelText) . "</td>
                        </tr>
                        <tr>
                            <td style='padding: 8px; border-bottom: 1px solid #eee; font-weight: bold;'>Expiration:</td>
                            <td style='padding: 8px; border-bottom: 1px solid #eee;'>" . date('d M Y, H:i', strtotime($expiresAt)) . "</td>
                        </tr>
                        <tr>
                            <td style='padding: 8px; border-bottom: 1px solid #eee; font-weight: bold; vertical-align: top;'>Modules Allowed:</td>
                            <td style='padding: 8px; border-bottom: 1px solid #eee;'>" . $modulesListHtml . "</td>
                        </tr>
                    </table>
                    <p style='margin-top: 24px;'>Please use this passcode on the login screen to verify and access your dashboard panels.</p>
                    <hr style='border: none; border-top: 1px solid #eee; margin: 24px 0;' />
                    <p style='font-size: 11px; color: #888;'>This is an automated security email. Please do not reply directly to this message.</p>
                </div>
            ";
            
            sendMail($adm['email'], $subject, $emailBody, $adm['name']);
        } catch (Exception $mailEx) {
            // Suppress mail errors to prevent blocking generation if SMTP fails
        }

        echo json_encode([
            'success' => true, 
            'message' => 'Temporary passcode generated and sent to admin successfully.',
            'passcode' => $passcode
        ]);
        exit;
    }

    // 5. Revoke passcode access
    if ($action === 'revoke_passcode') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id <= 0) {
            echo json_encode(['success' => false, 'message' => 'Invalid passcode ID.']);
            exit;
        }

        $pdo->prepare("DELETE FROM admin_passcodes WHERE id = ?")->execute([$id]);
        
        echo json_encode(['success' => true, 'message' => 'Passcode revoked successfully.']);
        exit;
    }

    // 6. Update passcode access permissions on the fly (lively permissions update)
    if ($action === 'update_passcode_permissions') {
        $id = (int)($_POST['id'] ?? 0);
        $modules = $_POST['modules'] ?? [];
        $allowEdit = (int)($_POST['allow_edit'] ?? 0);

        if ($id <= 0) {
            echo json_encode(['success' => false, 'message' => 'Invalid passcode ID.']);
            exit;
        }

        if (empty($modules) || !is_array($modules)) {
            echo json_encode(['success' => false, 'message' => 'Please select at least one module.']);
            exit;
        }

        $allowedModules = implode(',', $modules);

        $stmt = $pdo->prepare("
            UPDATE admin_passcodes 
            SET allowed_modules = ?, allow_edit = ?, used_writes = 0 
            WHERE id = ?
        ");
        $stmt->execute([$allowedModules, $allowEdit, $id]);

        echo json_encode(['success' => true, 'message' => 'Passcode permissions updated successfully.']);
        exit;
    }

    // 6. Update event registration control settings
    if ($action === 'update_reg_control') {
        $regStartActive = isset($_POST['reg_start_active']) ? 1 : 0;
        $regStartDate = !empty($_POST['reg_start_date']) ? $_POST['reg_start_date'] : null;
        
        $tripleEntryActive = isset($_POST['triple_entry_active']) ? 1 : 0;
        $tripleEntryDate = !empty($_POST['triple_entry_date']) ? $_POST['triple_entry_date'] : null;
        
        $regEndActive = isset($_POST['reg_end_active']) ? 1 : 0;
        $regEndDate = !empty($_POST['reg_end_date']) ? $_POST['reg_end_date'] : null;
        
        $activeChampionship = getActiveChampionship($pdo);
        $activeCid = (int)($activeChampionship['id'] ?? 1);

        // Update active championship registration dates
        try {
            $stmtChamp = $pdo->prepare("UPDATE championships SET registration_open = ?, registration_close = ? WHERE id = ?");
            $stmtChamp->execute([$regStartDate, $regEndDate, $activeCid]);
        } catch (Throwable $e) {}

        $stmt = $pdo->prepare("
            UPDATE event_info 
            SET reg_start_active = ?, reg_start_date = ?, 
                triple_entry_active = ?, triple_entry_date = ?, 
                reg_end_active = ?, reg_end_date = ?
            WHERE id = 1
        ");
        $stmt->execute([
            $regStartActive, $regStartDate,
            $tripleEntryActive, $tripleEntryDate,
            $regEndActive, $regEndDate
        ]);
        
        echo json_encode(['success' => true, 'message' => 'Event registration control settings updated successfully for active championship!']);
        exit;
    }

    echo json_encode(['success' => false, 'message' => 'Invalid action.']);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'Server error: ' . $e->getMessage()]);
}
