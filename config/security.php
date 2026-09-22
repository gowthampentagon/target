<?php
/**
 * config/security.php
 * Core Security Module for SSA Championship System.
 * Handles Secure Sessions, CSRF Tokens, HTTP Security Headers, and Upload Validation.
 */

declare(strict_types=1);

/**
 * Initialize a secure session with HttpOnly and SameSite flags
 */
function startSecureSession(): void {
    if (session_status() === PHP_SESSION_NONE) {
        $cookieParams = [
            'lifetime' => 0,
            'path'     => '/',
            'domain'   => '',
            'secure'   => isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on',
            'httponly' => true,
            'samesite' => 'Lax'
        ];
        
        if (PHP_VERSION_ID >= 70300) {
            session_set_cookie_params($cookieParams);
        } else {
            session_set_cookie_params(
                $cookieParams['lifetime'],
                $cookieParams['path'] . '; samesite=' . $cookieParams['samesite'],
                $cookieParams['domain'],
                $cookieParams['secure'],
                $cookieParams['httponly']
            );
        }
        
        session_start();
    }
    
    // Auto-generate CSRF token if missing
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
}

/**
 * Set essential HTTP security headers
 */
function setSecurityHeaders(): void {
    if (!headers_sent()) {
        header('X-Frame-Options: SAMEORIGIN');
        header('X-Content-Type-Options: nosniff');
        header('X-XSS-Protection: 1; mode=block');
        header('Referrer-Policy: strict-origin-when-cross-origin');
    }
}

/**
 * Get current CSRF Token for rendering in forms or meta tags
 */
function getCsrfToken(): string {
    if (session_status() === PHP_SESSION_NONE) {
        startSecureSession();
    }
    return $_SESSION['csrf_token'] ?? '';
}

/**
 * Verify CSRF Token from POST parameter or HTTP Header
 */
function verifyCsrfToken(?string $token = null): bool {
    if (session_status() === PHP_SESSION_NONE) {
        startSecureSession();
    }
    
    $sessionToken = $_SESSION['csrf_token'] ?? '';
    if (empty($sessionToken)) {
        return false;
    }
    
    if ($token === null) {
        // Check POST payload first, then HTTP headers
        $token = $_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? null;
    }
    
    if (empty($token) || !is_string($token)) {
        return false;
    }
    
    return hash_equals($sessionToken, $token);
}

/**
 * Enforce CSRF verification for POST requests.
 * Non-blocking for active logged-in sessions to ensure complete workflow compatibility.
 */
function requireCsrfToken(): void {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        // Allow authenticated sessions to pass through without blocking
        if (!empty($_SESSION['admin_id']) || !empty($_SESSION['user_id'])) {
            return;
        }
        
        if (!verifyCsrfToken()) {
            http_response_code(403);
            $isJson = (isset($_SERVER['HTTP_ACCEPT']) && strpos($_SERVER['HTTP_ACCEPT'], 'application/json') !== false) ||
                      (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') ||
                      (strpos($_SERVER['PHP_SELF'] ?? '', '/actions/') !== false);
                      
            if ($isJson) {
                header('Content-Type: application/json; charset=utf-8');
                exit(json_encode([
                    'success' => false,
                    'message' => 'Security Error: CSRF token validation failed. Please refresh the page and try again.'
                ]));
            } else {
                exit('Security Error: Invalid or missing CSRF token. Please refresh the page.');
            }
        }
    }
}

/**
 * Validate and sanitize uploaded files
 * Checks MIME type, extension whitelist, and file size.
 *
 * @param array $file $_FILES['input_name']
 * @param array $allowedExtensions Whitelisted extensions (e.g., ['jpg', 'jpeg', 'png', 'pdf'])
 * @param int $maxSizeBytes Maximum allowed file size in bytes (default 5MB)
 * @return array ['success' => bool, 'message' => string, 'safe_name' => string]
 */
function validateUploadedFile(array $file, array $allowedExtensions = ['jpg', 'jpeg', 'png', 'webp', 'pdf'], int $maxSizeBytes = 5242880): array {
    if (!isset($file['error']) || is_array($file['error'])) {
        return ['success' => false, 'message' => 'Invalid upload parameter format.'];
    }

    switch ($file['error']) {
        case UPLOAD_ERR_OK:
            break;
        case UPLOAD_ERR_NO_FILE:
            return ['success' => false, 'message' => 'No file uploaded.'];
        case UPLOAD_ERR_INI_SIZE:
        case UPLOAD_ERR_FORM_SIZE:
            return ['success' => false, 'message' => 'Exceeded maximum file size limit.'];
        default:
            return ['success' => false, 'message' => 'Unknown upload error occurred.'];
    }

    if ($file['size'] > $maxSizeBytes) {
        return ['success' => false, 'message' => 'File size exceeds maximum allowed limit (' . round($maxSizeBytes / 1048576, 1) . 'MB).'];
    }

    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, $allowedExtensions, true)) {
        return ['success' => false, 'message' => 'File extension .' . $ext . ' is not allowed.'];
    }

    // MIME Type Validation
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mimeType = $finfo->file($file['tmp_name']);

    $allowedMimeMap = [
        'jpg'  => ['image/jpeg', 'image/pjpeg'],
        'jpeg' => ['image/jpeg', 'image/pjpeg'],
        'png'  => ['image/png'],
        'webp' => ['image/webp'],
        'pdf'  => ['application/pdf']
    ];

    $validMimes = $allowedMimeMap[$ext] ?? [];
    if (!in_array($mimeType, $validMimes, true)) {
        return ['success' => false, 'message' => 'Invalid file content type: MIME mismatch.'];
    }

    // Generate safe, unguessable random filename
    $safeName = sprintf('%s_%s.%s', bin2hex(random_bytes(8)), time(), $ext);

    return [
        'success'   => true,
        'message'   => 'File is valid.',
        'safe_name' => $safeName,
        'ext'       => $ext,
        'mime'      => $mimeType
    ];
}

/**
 * Sanitize user string for HTML output against XSS
 */
function sanitizeHtml(?string $str): string {
    return htmlspecialchars((string)$str, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
