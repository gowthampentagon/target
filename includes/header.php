<?php
/**
 * includes/header.php
 * Shared page shell – open tags, bg, header branding
 */
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Prevent browser from caching authenticated pages.
// Stops back-button re-showing protected pages after logout.
if (!empty($_SESSION['user_id']) || !empty($_SESSION['admin_id'])) {
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    header('Expires: Sat, 01 Jan 2000 00:00:00 GMT');
}

require_once dirname(__DIR__) . '/config/db.php';
$activeChampionship = getActiveChampionship();

$pageTitle    = $pageTitle    ?? ($activeChampionship['championship_name'] ?? 'TARGET');
$pageDesc     = $pageDesc     ?? ($activeChampionship['championship_name'] . ' – Official Registration Portal');
$bodyClass    = $bodyClass    ?? '';
?>
<!DOCTYPE html>
<html lang="en" data-bs-theme="dark">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <meta name="csrf-token" content="<?= getCsrfToken() ?>">
  <script>
    (function() {
      const originalFetch = window.fetch;
      const csrfToken = "<?= getCsrfToken() ?>";
      window.fetch = function(url, options) {
        options = options || {};
        options.headers = options.headers || {};
        if (options.headers instanceof Headers) {
          if (!options.headers.has('X-CSRF-Token')) {
            options.headers.append('X-CSRF-Token', csrfToken);
          }
        } else if (Array.isArray(options.headers)) {
          options.headers.push(['X-CSRF-Token', csrfToken]);
        } else {
          if (!options.headers['X-CSRF-Token']) {
            options.headers['X-CSRF-Token'] = csrfToken;
          }
        }
        return originalFetch.call(this, url, options);
      };

      document.addEventListener('DOMContentLoaded', function() {
        document.querySelectorAll('form[method="POST"], form[method="post"]').forEach(function(form) {
          if (!form.querySelector('input[name="csrf_token"]')) {
            const input = document.createElement('input');
            input.type = 'hidden';
            input.name = 'csrf_token';
            input.value = csrfToken;
            form.appendChild(input);
          }
        });
      });
    })();
  </script>
  <meta name="description" content="<?= htmlspecialchars($pageDesc) ?>">
  <meta name="robots" content="index, follow">
  <title><?= htmlspecialchars($pageTitle) ?> | <?= htmlspecialchars($activeChampionship['championship_name'] ?? 'TARGET') ?></title>
  <link rel="icon" href="images/logo.png?v=<?= time() ?>" type="image/png">
  <link rel="shortcut icon" href="favicon.ico?v=<?= time() ?>" type="image/x-icon">
  <link rel="apple-touch-icon" href="images/logo.png?v=<?= time() ?>">

  <!-- Google Fonts -->
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Rajdhani:wght@400;500;600;700&family=Inter:wght@300;400;500;600;700&family=Cinzel:wght@400;600;700&family=Playfair+Display:ital,wght@0,700;1,600&display=swap" rel="stylesheet">

  <!-- Stylesheet -->
  <link rel="stylesheet" href="css/style.css?v=<?= time() ?>">
  <!-- Bootstrap Icons -->
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
</head>
<body class="<?= htmlspecialchars($bodyClass) ?>">

<!-- SSA Photo Background -->
<div class="page-bg" aria-hidden="true"></div>
<div class="page-bg-noise" aria-hidden="true"></div>

<div class="page-wrapper">

  <!-- Branding header -->
  <header class="site-header">
    <div class="emblem" aria-hidden="true" style="border:none; background:transparent;">
      <div class="emblem-inner" style="border:none; background:transparent;">
        <img src="images/logo.png?v=<?= time() ?>" alt="TARGET Logo" style="width:58px; height:58px; object-fit:contain; filter: drop-shadow(0 2px 8px rgba(0,0,0,0.5));">
      </div>
    </div>
    <p class="org-name" style="margin-bottom:0; line-height:1.1; font-size: 24px; letter-spacing: 2px; font-weight: 700; color: var(--gold-400, #c9a84c);"><?= htmlspecialchars(($activeChampionship['org_name'] ?? '') === 'Saragarhi Shooting Academy' ? 'TARGET' : ($activeChampionship['org_name'] ?? 'TARGET')) ?></p>
    <span class="org-expansion" style="display:block; font-size:10px; color:var(--text-secondary, #a0aec0); letter-spacing:0.5px; margin-top:1px; margin-bottom:8px; font-weight:600; text-transform:uppercase; line-height:1.2;">Tournament Administration and Registration Gateway for Event Tracking</span>
    <p class="event-title"><?= htmlspecialchars($activeChampionship['championship_name'] ?? 'Tamil Nadu State Shooting Championship') ?></p>
    <div class="divider" aria-hidden="true"><span class="divider-diamond"></span></div>
  </header>
