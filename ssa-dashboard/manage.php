<?php
/**
 * manage.php - SSA Landing Page Dashboard Content Manager
 * Authenticated page for page admins and portal admins.
 */
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once dirname(__DIR__) . '/admin/includes/auth.php';

$isPageAdmin = isset($_SESSION['page_admin']) && $_SESSION['page_admin'] === true;
$isPortalAdmin = !empty($_SESSION['admin_id']) && isset($_SESSION['admin_role']) && $_SESSION['admin_role'] === 'superadmin';
$isAllowedPasscode = !empty($_SESSION['admin_id']) && !empty($_SESSION['passcode_verified']) && 
                     (in_array('../ssa-dashboard/manage.php', $_SESSION['allowed_modules'] ?? [], true) || 
                      in_array('manage.php', $_SESSION['allowed_modules'] ?? [], true));

if (!$isPageAdmin && !$isPortalAdmin && !$isAllowedPasscode) {
    header('Location: ../login.php');
    exit;
}

$dataPath = __DIR__ . '/data.json';

// Helper function to handle image uploads
function handleUpload($fileField) {
    if (!isset($_FILES[$fileField]) || $_FILES[$fileField]['error'] !== UPLOAD_ERR_OK) {
        return null;
    }
    
    $uploadDir = dirname(__DIR__) . '/uploads/dashboard/';
    if (!is_dir($uploadDir)) {
        mkdir($uploadDir, 0777, true);
    }
    
    $extension = strtolower(pathinfo($_FILES[$fileField]['name'], PATHINFO_EXTENSION));
    $allowed = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
    if (!in_array($extension, $allowed)) {
        return null;
    }
    
    $fileName = time() . '_' . preg_replace('/[^a-zA-Z0-9._-]/', '_', basename($_FILES[$fileField]['name']));
    $targetPath = $uploadDir . $fileName;
    
    if (move_uploaded_file($_FILES[$fileField]['tmp_name'], $targetPath)) {
        return '../uploads/dashboard/' . $fileName;
    }
    
    return null;
}

// ── POST REQUEST HANDLER (AJAX CRUD) ──────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json; charset=utf-8');
    if (!canEdit()) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Access Denied: You do not have edit/write permissions.']);
        exit;
    }
    $action = $_POST['action'] ?? '';
    
    // Load existing data
    $data = [];
    if (file_exists($dataPath)) {
        $data = json_decode(file_get_contents($dataPath), true);
    }
    
    // Safety initializations
    if (!isset($data['news'])) $data['news'] = [];
    if (!isset($data['gallery'])) $data['gallery'] = [];
    if (!isset($data['events'])) $data['events'] = [];
    
    // --- NEWS ACTIONS ---
    if ($action === 'save_news') {
        $id = $_POST['id'] ?? '';
        $title = trim($_POST['title'] ?? '');
        $date = trim($_POST['date'] ?? '');
        $excerpt = trim($_POST['excerpt'] ?? '');
        $body = trim($_POST['body'] ?? '');
        
        if (empty($title) || empty($date)) {
            echo json_encode(['success' => false, 'message' => 'Title and Date are required.']);
            exit;
        }
        
        // Handle upload
        $imgPath = handleUpload('image');
        if (!$imgPath) {
            $imgPath = $_POST['existing_image'] ?? '';
        }
        
        if (empty($imgPath)) {
            $imgPath = '../images/gallery/ssa_gallery_1.jpg'; // fallback default
        }
        
        if (empty($id)) {
            // Create new news item
            $newId = count($data['news']) > 0 ? max(array_column($data['news'], 'id')) + 1 : 1;
            $data['news'][] = [
                'id' => $newId,
                'title' => $title,
                'date' => $date,
                'img' => $imgPath,
                'excerpt' => $excerpt,
                'body' => $body
            ];
        } else {
            // Edit existing news item
            $found = false;
            foreach ($data['news'] as &$item) {
                if ((string)$item['id'] === (string)$id) {
                    $item['title'] = $title;
                    $item['date'] = $date;
                    $item['img'] = $imgPath;
                    $item['excerpt'] = $excerpt;
                    $item['body'] = $body;
                    $found = true;
                    break;
                }
            }
            if (!$found) {
                echo json_encode(['success' => false, 'message' => 'News item not found.']);
                exit;
            }
        }
        
        file_put_contents($dataPath, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        echo json_encode(['success' => true, 'message' => 'News item saved successfully!']);
        exit;
    }
    
    if ($action === 'delete_news') {
        $id = $_POST['id'] ?? '';
        $data['news'] = array_values(array_filter($data['news'], fn($item) => (string)$item['id'] !== (string)$id));
        file_put_contents($dataPath, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        echo json_encode(['success' => true, 'message' => 'News item deleted successfully.']);
        exit;
    }
    
    // --- EVENT ACTIONS ---
    if ($action === 'save_event') {
        $id = $_POST['id'] ?? '';
        $title = trim($_POST['title'] ?? '');
        $date = trim($_POST['date'] ?? '');
        $venue = trim($_POST['venue'] ?? '');
        $desc = trim($_POST['desc'] ?? '');
        
        if (empty($title) || empty($date) || empty($venue)) {
            echo json_encode(['success' => false, 'message' => 'Title, Date, and Venue are required.']);
            exit;
        }
        
        if (empty($id)) {
            // Create new event
            $newId = count($data['events']) > 0 ? max(array_column($data['events'], 'id')) + 1 : 1;
            $data['events'][] = [
                'id' => $newId,
                'title' => $title,
                'date' => $date,
                'venue' => $venue,
                'desc' => $desc
            ];
        } else {
            // Edit existing event
            $found = false;
            foreach ($data['events'] as &$item) {
                if ((string)$item['id'] === (string)$id) {
                    $item['title'] = $title;
                    $item['date'] = $date;
                    $item['venue'] = $venue;
                    $item['desc'] = $desc;
                    $found = true;
                    break;
                }
            }
            if (!$found) {
                echo json_encode(['success' => false, 'message' => 'Event not found.']);
                exit;
            }
        }
        
        file_put_contents($dataPath, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        echo json_encode(['success' => true, 'message' => 'Event saved successfully!']);
        exit;
    }
    
    if ($action === 'delete_event') {
        $id = $_POST['id'] ?? '';
        $data['events'] = array_values(array_filter($data['events'], fn($item) => (string)$item['id'] !== (string)$id));
        file_put_contents($dataPath, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        echo json_encode(['success' => true, 'message' => 'Event deleted successfully.']);
        exit;
    }
    
    // --- GALLERY ACTIONS ---
    if ($action === 'save_gallery') {
        $index = $_POST['index'] ?? '';
        $title = trim($_POST['title'] ?? '');
        $desc = trim($_POST['desc'] ?? '');
        
        if (empty($title)) {
            echo json_encode(['success' => false, 'message' => 'Title is required.']);
            exit;
        }
        
        // Handle upload
        $imgPath = handleUpload('image');
        if (!$imgPath) {
            $imgPath = $_POST['existing_image'] ?? '';
        }
        
        if (empty($imgPath)) {
            echo json_encode(['success' => false, 'message' => 'Gallery image is required.']);
            exit;
        }
        
        $galleryItem = [
            'img' => $imgPath,
            'title' => $title,
            'desc' => $desc
        ];
        
        if ($index === '') {
            // Append new item
            $data['gallery'][] = $galleryItem;
        } else {
            // Edit existing
            $idx = (int)$index;
            if (isset($data['gallery'][$idx])) {
                $data['gallery'][$idx] = $galleryItem;
            } else {
                echo json_encode(['success' => false, 'message' => 'Gallery item not found.']);
                exit;
            }
        }
        
        file_put_contents($dataPath, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        echo json_encode(['success' => true, 'message' => 'Gallery photo saved successfully!']);
        exit;
    }
    
    if ($action === 'delete_gallery') {
        $index = (int)($_POST['index'] ?? -1);
        if (isset($data['gallery'][$index])) {
            unset($data['gallery'][$index]);
            $data['gallery'] = array_values($data['gallery']);
            file_put_contents($dataPath, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            echo json_encode(['success' => true, 'message' => 'Gallery photo deleted successfully.']);
            exit;
        } else {
            echo json_encode(['success' => false, 'message' => 'Gallery item not found.']);
            exit;
        }
    }
    
    echo json_encode(['success' => false, 'message' => 'Unsupported action request.']);
    exit;
}

// ── GET REQUEST - RENDER DASHBOARD ────────────────────────────
$data = [];
if (file_exists($dataPath)) {
    $data = json_decode(file_get_contents($dataPath), true);
}
$news = $data['news'] ?? [];
$events = $data['events'] ?? [];
$gallery = $data['gallery'] ?? [];
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <meta name="robots" content="noindex, nofollow">
  <title>Landing Page Content Manager | SSA</title>

  <!-- Google Fonts -->
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Cinzel:wght@500;700&family=Inter:wght@300;400;500;600;700&family=Rajdhani:wght@500;600;700&display=swap" rel="stylesheet">

  <!-- SweetAlert2 -->
  <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

  <!-- Premium Cinematic Admin CSS Stylesheet -->
  <style>
    :root {
      --gold-500:   #ADB5BD;
      --gold-400:   #E9ECEF;
      --gold-300:   #FFFFFF;
      --gold-200:   #F5DF9F;
      --gold-100:   #F8F9FA;
      --dark-950:   #06080b;
      --dark-900:   #0a0d14;
      --dark-800:   #101520;
      --dark-700:   #171e2e;
      --dark-600:   #20293d;
      --dark-500:   #2c3954;
      --text-primary:   #f3f1ec;
      --text-secondary: #aba79e;
      --text-muted:     #6e6a64;
      --error-color:    #e74c3c;
      --success-color:  #2ecc71;
      
      --grad-glass: linear-gradient(135deg, rgba(16, 21, 32, 0.9) 0%, rgba(10, 13, 20, 0.95) 100%);
      --grad-gold: linear-gradient(135deg, #ADB5BD 0%, #E9ECEF 50%, #ADB5BD 100%);
      --border-gold: 1px solid rgba(255, 255, 255, 0.2);
      --border-light: 1px solid rgba(255, 255, 255, 0.05);
      --shadow-premium: 0 15px 35px rgba(0,0,0,0.6), 0 0 15px rgba(255, 255, 255,0.05);
      
      --radius-sm: 6px;
      --radius-md: 10px;
      --radius-lg: 16px;
    }

    * {
      box-sizing: border-box;
      margin: 0;
      padding: 0;
    }

    body {
      font-family: 'Inter', sans-serif;
      background-color: var(--dark-950);
      color: var(--text-primary);
      min-height: 100vh;
      overflow-x: hidden;
      line-height: 1.5;
    }

    /* Background image with overlay matching landing page */
    .bg-cinematic {
      position: fixed;
      inset: 0;
      z-index: -2;
      background-image: url('../images/gallery/ssa_range_1.jpg');
      background-size: cover;
      background-position: center;
      filter: blur(15px) brightness(0.2);
      transform: scale(1.05);
    }
    .bg-overlay {
      position: fixed;
      inset: 0;
      z-index: -1;
      background: radial-gradient(circle at center, rgba(10, 13, 20, 0.7) 0%, var(--dark-950) 100%);
    }

    /* Container layout */
    .app-container {
      max-width: 1400px;
      margin: 0 auto;
      padding: 30px 20px;
    }

    /* Header styling */
    header {
      background: var(--grad-glass);
      border: var(--border-gold);
      border-radius: var(--radius-lg);
      padding: 20px 30px;
      margin-bottom: 30px;
      display: flex;
      justify-content: space-between;
      align-items: center;
      box-shadow: var(--shadow-premium);
    }

    .brand {
      display: flex;
      align-items: center;
      gap: 15px;
    }

    .brand-logo-svg {
      width: 32px;
      height: 32px;
      color: var(--gold-400);
    }

    .brand-title {
      font-family: 'Cinzel', serif;
      font-size: 1.5rem;
      font-weight: 700;
      color: var(--gold-400);
      letter-spacing: 2px;
    }

    .header-nav {
      display: flex;
      gap: 15px;
      align-items: center;
    }

    .btn {
      display: inline-flex;
      align-items: center;
      gap: 8px;
      font-family: 'Rajdhani', sans-serif;
      font-size: 1rem;
      font-weight: 700;
      text-transform: uppercase;
      letter-spacing: 1px;
      padding: 10px 20px;
      border-radius: var(--radius-sm);
      cursor: pointer;
      transition: all 0.2s ease;
      text-decoration: none;
    }

    .btn-gold {
      background: var(--grad-gold);
      color: var(--dark-950);
      border: none;
      box-shadow: 0 4px 15px rgba(255, 255, 255,0.3);
    }
    .btn-gold:hover {
      box-shadow: 0 4px 25px rgba(255, 255, 255,0.6);
      transform: translateY(-2px);
    }

    .btn-outline {
      background: transparent;
      color: var(--text-primary);
      border: 1px solid rgba(255,255,255,0.15);
    }
    .btn-outline:hover {
      background: rgba(255,255,255,0.05);
      border-color: var(--gold-400);
      color: var(--gold-300);
    }

    .btn-danger {
      background: rgba(231, 76, 60, 0.15);
      border: 1px solid var(--error-color);
      color: var(--error-color);
    }
    .btn-danger:hover {
      background: var(--error-color);
      color: #fff;
    }

    /* Main layout grid */
    .dashboard-layout {
      display: grid;
      grid-template-columns: 280px 1fr;
      gap: 30px;
    }

    /* Sidebar controls */
    .sidebar-panel {
      background: var(--grad-glass);
      border: var(--border-gold);
      border-radius: var(--radius-lg);
      padding: 25px;
      box-shadow: var(--shadow-premium);
      height: fit-content;
    }

    .sidebar-heading {
      font-family: 'Rajdhani', sans-serif;
      font-size: 1.1rem;
      font-weight: 700;
      color: var(--text-muted);
      text-transform: uppercase;
      letter-spacing: 1.5px;
      margin-bottom: 20px;
    }

    .tab-nav {
      display: flex;
      flex-direction: column;
      gap: 10px;
    }

    .tab-btn {
      background: transparent;
      border: var(--border-light);
      border-radius: var(--radius-sm);
      color: var(--text-secondary);
      font-family: 'Rajdhani', sans-serif;
      font-size: 1.1rem;
      font-weight: 600;
      text-align: left;
      padding: 12px 18px;
      cursor: pointer;
      display: flex;
      align-items: center;
      gap: 12px;
      transition: all 0.2s ease;
    }

    .tab-btn svg {
      width: 18px;
      height: 18px;
      transition: color 0.2s ease;
    }

    .tab-btn:hover {
      background: rgba(255,255,255,0.03);
      color: var(--text-primary);
      border-color: rgba(255, 255, 255,0.3);
    }

    .tab-btn.active {
      background: rgba(255, 255, 255, 0.1);
      color: var(--gold-400);
      border-color: var(--gold-500);
      box-shadow: inset 3px 0 0 var(--gold-500);
    }

    .tab-btn.active svg {
      color: var(--gold-400);
    }

    /* Content Area panel */
    .content-panel {
      background: var(--grad-glass);
      border: var(--border-gold);
      border-radius: var(--radius-lg);
      padding: 35px;
      box-shadow: var(--shadow-premium);
      min-height: 500px;
    }

    .section-title-wrap {
      display: flex;
      justify-content: space-between;
      align-items: center;
      border-bottom: 1px solid rgba(255,255,255,0.06);
      padding-bottom: 20px;
      margin-bottom: 35px;
    }

    .panel-title {
      font-family: 'Rajdhani', sans-serif;
      font-size: 1.75rem;
      font-weight: 700;
      color: var(--gold-400);
      text-transform: uppercase;
      letter-spacing: 1px;
    }

    .panel-desc {
      font-size: 0.9rem;
      color: var(--text-secondary);
      margin-top: 5px;
    }

    /* CRUD Grid display styles */
    .item-grid {
      display: grid;
      grid-template-columns: repeat(auto-fill, minmax(320px, 1fr));
      gap: 25px;
    }

    .crud-card {
      background: rgba(255,255,255,0.02);
      border: var(--border-light);
      border-radius: var(--radius-md);
      overflow: hidden;
      display: flex;
      flex-direction: column;
      box-shadow: 0 8px 20px rgba(0,0,0,0.3);
      transition: border-color 0.2s ease, transform 0.2s ease;
    }

    .crud-card:hover {
      border-color: rgba(255, 255, 255, 0.3);
      transform: translateY(-2px);
    }

    .card-thumbnail {
      height: 180px;
      background-color: var(--dark-900);
      position: relative;
      overflow: hidden;
    }

    .card-thumbnail img {
      width: 100%;
      height: 100%;
      object-fit: cover;
    }

    .card-badge {
      position: absolute;
      top: 12px;
      right: 12px;
      background: rgba(0,0,0,0.85);
      border: 1px solid rgba(255, 255, 255, 0.3);
      color: var(--gold-400);
      font-family: 'Rajdhani', sans-serif;
      font-weight: 600;
      font-size: 0.8rem;
      padding: 2px 8px;
      border-radius: var(--radius-sm);
    }

    .card-content {
      padding: 20px;
      flex-grow: 1;
      display: flex;
      flex-direction: column;
    }

    .card-title {
      font-family: 'Rajdhani', sans-serif;
      font-size: 1.2rem;
      font-weight: 700;
      color: var(--gold-300);
      margin-bottom: 8px;
      line-height: 1.3;
    }

    .card-meta {
      font-size: 0.8rem;
      color: var(--text-muted);
      margin-bottom: 12px;
      display: flex;
      align-items: center;
      gap: 5px;
    }

    .card-desc {
      font-size: 0.9rem;
      color: var(--text-secondary);
      line-height: 1.5;
      margin-bottom: 20px;
      flex-grow: 1;
      display: -webkit-box;
      -webkit-line-clamp: 3;
      -webkit-box-orient: vertical;
      overflow: hidden;
    }

    .card-actions {
      display: flex;
      gap: 10px;
      margin-top: auto;
      border-top: 1px solid rgba(255,255,255,0.05);
      padding-top: 15px;
    }

    .action-btn {
      flex: 1;
      display: inline-flex;
      justify-content: center;
      align-items: center;
      gap: 6px;
      font-family: 'Rajdhani', sans-serif;
      font-weight: 700;
      font-size: 0.85rem;
      text-transform: uppercase;
      letter-spacing: 0.5px;
      padding: 8px;
      border-radius: 4px;
      cursor: pointer;
      text-decoration: none;
      transition: all 0.2s ease;
      background: transparent;
    }

    .btn-edit {
      border: 1px solid rgba(255, 255, 255, 0.4);
      color: var(--gold-400);
    }
    .btn-edit:hover {
      background: rgba(255, 255, 255, 0.1);
      border-color: var(--gold-400);
    }

    .btn-delete {
      border: 1px solid rgba(231, 76, 60, 0.4);
      color: var(--error-color);
    }
    .btn-delete:hover {
      background: rgba(231, 76, 60, 0.1);
      border-color: var(--error-color);
    }

    /* Modal Form Overlay Styles */
    .editor-modal {
      position: fixed;
      inset: 0;
      z-index: 1000;
      display: none;
      align-items: center;
      justify-content: center;
      padding: 20px;
    }

    .editor-modal.show {
      display: flex;
    }

    .modal-overlay {
      position: absolute;
      inset: 0;
      background: rgba(5,6,8,0.85);
      backdrop-filter: blur(8px);
    }

    .modal-container {
      background: var(--grad-glass);
      border: var(--border-gold);
      border-radius: var(--radius-lg);
      width: 100%;
      max-width: 650px;
      max-height: 90vh;
      overflow-y: auto;
      position: relative;
      z-index: 10;
      box-shadow: var(--shadow-premium), 0 0 50px rgba(0,0,0,0.5);
      animation: modalFadeIn 0.3s cubic-bezier(0.16, 1, 0.3, 1);
    }

    @keyframes modalFadeIn {
      from { transform: scale(0.95); opacity: 0; }
      to { transform: scale(1); opacity: 1; }
    }

    .modal-header {
      padding: 20px 30px;
      border-bottom: 1px solid rgba(255,255,255,0.06);
      display: flex;
      justify-content: space-between;
      align-items: center;
    }

    .modal-title {
      font-family: 'Rajdhani', sans-serif;
      font-size: 1.4rem;
      font-weight: 700;
      color: var(--gold-400);
      text-transform: uppercase;
    }

    .modal-close-btn {
      background: transparent;
      border: none;
      color: var(--text-secondary);
      cursor: pointer;
      font-size: 1.5rem;
      line-height: 1;
      transition: color 0.2s ease;
    }
    .modal-close-btn:hover {
      color: var(--error-color);
    }

    .modal-body {
      padding: 30px;
    }

    /* Form Fields Styling */
    .form-group {
      margin-bottom: 22px;
    }

    .form-group label {
      display: block;
      font-family: 'Rajdhani', sans-serif;
      font-size: 0.95rem;
      font-weight: 700;
      color: var(--text-secondary);
      text-transform: uppercase;
      letter-spacing: 0.5px;
      margin-bottom: 8px;
    }

    .form-control {
      width: 100%;
      background: rgba(0,0,0,0.25);
      border: 1px solid rgba(255,255,255,0.1);
      border-radius: var(--radius-sm);
      color: var(--text-primary);
      padding: 12px 16px;
      font-family: 'Inter', sans-serif;
      font-size: 0.95rem;
      transition: border-color 0.2s ease, box-shadow 0.2s ease;
    }

    .form-control:focus {
      outline: none;
      border-color: var(--gold-500);
      box-shadow: 0 0 8px rgba(255, 255, 255,0.25);
    }

    textarea.form-control {
      resize: vertical;
      min-height: 100px;
    }

    /* Upload visual style */
    .file-input-wrapper {
      position: relative;
      margin-bottom: 10px;
    }

    .upload-preview {
      margin-top: 12px;
      width: 100%;
      height: 160px;
      border-radius: var(--radius-sm);
      border: 1px dashed rgba(255, 255, 255, 0.3);
      background-color: rgba(0,0,0,0.15);
      display: flex;
      align-items: center;
      justify-content: center;
      overflow: hidden;
    }

    .upload-preview img {
      width: 100%;
      height: 100%;
      object-fit: cover;
    }

    .upload-preview span {
      font-size: 0.85rem;
      color: var(--text-muted);
    }

    .modal-footer {
      padding: 0 30px 30px;
      display: flex;
      justify-content: flex-end;
      gap: 15px;
    }

    /* Tab Contents panels show/hide */
    .panel-section {
      display: none;
    }

    .panel-section.active {
      display: block;
    }

    /* Custom scrollbar matching root style */
    .modal-container::-webkit-scrollbar {
      width: 6px;
    }
    .modal-container::-webkit-scrollbar-track {
      background: var(--dark-900);
    }
    .modal-container::-webkit-scrollbar-thumb {
      background: var(--dark-600);
      border-radius: 3px;
    }
    .modal-container::-webkit-scrollbar-thumb:hover {
      background: var(--gold-500);
    }
    <?php if (!canEdit()): ?>
    /* If read-only admin: disable all inputs, selects, textareas and buttons globally */
    input, select, textarea, button, a.btn, .action-btn {
        pointer-events: none !important;
        opacity: 0.5 !important;
        cursor: not-allowed !important;
    }
    /* Exclude global navigation elements */
    header a, header button, header a.btn, .tab-btn {
        pointer-events: auto !important;
        opacity: 1 !important;
        cursor: pointer !important;
    }
    <?php endif; ?>
  </style>
</head>
<body>

  <!-- Cinematic Visual Layers -->
  <div class="bg-cinematic" aria-hidden="true"></div>
  <div class="bg-overlay" aria-hidden="true"></div>

  <div class="app-container">
    
    <!-- Header -->
    <header>
      <div class="brand">
        <svg class="brand-logo-svg" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
          <circle cx="12" cy="12" r="10" />
          <circle cx="12" cy="12" r="6" />
          <circle cx="12" cy="12" r="2" fill="currentColor"/>
          <line x1="12" y1="2" x2="12" y2="5"/>
          <line x1="12" y1="19" x2="12" y2="22"/>
          <line x1="2" y1="12" x2="5" y2="12"/>
          <line x1="19" y1="12" x2="22" y2="12"/>
        </svg>
        <span class="brand-title">Saragarhi Landing Manager</span>
      </div>
      
      <div class="header-nav">
        <a href="../admin/index.php" class="btn btn-outline">
          <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" style="vertical-align:-2px; margin-right:4px;"><path stroke-linecap="round" stroke-linejoin="round" d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6"/></svg>
          Back to Dashboard
        </a>

        <a href="../index.php" target="_blank" class="btn btn-outline">
          <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" style="vertical-align:-2px;"><path stroke-linecap="round" stroke-linejoin="round" d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14"/></svg>
          View Landing Page
        </a>

        <a href="../logout.php" class="btn btn-danger">
          <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" style="vertical-align:-2px;"><path stroke-linecap="round" stroke-linejoin="round" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1"/></svg>
          Logout
        </a>
      </div>
    </header>

    <!-- Main Workspace -->
    <div class="dashboard-layout">
      
      <!-- Sidebar Tabs Control -->
      <aside class="sidebar-panel">
        <div class="sidebar-heading">Sections</div>
        <nav class="tab-nav">
          <button class="tab-btn active" onclick="switchTab('news')">
            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M19 20H5a2 2 0 01-2-2V6a2 2 0 012-2h10a2 2 0 012 2v1m2 13a2 2 0 01-2-2V7m2 13a2 2 0 002-2V9a2 2 0 00-2-2h-2m-4-3H9M7 16h6M7 8h6v4H7V8z"/></svg>
            News & Updates
          </button>
          
          <button class="tab-btn" onclick="switchTab('events')">
            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/></svg>
            Upcoming Events
          </button>
          
          <button class="tab-btn" onclick="switchTab('gallery')">
            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"/></svg>
            Academy Gallery
          </button>
        </nav>
      </aside>

      <!-- Content Area -->
      <main class="content-panel">
        
        <!-- ==================== NEWS SECTION ==================== -->
        <section id="panel-news" class="panel-section active">
          <div class="section-title-wrap">
            <div>
              <h2 class="panel-title">News &amp; Updates</h2>
              <p class="panel-desc">Manage announcement posts displayed on the landing page.</p>
            </div>
            <button class="btn btn-gold" onclick="openNewsForm()">+ Add News</button>
          </div>
          
          <div class="item-grid" id="news-grid-container">
            <?php if (empty($news)): ?>
              <p style="grid-column: 1/-1; text-align: center; color: var(--text-muted); padding: 40px 0;">No news items configured yet.</p>
            <?php else: ?>
              <?php foreach ($news as $item): ?>
                <div class="crud-card" id="news-card-<?= $item['id'] ?>">
                  <div class="card-thumbnail">
                    <img src="<?= htmlspecialchars($item['img']) ?>" alt="">
                    <span class="card-badge"><?= htmlspecialchars($item['date']) ?></span>
                  </div>
                  <div class="card-content">
                    <h3 class="card-title"><?= htmlspecialchars($item['title']) ?></h3>
                    <p class="card-desc"><?= htmlspecialchars($item['excerpt']) ?></p>
                    <div class="card-actions">
                      <button class="action-btn btn-edit" onclick='editNews(<?= json_encode($item, JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT) ?>)'>Edit</button>
                      <button class="action-btn btn-delete" onclick="deleteNews(<?= $item['id'] ?>)">Delete</button>
                    </div>
                  </div>
                </div>
              <?php endforeach; ?>
            <?php endif; ?>
          </div>
        </section>

        <!-- ==================== EVENTS SECTION ==================== -->
        <section id="panel-events" class="panel-section">
          <div class="section-title-wrap">
            <div>
              <h2 class="panel-title">Upcoming Events</h2>
              <p class="panel-desc">Manage training trials, matches, and calendar items.</p>
            </div>
            <button class="btn btn-gold" onclick="openEventForm()">+ Add Event</button>
          </div>
          
          <div class="item-grid" id="events-grid-container">
            <?php if (empty($events)): ?>
              <p style="grid-column: 1/-1; text-align: center; color: var(--text-muted); padding: 40px 0;">No upcoming events scheduled.</p>
            <?php else: ?>
              <?php foreach ($events as $event): ?>
                <div class="crud-card" id="event-card-<?= $event['id'] ?>">
                  <div class="card-content">
                    <span class="card-badge" style="position:static; display:inline-block; align-self:flex-start; margin-bottom:12px;"><?= htmlspecialchars($event['date']) ?></span>
                    <h3 class="card-title"><?= htmlspecialchars($event['title']) ?></h3>
                    <div class="card-meta">
                      <svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z"/><path stroke-linecap="round" stroke-linejoin="round" d="M15 11a3 3 0 11-6 0 3 3 0 016 0z"/></svg>
                      <?= htmlspecialchars($event['venue']) ?>
                    </div>
                    <p class="card-desc" style="-webkit-line-clamp: 4;"><?= htmlspecialchars($event['desc']) ?></p>
                    <div class="card-actions">
                      <button class="action-btn btn-edit" onclick='editEvent(<?= json_encode($event, JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT) ?>)'>Edit</button>
                      <button class="action-btn btn-delete" onclick="deleteEvent(<?= $event['id'] ?>)">Delete</button>
                    </div>
                  </div>
                </div>
              <?php endforeach; ?>
            <?php endif; ?>
          </div>
        </section>

        <!-- ==================== GALLERY SECTION ==================== -->
        <section id="panel-gallery" class="panel-section">
          <div class="section-title-wrap">
            <div>
              <h2 class="panel-title">Academy Gallery</h2>
              <p class="panel-desc">Manage academy photos displayed in the visual gallery grid.</p>
            </div>
            <button class="btn btn-gold" onclick="openGalleryForm()">+ Add Photo</button>
          </div>
          
          <div class="item-grid" id="gallery-grid-container">
            <?php if (empty($gallery)): ?>
              <p style="grid-column: 1/-1; text-align: center; color: var(--text-muted); padding: 40px 0;">No gallery photos added yet.</p>
            <?php else: ?>
              <?php foreach ($gallery as $index => $photo): ?>
                <div class="crud-card" id="gallery-card-<?= $index ?>">
                  <div class="card-thumbnail" style="height:200px;">
                    <img src="<?= htmlspecialchars($photo['img']) ?>" alt="">
                  </div>
                  <div class="card-content">
                    <h3 class="card-title"><?= htmlspecialchars($photo['title']) ?></h3>
                    <p class="card-desc" style="-webkit-line-clamp: 2;"><?= htmlspecialchars($photo['desc']) ?></p>
                    <div class="card-actions">
                      <button class="action-btn btn-edit" onclick='editGallery(<?= $index ?>, <?= json_encode($photo, JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT) ?>)'>Edit</button>
                      <button class="action-btn btn-delete" onclick="deleteGallery(<?= $index ?>)">Delete</button>
                    </div>
                  </div>
                </div>
              <?php endforeach; ?>
            <?php endif; ?>
          </div>
        </section>

      </main>
    </div>
  </div>

  <!-- ── NEWS MODAL EDITOR ────────────────────────────────────── -->
  <div class="editor-modal" id="news-modal">
    <div class="modal-overlay" onclick="closeModal('news')"></div>
    <div class="modal-container">
      <div class="modal-header">
        <h3 class="modal-title" id="news-modal-title-text">Add News Post</h3>
        <button class="modal-close-btn" onclick="closeModal('news')">&times;</button>
      </div>
      <form id="news-form" onsubmit="submitNews(event)">
        <input type="hidden" name="action" value="save_news">
        <input type="hidden" name="id" id="news-id">
        <input type="hidden" name="existing_image" id="news-existing-image">
        
        <div class="modal-body">
          <div class="form-group">
            <label for="news-title-input">Article Title</label>
            <input type="text" id="news-title-input" name="title" class="form-control" placeholder="e.g. Academy Opens New Laser Range" required>
          </div>
          
          <div class="form-group">
            <label for="news-date-input">Display Date</label>
            <input type="text" id="news-date-input" name="date" class="form-control" placeholder="e.g. Jul 2026" required>
          </div>
          
          <div class="form-group">
            <label for="news-excerpt-input">Card Excerpt (Brief description)</label>
            <input type="text" id="news-excerpt-input" name="excerpt" class="form-control" placeholder="Brief summary shown on the card listing...">
          </div>
          
          <div class="form-group">
            <label for="news-body-input">Full Article Body (HTML Allowed)</label>
            <textarea id="news-body-input" name="body" class="form-control" placeholder="Detailed content displayed when reading details..."></textarea>
          </div>
          
          <div class="form-group">
            <label for="news-image-input">Banner Image</label>
            <div class="file-input-wrapper">
              <input type="file" id="news-image-input" name="image" class="form-control" accept="image/*" onchange="previewImage(this, 'news-img-preview')">
            </div>
            <div class="upload-preview" id="news-img-preview">
              <span>Select an image file to upload</span>
            </div>
          </div>
        </div>
        
        <div class="modal-footer">
          <button type="button" class="btn btn-outline" onclick="closeModal('news')">Cancel</button>
          <button type="submit" class="btn btn-gold" id="news-save-btn">Save Post</button>
        </div>
      </form>
    </div>
  </div>

  <!-- ── EVENTS MODAL EDITOR ──────────────────────────────────── -->
  <div class="editor-modal" id="events-modal">
    <div class="modal-overlay" onclick="closeModal('events')"></div>
    <div class="modal-container">
      <div class="modal-header">
        <h3 class="modal-title" id="events-modal-title-text">Add Academy Event</h3>
        <button class="modal-close-btn" onclick="closeModal('events')">&times;</button>
      </div>
      <form id="events-form" onsubmit="submitEvent(event)">
        <input type="hidden" name="action" value="save_event">
        <input type="hidden" name="id" id="event-id">
        
        <div class="modal-body">
          <div class="form-group">
            <label for="event-title-input">Event Name</label>
            <input type="text" id="event-title-input" name="title" class="form-control" placeholder="e.g. 51st State Shooting Championship" required>
          </div>
          
          <div class="form-group">
            <label for="event-date-input">Event Date Range</label>
            <input type="text" id="event-date-input" name="date" class="form-control" placeholder="e.g. July 15 - July 22, 2026" required>
          </div>
          
          <div class="form-group">
            <label for="event-venue-input">Venue / Location</label>
            <input type="text" id="event-venue-input" name="venue" class="form-control" placeholder="e.g. 10m Air Range Arena" required>
          </div>
          
          <div class="form-group">
            <label for="event-desc-input">Description</label>
            <textarea id="event-desc-input" name="desc" class="form-control" placeholder="Brief explanation of entry criteria or timings..."></textarea>
          </div>
        </div>
        
        <div class="modal-footer">
          <button type="button" class="btn btn-outline" onclick="closeModal('events')">Cancel</button>
          <button type="submit" class="btn btn-gold" id="event-save-btn">Save Event</button>
        </div>
      </form>
    </div>
  </div>

  <!-- ── GALLERY MODAL EDITOR ─────────────────────────────────── -->
  <div class="editor-modal" id="gallery-modal">
    <div class="modal-overlay" onclick="closeModal('gallery')"></div>
    <div class="modal-container">
      <div class="modal-header">
        <h3 class="modal-title" id="gallery-modal-title-text">Add Gallery Photo</h3>
        <button class="modal-close-btn" onclick="closeModal('gallery')">&times;</button>
      </div>
      <form id="gallery-form" onsubmit="submitGallery(event)">
        <input type="hidden" name="action" value="save_gallery">
        <input type="hidden" name="index" id="gallery-index">
        <input type="hidden" name="existing_image" id="gallery-existing-image">
        
        <div class="modal-body">
          <div class="form-group">
            <label for="gallery-title-input">Photo Caption</label>
            <input type="text" id="gallery-title-input" name="title" class="form-control" placeholder="e.g. 50m Electronic Rifle Range" required>
          </div>
          
          <div class="form-group">
            <label for="gallery-desc-input">Description / Subtitle</label>
            <input type="text" id="gallery-desc-input" name="desc" class="form-control" placeholder="e.g. Fully equipped target lanes for tournament practice.">
          </div>
          
          <div class="form-group">
            <label for="gallery-image-input">Upload Photo</label>
            <div class="file-input-wrapper">
              <input type="file" id="gallery-image-input" name="image" class="form-control" accept="image/*" onchange="previewImage(this, 'gallery-img-preview')">
            </div>
            <div class="upload-preview" id="gallery-img-preview">
              <span>Select an image file to upload</span>
            </div>
          </div>
        </div>
        
        <div class="modal-footer">
          <button type="button" class="btn btn-outline" onclick="closeModal('gallery')">Cancel</button>
          <button type="submit" class="btn btn-gold" id="gallery-save-btn">Save Photo</button>
        </div>
      </form>
    </div>
  </div>

  <!-- Client-side SPA Operations & AJAX logic -->
  <script>
    // Tab switching controller
    function switchTab(tabName) {
      document.querySelectorAll('.tab-btn').forEach(btn => btn.classList.remove('active'));
      document.querySelectorAll('.panel-section').forEach(sec => sec.classList.remove('active'));
      
      const targetBtn = Array.from(document.querySelectorAll('.tab-btn')).find(btn => btn.getAttribute('onclick').includes(tabName));
      if (targetBtn) targetBtn.classList.add('active');
      
      const targetSec = document.getElementById(`panel-${tabName}`);
      if (targetSec) targetSec.classList.add('active');
    }

    // Modal display handlers
    function openModal(id) {
      document.getElementById(`${id}-modal`).classList.add('show');
    }

    function closeModal(id) {
      document.getElementById(`${id}-modal`).classList.remove('show');
      document.getElementById(`${id}-form`).reset();
      
      const preview = document.getElementById(`${id}-img-preview`);
      if (preview) {
        preview.innerHTML = `<span>Select an image file to upload</span>`;
      }
    }

    // Image preview visualizer
    function previewImage(input, previewId) {
      const preview = document.getElementById(previewId);
      if (input.files && input.files[0]) {
        const reader = new FileReader();
        reader.onload = function(e) {
          preview.innerHTML = `<img src="${e.target.result}" alt="Preview">`;
        };
        reader.readAsDataURL(input.files[0]);
      } else {
        preview.innerHTML = `<span>Select an image file to upload</span>`;
      }
    }

    // Generic helper to show sweet alerts
    function showNotification(type, message) {
      Swal.fire({
        text: message,
        icon: type,
        background: '#101520',
        color: '#f3f1ec',
        confirmButtonColor: '#ADB5BD'
      });
    }

    // --- NEWS FORM ACTIONS ---
    function openNewsForm() {
      document.getElementById('news-id').value = '';
      document.getElementById('news-existing-image').value = '';
      document.getElementById('news-modal-title-text').innerText = 'Add News Post';
      document.getElementById('news-save-btn').innerText = 'Add Post';
      openModal('news');
    }

    function editNews(item) {
      document.getElementById('news-id').value = item.id;
      document.getElementById('news-existing-image').value = item.img;
      document.getElementById('news-title-input').value = item.title;
      document.getElementById('news-date-input').value = item.date;
      document.getElementById('news-excerpt-input').value = item.excerpt || '';
      document.getElementById('news-body-input').value = item.body || '';
      
      const preview = document.getElementById('news-img-preview');
      if (item.img) {
        preview.innerHTML = `<img src="${item.img}" alt="Preview">`;
      } else {
        preview.innerHTML = `<span>Select an image file to upload</span>`;
      }
      
      document.getElementById('news-modal-title-text').innerText = 'Edit News Post';
      document.getElementById('news-save-btn').innerText = 'Save Changes';
      openModal('news');
    }

    async function submitNews(e) {
      e.preventDefault();
      const form = document.getElementById('news-form');
      const fd = new FormData(form);
      
      try {
        const resp = await fetch('manage.php', {
          method: 'POST',
          body: fd
        });
        const res = await resp.json();
        if (res.success) {
          showNotification('success', res.message);
          closeModal('news');
          setTimeout(() => window.location.reload(), 1200);
        } else {
          showNotification('error', res.message || 'Saving failed.');
        }
      } catch {
        showNotification('error', 'A network connection error occurred.');
      }
    }

    // --- EVENTS FORM ACTIONS ---
    function openEventForm() {
      document.getElementById('event-id').value = '';
      document.getElementById('events-modal-title-text').innerText = 'Add Academy Event';
      document.getElementById('event-save-btn').innerText = 'Add Event';
      openModal('events');
    }

    function editEvent(event) {
      document.getElementById('event-id').value = event.id;
      document.getElementById('event-title-input').value = event.title;
      document.getElementById('event-date-input').value = event.date;
      document.getElementById('event-venue-input').value = event.venue;
      document.getElementById('event-desc-input').value = event.desc || '';
      
      document.getElementById('events-modal-title-text').innerText = 'Edit Academy Event';
      document.getElementById('event-save-btn').innerText = 'Save Changes';
      openModal('events');
    }

    async function submitEvent(e) {
      e.preventDefault();
      const form = document.getElementById('events-form');
      const fd = new FormData(form);
      
      try {
        const resp = await fetch('manage.php', {
          method: 'POST',
          body: fd
        });
        const res = await resp.json();
        if (res.success) {
          showNotification('success', res.message);
          closeModal('events');
          setTimeout(() => window.location.reload(), 1200);
        } else {
          showNotification('error', res.message || 'Saving failed.');
        }
      } catch {
        showNotification('error', 'A network connection error occurred.');
      }
    }

    // --- GALLERY FORM ACTIONS ---
    function openGalleryForm() {
      document.getElementById('gallery-index').value = '';
      document.getElementById('gallery-existing-image').value = '';
      document.getElementById('gallery-modal-title-text').innerText = 'Add Gallery Photo';
      document.getElementById('gallery-save-btn').innerText = 'Add Photo';
      openModal('gallery');
    }

    function editGallery(index, photo) {
      document.getElementById('gallery-index').value = index;
      document.getElementById('gallery-existing-image').value = photo.img;
      document.getElementById('gallery-title-input').value = photo.title;
      document.getElementById('gallery-desc-input').value = photo.desc || '';
      
      const preview = document.getElementById('gallery-img-preview');
      if (photo.img) {
        preview.innerHTML = `<img src="${photo.img}" alt="Preview">`;
      } else {
        preview.innerHTML = `<span>Select an image file to upload</span>`;
      }
      
      document.getElementById('gallery-modal-title-text').innerText = 'Edit Gallery Photo';
      document.getElementById('gallery-save-btn').innerText = 'Save Changes';
      openModal('gallery');
    }

    async function submitGallery(e) {
      e.preventDefault();
      const form = document.getElementById('gallery-form');
      const fd = new FormData(form);
      
      try {
        const resp = await fetch('manage.php', {
          method: 'POST',
          body: fd
        });
        const res = await resp.json();
        if (res.success) {
          showNotification('success', res.message);
          closeModal('gallery');
          setTimeout(() => window.location.reload(), 1200);
        } else {
          showNotification('error', res.message || 'Saving failed.');
        }
      } catch {
        showNotification('error', 'A network connection error occurred.');
      }
    }

    // --- DELETE OPERATIONS HANDLERS ---
    function deleteNews(id) {
      Swal.fire({
        title: 'Delete News Post?',
        text: "This item will be permanently removed from the website.",
        icon: 'warning',
        showCancelButton: true,
        background: '#101520',
        color: '#f3f1ec',
        confirmButtonColor: '#e74c3c',
        cancelButtonColor: 'rgba(255,255,255,0.1)',
        confirmButtonText: 'Yes, Delete'
      }).then(async (result) => {
        if (result.isConfirmed) {
          const fd = new FormData();
          fd.append('action', 'delete_news');
          fd.append('id', id);
          
          try {
            const resp = await fetch('manage.php', { method: 'POST', body: fd });
            const res = await resp.json();
            if (res.success) {
              const card = document.getElementById(`news-card-${id}`);
              if (card) card.remove();
              Swal.fire({
                text: 'News post deleted successfully.',
                icon: 'success',
                background: '#101520',
                color: '#f3f1ec',
                timer: 1500,
                showConfirmButton: false
              });
            } else {
              showNotification('error', res.message || 'Delete failed.');
            }
          } catch {
            showNotification('error', 'A network error occurred.');
          }
        }
      });
    }

    function deleteEvent(id) {
      Swal.fire({
        title: 'Delete Event?',
        text: "This calendar entry will be removed from the list.",
        icon: 'warning',
        showCancelButton: true,
        background: '#101520',
        color: '#f3f1ec',
        confirmButtonColor: '#e74c3c',
        cancelButtonColor: 'rgba(255,255,255,0.1)',
        confirmButtonText: 'Yes, Delete'
      }).then(async (result) => {
        if (result.isConfirmed) {
          const fd = new FormData();
          fd.append('action', 'delete_event');
          fd.append('id', id);
          
          try {
            const resp = await fetch('manage.php', { method: 'POST', body: fd });
            const res = await resp.json();
            if (res.success) {
              const card = document.getElementById(`event-card-${id}`);
              if (card) card.remove();
              Swal.fire({
                text: 'Event deleted successfully.',
                icon: 'success',
                background: '#101520',
                color: '#f3f1ec',
                timer: 1500,
                showConfirmButton: false
              });
            } else {
              showNotification('error', res.message || 'Delete failed.');
            }
          } catch {
            showNotification('error', 'A network error occurred.');
          }
        }
      });
    }

    function deleteGallery(index) {
      Swal.fire({
        title: 'Delete Photo?',
        text: "This image will be removed from the gallery display.",
        icon: 'warning',
        showCancelButton: true,
        background: '#101520',
        color: '#f3f1ec',
        confirmButtonColor: '#e74c3c',
        cancelButtonColor: 'rgba(255,255,255,0.1)',
        confirmButtonText: 'Yes, Delete'
      }).then(async (result) => {
        if (result.isConfirmed) {
          const fd = new FormData();
          fd.append('action', 'delete_gallery');
          fd.append('index', index);
          
          try {
            const resp = await fetch('manage.php', { method: 'POST', body: fd });
            const res = await resp.json();
            if (res.success) {
              const card = document.getElementById(`gallery-card-${index}`);
              if (card) card.remove();
              Swal.fire({
                text: 'Photo removed successfully.',
                icon: 'success',
                background: '#101520',
                color: '#f3f1ec',
                timer: 1500,
                showConfirmButton: false
              });
              setTimeout(() => window.location.reload(), 800); // reload to reindex array indexes
            } else {
              showNotification('error', res.message || 'Delete failed.');
            }
          } catch {
            showNotification('error', 'A network error occurred.');
          }
        }
      });
    }
  </script>
</body>
</html>
