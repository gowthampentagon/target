<?php
// Score Sheet/index.php
declare(strict_types=1);
require_once dirname(__DIR__) . '/config/db.php';
require_once dirname(__DIR__) . '/config/events.php';

if (session_status() === PHP_SESSION_NONE) session_start();
if (empty($_SESSION['admin_id'])) {
    header('Location: ../login.php');
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Saragarhi Shooting Academy — Score Sheets</title>
  <link rel="stylesheet" href="sheet.css">
  <!-- Bootstrap 5 CSS -->
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
  <!-- Bootstrap Icons -->
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
</head>
<body>
<div class="page">

  <!-- HEADER -->
  <header class="academy-header">
    <div class="crest" style="margin-bottom: 10px;">
      <i class="bi bi-bullseye text-secondary" style="font-size: 48px; line-height: 1;"></i>
    </div>
    <div class="header-text">
      <h1>SARAGARHI SHOOTING ACADEMY</h1>
      <p class="tagline">PRECISION &bull; VALOUR &bull; DISCIPLINE</p>
      <p class="sheet-label">Official Match Score Sheets Portal</p>
    </div>
  </header>

  <!-- NAV -->
  <nav class="nav-bar" style="margin-bottom: 20px;">
    <a href="../admin/start_sheet.php" class="btn btn-back">← Back to Admin start lists</a>
  </nav>

  <!-- DASHBOARD -->
  <main>
    <h2 class="section-title">Select Shooting Event</h2>
    <div class="event-grid">

      <!-- Centre Fire Pistol -->
      <div class="event-card">
        <div class="card-icon"><i class="bi bi-crosshair"></i></div>
        <div class="card-body">
          <h3>Centre Fire / 25M Pistol</h3>
          <p>25M Precision &amp; Duelling — 6 series rows each (12 series total).</p>
          <span class="card-chip">Men / Jr. Men / Women / Jr. Women</span>
        </div>
        <a href="centre-fire-pistol.php" class="card-cta">Open Score Sheet →</a>
      </div>

      <!-- 10M Air Rifle / Air Pistol -->
      <div class="event-card">
        <div class="card-icon"><i class="bi bi-bullseye"></i></div>
        <div class="card-body">
          <h3>10M Air Rifle / Air Pistol</h3>
          <p>Six series of 10 shots each — 60 shots total.</p>
          <span class="card-chip">6 Series (60 Shots)</span>
        </div>
        <a href="air-rifle-pistol.php" class="card-cta">Open Score Sheet →</a>
      </div>

      <!-- Standard Pistol -->
      <div class="event-card">
        <div class="card-icon"><i class="bi bi-stopwatch"></i></div>
        <div class="card-body">
          <h3>Standard Pistol</h3>
          <p>Time-based stages: 150 sec, 20 sec, and 10 sec.</p>
          <span class="card-chip">Men / Jr. Men / Jr. Women</span>
        </div>
        <a href="standard-pistol.php" class="card-cta">Open Score Sheet →</a>
      </div>

      <!-- 50M Rifle Prone -->
      <div class="event-card">
        <div class="card-icon"><i class="bi bi-eye"></i></div>
        <div class="card-body">
          <h3>50M Rifle Prone</h3>
          <p>Six prone-position series, 10 shots each — 60 total.</p>
          <span class="card-chip">Men / Jr. Men / Jr. Women / Women</span>
        </div>
        <a href="rifle-prone.php" class="card-cta">Open Score Sheet →</a>
      </div>

    </div>
  </main>

  <footer>&copy; 2026 Saragarhi Shooting Academy. All rights reserved.</footer>
</div>
  <!-- Bootstrap 5 JS -->
  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
