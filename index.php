<?php
/**
 * index.php - Saragarhi Shooting Academy Dashboard / Landing Page
 */
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/config/db.php';

$dataPath = __DIR__ . '/ssa-dashboard/data.json';
if (file_exists($dataPath)) {
    $data = json_decode(file_get_contents($dataPath), true);
} else {
    $data = ['news' => [], 'gallery' => [], 'events' => []];
}

// Fetch official updates
try {
    $pdo = getDB();
    // Auto-create landing_updates table if not exists (fail-safe)
    $pdo->exec("CREATE TABLE IF NOT EXISTS `landing_updates` (
      `id` INT AUTO_INCREMENT PRIMARY KEY,
      `title` VARCHAR(255) NOT NULL,
      `update_type` ENUM('rank_list', 'certificate', 'general') NOT NULL,
      `file_path` VARCHAR(255) NULL,
      `cover_image` VARCHAR(255) NULL,
      `description` TEXT NULL,
      `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");

    // Dynamic schema update: add cover_image column if it does not exist
    try {
        $pdo->exec("ALTER TABLE `landing_updates` ADD COLUMN `cover_image` VARCHAR(255) NULL AFTER `file_path`");
    } catch (Exception $colEx) {
        // Suppress if column already exists
    }

    $stmtUpdates = $pdo->query("SELECT * FROM landing_updates ORDER BY created_at DESC LIMIT 10");
    $officialUpdates = $stmtUpdates->fetchAll();
} catch (Exception $e) {
    $officialUpdates = [];
}

function fixImgPath($path) {
    if (strpos($path, '../') === 0) {
        return substr($path, 3); // strip '../'
    }
    return $path;
}

function getAttachmentUrl($path) {
    if (empty($path)) return '';
    $path = str_replace('\\', '/', trim($path));
    if (strpos($path, 'http://') === 0 || strpos($path, 'https://') === 0) {
        return $path;
    }
    if (strpos($path, '/') === 0) {
        return substr($path, 1);
    }
    return $path;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <meta name="description" content="Welcome to TARGET (Tournament Administration and Registration Gateway for Event Tracking) - The premier portal for Olympic precision shooting sports. Learn about training, ranges, and news.">
  <meta name="robots" content="index, follow">
  <title>TARGET | Official Portal</title>
  <link rel="icon" href="images/logo.png?v=<?= time() ?>" type="image/png">
  <link rel="shortcut icon" href="favicon.ico?v=<?= time() ?>" type="image/x-icon">
  <link rel="apple-touch-icon" href="images/logo.png?v=<?= time() ?>">

  <!-- Google Fonts -->
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Cinzel:wght@400;600;700&family=Inter:wght@300;400;500;600;700&family=Rajdhani:wght@400;500;600;700&display=swap" rel="stylesheet">

  <!-- Local Custom Stylesheet -->
  <link rel="stylesheet" href="ssa-dashboard/style.css">

  <style>
    .btn-portal-register {
      background: transparent !important;
      color: var(--text-primary) !important;
      border: 1.5px solid rgba(255, 255, 255, 0.25) !important;
      box-shadow: none !important;
      transition: all 0.2s ease !important;
    }
    .btn-portal-register:hover {
      background: rgba(255, 255, 255, 0.1) !important;
      color: #ffffff !important;
      border-color: rgba(255, 255, 255, 0.45) !important;
      transform: translateY(-2px);
    }
    .hero-actions .btn-secondary-custom {
      font-family: 'Rajdhani', sans-serif;
      font-weight: 700;
      font-size: 1.05rem;
      text-transform: uppercase;
      letter-spacing: 1.5px;
      color: var(--text-primary) !important;
      background: transparent;
      padding: 11px 31px;
      border-radius: var(--radius-sm);
      border: 1px solid rgba(255, 255, 255, 0.2);
      cursor: pointer;
      display: inline-flex;
      align-items: center;
      transition: background var(--trans-fast), color var(--trans-fast), border-color var(--trans-fast);
    }
    .hero-actions .btn-secondary-custom:hover {
      background: rgba(255, 255, 255, 0.08);
      color: #ffffff !important;
      border-color: rgba(255, 255, 255, 0.4);
      transform: translateY(-2px);
    }
    /* Fix background image reference from parent directory style.css */
    .bg-cinematic {
      background-image: url('images/gallery/ssa_range_1.jpg') !important;
    }
  </style>
</head>
<body>

  <!-- Cinematic Background Layer -->
  <div class="bg-cinematic" aria-hidden="true"></div>
  <div class="bg-overlay" aria-hidden="true"></div>

  <!-- Navigation Bar -->
  <nav class="navbar">
    <div class="nav-brand">
      <img src="images/logo.png?v=<?= time() ?>" alt="TARGET Logo" style="width:34px; height:34px; object-fit:contain; margin-right:8px;">
      <span class="nav-title">TARGET</span>
    </div>
    <ul class="nav-links">
      <li><a href="#hero">Home</a></li>
      <li><a href="#about">About</a></li>
      <li><a href="#news">News</a></li>
      <li><a href="#events">Events</a></li>
      <li><a href="#updates">Updates</a></li>
      <li><a href="#gallery">Gallery</a></li>
    </ul>
    <div style="display: flex; align-items: center;">
      <a href="login.php" class="btn-portal" style="margin-right: 10px;">Login</a>
      <a href="register.php" class="btn-portal btn-portal-register">New Register</a>
    </div>
  </nav>

  <!-- Hero Section -->
  <section id="hero" class="hero">
    <div class="hero-emblem" aria-hidden="true" style="border:none; background:transparent;">
      <div class="hero-emblem-inner" style="border:none; background:transparent;">
        <img src="images/logo.png?v=<?= time() ?>" alt="TARGET Logo" style="width:80px; height:80px; object-fit:contain; filter: drop-shadow(0 4px 15px rgba(0,0,0,0.6));">
      </div>
    </div>
    <p class="hero-subtitle">Precision &bull; Discipline &bull; Excellence</p>
    <h1 class="hero-title" style="margin-bottom: 0px; line-height: 1.05;">TARGET</h1>
    <p class="hero-expansion" style="font-size: 13px; font-weight: 700; color: var(--gold-400, #c9a84c); margin-top: 2px; margin-bottom: 25px; letter-spacing: 0.8px; text-transform: uppercase; opacity: 0.9;">Tournament Administration and Registration Gateway for Event Tracking</p>
    <p class="hero-description">
      Empowering athletes with state-of-the-art infrastructure, advanced diagnostics, and olympic-grade coaching methodologies. Elevating precision shooting standard across the nation.
    </p>
    <div class="hero-actions">
      <a href="login.php" class="btn-primary">
        Login
        <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="currentColor" viewBox="0 0 16 16">
          <path fill-rule="evenodd" d="M1 8a.5.5 0 0 1 .5-.5h11.793l-3.147-3.146a.5.5 0 0 1 .708-.708l4 4a.5.5 0 0 1 0 .708l-4 4a.5.5 0 0 1-.708-.708L13.293 8.5H1.5A.5.5 0 0 1 1 8z"/>
        </svg>
      </a>
      <a href="register.php" class="btn-secondary-custom">New Register</a>
    </div>
  </section>

  <!-- About Us Section -->
  <section id="about" class="about">
    <div class="section-header">
      <p class="section-subtitle">Discover Our Legacy</p>
      <h2 class="section-title">About the Academy</h2>
    </div>

    <div class="about-content">
      <div class="about-text">
        <p>
          Founded on the core principles of concentration, posture perfection, and dedicated discipline, <strong>TARGET (Tournament Administration and Registration Gateway for Event Tracking)</strong> stands as a premium center of athletic coaching. We offer specialized training formats for Pistol, Rifle, and Trap disciplines in various distance classes.
        </p>
        <p>
          Our mission is to spot, nurture, and prepare shooting talents for regional, national, and Olympic competitions. Our coaches hold prestigious ISSF certifications and incorporate biomechanical monitoring alongside state-of-the-art telemetry tools.
        </p>
        <ul class="about-highlights">
          <li>
            <!-- Checkmark SVG -->
            <svg viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
              <path d="M9 16.17L4.83 12l-1.42 1.41L9 19 21 7l-1.41-1.41z"/>
            </svg>
            Olympic-Standard Electronic Targets (EST)
          </li>
          <li>
            <svg viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
              <path d="M9 16.17L4.83 12l-1.42 1.41L9 19 21 7l-1.41-1.41z"/>
            </svg>
            Biomechanical and Laser diagnostics technology
          </li>
          <li>
            <svg viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
              <path d="M9 16.17L4.83 12l-1.42 1.41L9 19 21 7l-1.41-1.41z"/>
            </svg>
            Certified ISSF A & B Level National Coaches
          </li>
        </ul>
      </div>

      <div class="about-card-box">
        <div class="stats-grid">
          <div class="stat-item">
            <div class="stat-num" data-target="50">0</div>
            <div class="stat-label">Shooting Lanes</div>
          </div>
          <div class="stat-item">
            <div class="stat-num" data-target="15">0</div>
            <div class="stat-label">ISSF Coaches</div>
          </div>
          <div class="stat-item">
            <div class="stat-num" data-target="500">0</div>
            <div class="stat-label">Active Members</div>
          </div>
          <div class="stat-item">
            <div class="stat-num" data-target="12">0</div>
            <div class="stat-label">State Champions</div>
          </div>
        </div>
      </div>
    </div>
  </section>

  <!-- News Section -->
  <section id="news" class="news">
    <div class="section-header">
      <p class="section-subtitle">Stay Informed</p>
      <h2 class="section-title">Academy News &amp; Updates</h2>
    </div>

    <div class="news-grid">
      <?php if (!empty($data['news'])): ?>
        <?php foreach ($data['news'] as $item): ?>
          <article class="news-card" onclick="openNews(<?= htmlspecialchars((string)$item['id']) ?>)">
            <div class="news-img-container">
              <span class="news-date"><?= htmlspecialchars($item['date']) ?></span>
              <img class="news-img" src="<?= htmlspecialchars(fixImgPath($item['img'])) ?>" alt="<?= htmlspecialchars($item['title']) ?>">
            </div>
            <div class="news-info">
              <h3 class="news-title"><?= htmlspecialchars($item['title']) ?></h3>
              <p class="news-excerpt"><?= htmlspecialchars($item['excerpt']) ?></p>
              <span class="news-more">
                Read More
                <svg viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                  <path d="M12 4l-1.41 1.41L16.17 11H4v2h12.17l-5.58 5.59L12 20l8-8z"/>
                </svg>
              </span>
            </div>
          </article>
        <?php endforeach; ?>
      <?php else: ?>
        <p style="grid-column: 1/-1; text-align: center; color: var(--text-muted);">No news updates available.</p>
      <?php endif; ?>
    </div>
  </section>

  <!-- Events Section -->
  <section id="events" class="events">
    <div class="section-header">
      <p class="section-subtitle">Mark Your Calendar</p>
      <h2 class="section-title">Upcoming Events</h2>
    </div>

    <div class="events-grid">
      <?php if (!empty($data['events'])): ?>
        <?php foreach ($data['events'] as $event): ?>
          <div class="event-card">
            <div class="event-badge">
              <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="event-calendar-icon" style="width:16px;height:16px;vertical-align:middle;margin-right:4px;">
                <rect x="3" y="4" width="18" height="18" rx="2" ry="2"/>
                <line x1="16" y1="2" x2="16" y2="6"/>
                <line x1="8" y1="2" x2="8" y2="6"/>
                <line x1="3" y1="10" x2="21" y2="10"/>
              </svg>
              <span>Upcoming</span>
            </div>
            <div class="event-content">
              <span class="event-date-text"><?= htmlspecialchars($event['date']) ?></span>
              <h3 class="event-card-title"><?= htmlspecialchars($event['title']) ?></h3>
              <p class="event-venue">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="event-pin-icon" style="width:14px;height:14px;vertical-align:-2px;margin-right:4px;color:var(--gold-400);">
                  <path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"/>
                  <circle cx="12" cy="10" r="3"/>
                </svg>
                <?= htmlspecialchars($event['venue']) ?>
              </p>
              <p class="event-desc"><?= htmlspecialchars($event['desc']) ?></p>
            </div>
          </div>
        <?php endforeach; ?>
      <?php else: ?>
        <p style="grid-column: 1/-1; text-align: center; color: var(--text-muted);">No upcoming events scheduled.</p>
      <?php endif; ?>
    </div>
  </section>

  <!-- Official Updates Section -->
  <section id="updates" class="updates" style="padding: 80px 0; background: rgba(8, 10, 15, 0.4); border-top: 1px solid rgba(255,255,255,0.03); border-bottom: 1px solid rgba(255,255,255,0.03);">
    <div class="container" style="max-width: 1200px; margin: 0 auto; padding: 0 20px;">
      <div class="section-header" style="text-align: center; margin-bottom: 50px;">
        <p class="section-subtitle" style="font-family:'Rajdhani', sans-serif; font-weight:700; font-size:0.95rem; text-transform:uppercase; color:var(--gold-400); letter-spacing:2px; margin-bottom:10px;">Official Announcements</p>
        <h2 class="section-title" style="font-family:'Cinzel', serif; font-size:2.4rem; color:var(--ssa-text-1); text-transform:uppercase; letter-spacing:1.5px; margin-bottom: 0;">Results &amp; Rank Lists</h2>
      </div>

      <style>
        .update-row-card {
          background: rgba(20, 24, 33, 0.85);
          border: 1.5px solid rgba(108, 117, 125, 0.12);
          border-radius: 12px;
          padding: 24px;
          display: flex;
          justify-content: space-between;
          align-items: center;
          flex-wrap: wrap;
          gap: 20px;
          transition: all 0.25s ease;
          box-shadow: 0 8px 32px rgba(0,0,0,0.25);
        }
        .update-row-card:hover {
          border-color: rgba(255, 255, 255, 0.25) !important;
          transform: translateY(-2px);
          box-shadow: 0 12px 40px rgba(0,0,0,0.4);
        }
        .btn-doc-view {
          display: inline-flex;
          align-items: center;
          gap: 7px;
          font-family: 'Rajdhani', sans-serif;
          font-weight: 700;
          text-transform: uppercase;
          letter-spacing: 0.8px;
          font-size: 12px;
          padding: 9px 18px;
          text-decoration: none !important;
          border: 1px solid rgba(255, 255, 255, 0.35);
          background: rgba(255, 255, 255, 0.08);
          color: #ffffff !important;
          border-radius: 6px;
          box-shadow: 0 2px 8px rgba(0, 0, 0, 0.2);
          transition: all 0.2s ease-in-out;
          cursor: pointer;
        }
        .btn-doc-view:hover {
          background: #ffffff !important;
          color: #0d1117 !important;
          border-color: #ffffff !important;
          box-shadow: 0 4px 14px rgba(255, 255, 255, 0.3);
          transform: translateY(-1px);
        }
        .btn-doc-download {
          display: inline-flex;
          align-items: center;
          gap: 7px;
          font-family: 'Rajdhani', sans-serif;
          font-weight: 700;
          text-transform: uppercase;
          letter-spacing: 0.8px;
          font-size: 12px;
          padding: 9px 18px;
          text-decoration: none !important;
          border: 1px solid #d4af37;
          background: rgba(212, 175, 55, 0.15);
          color: #ffd700 !important;
          border-radius: 6px;
          box-shadow: 0 2px 8px rgba(212, 175, 55, 0.15);
          transition: all 0.2s ease-in-out;
          cursor: pointer;
        }
        .btn-doc-download:hover {
          background: #d4af37 !important;
          color: #000000 !important;
          border-color: #ffd700 !important;
          box-shadow: 0 4px 14px rgba(212, 175, 55, 0.4);
          transform: translateY(-1px);
        }
      </style>

      <div class="updates-timeline" style="display: flex; flex-direction: column; gap: 24px;">
        <?php if (!empty($officialUpdates)): ?>
          <?php foreach ($officialUpdates as $u): ?>
            <div class="update-row-card" style="display: flex; align-items: stretch; gap: 24px;">
              <?php if (!empty($u['cover_image'])): ?>
                <div class="update-cover-container" style="width: 160px; min-width: 160px; height: 110px; border-radius: 8px; overflow: hidden; border: 1.5px solid rgba(255,255,255,0.12); background: rgba(0,0,0,0.3); display: flex; align-items: center; justify-content: center;">
                  <img src="<?= htmlspecialchars($u['cover_image']) ?>" alt="Cover image" style="width: 100%; height: 100%; object-fit: cover;">
                </div>
              <?php endif; ?>
              
              <div style="flex: 1; min-width: 280px; display: flex; flex-direction: column; justify-content: center;">
                <div style="display: flex; align-items: center; gap: 10px; margin-bottom: 10px; flex-wrap: wrap;">
                  <?php if ($u['update_type'] === 'rank_list'): ?>
                    <span style="background: rgba(255,255,255,0.08); color: var(--text-primary); font-size: 10.5px; font-weight: 700; text-transform: uppercase; padding: 4px 10px; border-radius: 20px; border: 1px solid rgba(255,255,255,0.2); letter-spacing: 0.5px; font-family:'Rajdhani', sans-serif;">🏆 Rank List</span>
                  <?php elseif ($u['update_type'] === 'certificate'): ?>
                    <span style="background: rgba(46,204,113,0.1); color: #2ecc71; font-size: 10.5px; font-weight: 700; text-transform: uppercase; padding: 4px 10px; border-radius: 20px; border: 1px solid rgba(46,204,113,0.25); letter-spacing: 0.5px; font-family:'Rajdhani', sans-serif;">🎖️ Certificate</span>
                  <?php else: ?>
                    <span style="background: rgba(52,152,219,0.1); color: #3498db; font-size: 10.5px; font-weight: 700; text-transform: uppercase; padding: 4px 10px; border-radius: 20px; border: 1px solid rgba(52,152,219,0.25); letter-spacing: 0.5px; font-family:'Rajdhani', sans-serif;">📢 Notice</span>
                  <?php endif; ?>
                  <span style="font-size: 12px; color: var(--text-muted); font-family: 'Rajdhani', sans-serif; font-weight: 600;">Posted on <?= date('d M Y', strtotime($u['created_at'])) ?></span>
                </div>
                <h3 style="font-family: 'Rajdhani', sans-serif; font-weight: 700; font-size: 1.35rem; color: var(--text-primary); margin: 0 0 8px 0;"><?= htmlspecialchars($u['title']) ?></h3>
                <?php if (!empty($u['description'])): ?>
                  <p style="color: var(--text-secondary); font-size: 0.92rem; line-height: 1.6; margin: 0; white-space: pre-wrap; font-family:'Inter', sans-serif;"><?= htmlspecialchars($u['description']) ?></p>
                <?php endif; ?>
              </div>
              
              <?php if (!empty($u['file_path'])): 
                $fileUrl = htmlspecialchars(getAttachmentUrl($u['file_path']));
                $fileTitle = htmlspecialchars($u['title']);
              ?>
                <div style="display: flex; align-items: center; gap: 12px; flex-wrap: wrap;">
                  <a href="javascript:void(0);" data-filepath="<?= $fileUrl ?>" data-title="<?= $fileTitle ?>" onclick="triggerAttachmentModal(this)" class="btn-doc-view">
                    <svg xmlns="http://www.w3.org/2000/svg" width="15" height="15" fill="currentColor" class="bi bi-eye" viewBox="0 0 16 16" style="vertical-align: middle;">
                      <path d="M16 8s-3-5.5-8-5.5S0 8 0 8s3 5.5 8 5.5S16 8 16 8zM1.173 8a13.133 13.133 0 0 1 1.66-2.043C4.12 4.668 5.88 3.5 8 3.5c2.12 0 3.879 1.168 5.168 2.457A13.133 13.133 0 0 1 14.828 8c-.058.087-.122.183-.195.288-.335.48-.83 1.12-1.465 1.755C11.879 11.332 10.119 12.5 8 12.5c-2.12 0-3.879-1.168-5.168-2.457A13.134 13.134 0 0 1 1.172 8z"/>
                      <path d="M8 5.5a2.5 2.5 0 1 0 0 5 2.5 2.5 0 0 0 0-5zM4.5 8a3.5 3.5 0 1 1 7 0 3.5 3.5 0 0 1-7 0z"/>
                    </svg>
                    View Document
                  </a>

                  <a href="<?= $fileUrl ?>" download target="_blank" class="btn-doc-download">
                    <svg xmlns="http://www.w3.org/2000/svg" width="15" height="15" fill="currentColor" class="bi bi-download" viewBox="0 0 16 16" style="vertical-align: middle;">
                      <path d="M.5 9.9a.5.5 0 0 1 .5.5v2.5a1 1 0 0 0 1 1h12a1 1 0 0 0 1-1v-2.5a.5.5 0 0 1 1 0v2.5a2 2 0 0 1-2 2H2a2 2 0 0 1-2-2v-2.5a.5.5 0 0 1 .5-.5z"/>
                      <path d="M7.646 11.854a.5.5 0 0 0 .708 0l3-3a.5.5 0 0 0-.708-.708L8.5 10.293V1.5a.5.5 0 0 0-1 0v8.793L5.354 8.146a.5.5 0 1 0-.708.708l3 3z"/>
                    </svg>
                    Download Doc
                  </a>
                </div>
              <?php endif; ?>
            </div>
          <?php endforeach; ?>
        <?php else: ?>
          <div style="background: rgba(20, 24, 33, 0.5); border: 1px dashed rgba(255,255,255,0.08); border-radius: 12px; padding: 40px; text-align: center; color: var(--text-muted);">
            <svg xmlns="http://www.w3.org/2000/svg" width="32" height="32" fill="currentColor" class="bi bi-info-circle" viewBox="0 0 16 16" style="margin-bottom:12px; opacity:0.6; color: var(--text-muted);">
              <path d="M8 15A7 7 0 1 1 8 1a7 7 0 0 1 0 14zm0 1A8 8 0 1 0 8 0a8 8 0 0 0 0 16z"/>
              <path d="m8.93 6.588-2.29.287-.082.38.45.083c.294.07.352.176.288.469l-.738 3.468c-.194.897.105 1.319.808 1.319.545 0 1.178-.252 1.465-.598l.088-.416c-.2.176-.492.246-.686.246-.275 0-.375-.193-.304-.533L8.93 6.588zM9 4.5a1 1 0 1 1-2 0 1 1 0 0 1 2 0z"/>
            </svg>
            <p style="margin: 0; font-size: 13px;">No official announcements or results have been published yet.</p>
          </div>
        <?php endif; ?>
      </div>
    </div>
  </section>

  <!-- Gallery Section -->
  <section id="gallery" class="gallery">
    <div class="section-header">
      <p class="section-subtitle">Visual Experience</p>
      <h2 class="section-title">Academy Gallery</h2>
    </div>

    <div class="gallery-grid">
      <?php if (!empty($data['gallery'])): ?>
        <?php foreach ($data['gallery'] as $photo): ?>
          <div class="gallery-item">
            <img src="<?= htmlspecialchars(fixImgPath($photo['img'])) ?>" alt="<?= htmlspecialchars($photo['title']) ?>">
            <div class="gallery-overlay">
              <h4 class="gallery-title"><?= htmlspecialchars($photo['title']) ?></h4>
              <p class="gallery-desc"><?= htmlspecialchars($photo['desc']) ?></p>
            </div>
          </div>
        <?php endforeach; ?>
      <?php else: ?>
        <p style="grid-column: 1/-1; text-align: center; color: var(--text-muted);">No gallery photos available.</p>
      <?php endif; ?>
    </div>
  </section>

  <!-- Footer -->
  <footer>
    <div class="footer-logo">
      <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24">
        <circle cx="12" cy="12" r="10" stroke-width="1.5" stroke="currentColor" fill="none"/>
        <circle cx="12" cy="12" r="6"  stroke-width="1.5" stroke="currentColor" fill="none"/>
        <circle cx="12" cy="12" r="2"  fill="currentColor"/>
        <line x1="12" y1="2"  x2="12" y2="5"  stroke="currentColor" stroke-width="1.5"/>
        <line x1="12" y1="19" x2="12" y2="22" stroke="currentColor" stroke-width="1.5"/>
        <line x1="2"  y1="12" x2="5"  y2="12" stroke="currentColor" stroke-width="1.5"/>
        <line x1="19" y1="12" x2="22" y2="12" stroke="currentColor" stroke-width="1.5"/>
      </svg>
      <span>TARGET</span>
    </div>
    <p class="footer-info">
      <strong>Tournament Administration and Registration Gateway for Event Tracking</strong><br>
      Affiliated to the National Rifle Association of India. Providing world class shooting systems, safety disciplines, and professional training models for the youth.
    </p>
    <div class="footer-divider" aria-hidden="true"></div>
    <p class="footer-copyright">&copy; <?php echo date('Y'); ?> TARGET (Tournament Administration and Registration Gateway for Event Tracking). All rights reserved.</p>
  </footer>

  <!-- News Overlay Modal -->
  <div id="newsModal" class="modal" role="dialog" aria-modal="true" aria-labelledby="modalTitle">
    <div class="modal-backdrop" onclick="closeNews()"></div>
    <div class="modal-container">
      <div class="modal-header-img">
        <button class="modal-close" onclick="closeNews()" aria-label="Close modal">
          <svg viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
            <path d="M19 6.41L17.59 5 12 10.59 6.41 5 5 6.41 10.59 12 5 17.59 6.41 19 12 13.41 17.59 19 19 17.59 13.41 12z"/>
          </svg>
        </button>
        <img id="modalImg" src="" alt="News banner">
      </div>
      <div class="modal-content">
        <div id="modalDate" class="modal-date"></div>
        <h3 id="modalTitle" class="modal-title"></h3>
        <div id="modalBody" class="modal-body"></div>
      </div>
    </div>
  </div>

  <!-- Attachment Preview Modal (Open and see only) -->
  <div id="attachmentModal" class="modal attachment-modal-custom" role="dialog" aria-modal="true" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; z-index:999999; align-items:center; justify-content:center; opacity:0; pointer-events:none; transition: opacity 0.25s ease;">
    <div style="position:absolute; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.85); backdrop-filter:blur(8px);" onclick="closeAttachmentModal()"></div>
    <div style="position:relative; width:90%; max-width:1000px; height:85vh; background:#0c0e14; border-radius:12px; border:1px solid rgba(255,255,255,0.15); display:flex; flex-direction:column; overflow:hidden; z-index:2001; box-shadow: 0 10px 40px rgba(0,0,0,0.5);">
      <!-- Modal Header -->
      <div style="padding:14px 20px; border-bottom:1px solid rgba(255,255,255,0.08); display:flex; justify-content:space-between; align-items:center; background:#10121a; flex-wrap:wrap; gap:10px;">
        <h3 id="attachmentTitle" style="font-family:'Rajdhani',sans-serif; font-size:17px; font-weight:700; color:var(--text-primary); margin:0; text-transform:uppercase; letter-spacing:1px; flex:1; min-width:180px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap;">Document Preview</h3>
        <div style="display:flex; align-items:center; gap:10px; flex-shrink:0;">
          <a id="attachmentDirectLink" href="#" target="_blank" style="color:var(--gold-400); font-size:11.5px; font-weight:700; text-decoration:none; text-transform:uppercase; letter-spacing:0.5px; display:inline-flex; align-items:center; gap:5px; padding:6px 12px; border:1px solid rgba(212,175,55,0.3); border-radius:6px; background:rgba(212,175,55,0.08);">
            ↗️ Open Tab
          </a>
          <a id="attachmentDownloadBtn" href="#" download target="_blank" style="color:#ffffff; font-size:11.5px; font-weight:700; text-decoration:none; text-transform:uppercase; letter-spacing:0.5px; display:inline-flex; align-items:center; gap:5px; padding:6px 12px; border:1px solid rgba(255,255,255,0.25); border-radius:6px; background:rgba(255,255,255,0.1);">
            📥 Download
          </a>
          <button onclick="closeAttachmentModal()" style="background:transparent; border:none; color:var(--text-muted); cursor:pointer; font-size:24px; line-height:1; transition:color 0.2s;" onmouseover="this.style.color='#fff'" onmouseout="this.style.color='var(--text-muted)'">&times;</button>
        </div>
      </div>
      <!-- Modal Body (Open only, right-click disabled) -->
      <div id="attachmentBody" style="flex:1; background:#08090d; display:flex; align-items:center; justify-content:center; overflow:hidden; position:relative;" oncontextmenu="return false;">
        <!-- Embedded viewer loaded by JS -->
      </div>
    </div>
  </div>

  <!-- Interactive JavaScript -->
  <script>
    // Detailed content for news modals
    const newsData = <?= json_encode(array_combine(array_column($data['news'] ?? [], 'id'), array_map(function($item) {
        $item['img'] = fixImgPath($item['img']);
        return $item;
    }, $data['news'] ?? [])), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES) ?>;

    // Open/Close News Modals
    function openNews(id) {
      const data = newsData[id];
      if (!data) return;

      document.getElementById('modalImg').src = data.img;
      document.getElementById('modalDate').innerText = data.date;
      document.getElementById('modalTitle').innerText = data.title;
      document.getElementById('modalBody').innerHTML = data.body;

      const modal = document.getElementById('newsModal');
      modal.classList.add('active');
      document.body.style.overflow = 'hidden'; // prevent scrolling underneath
    }

    function closeNews() {
      const modal = document.getElementById('newsModal');
      modal.classList.remove('active');
      document.body.style.overflow = ''; // restore scrolling
    }

    window.triggerAttachmentModal = function(btn) {
      if (!btn) return;
      const filePath = btn.getAttribute('data-filepath');
      const title = btn.getAttribute('data-title');
      window.openAttachmentModal(filePath, title);
    };

    // Attachment Modal Handlers
    window.openAttachmentModal = function(filePath, title) {
      const modal = document.getElementById('attachmentModal');
      const body = document.getElementById('attachmentBody');
      const titleEl = document.getElementById('attachmentTitle');
      const directLink = document.getElementById('attachmentDirectLink');
      const downloadBtn = document.getElementById('attachmentDownloadBtn');
      
      if (!filePath) {
        alert('Attachment file is not available.');
        return;
      }

      titleEl.innerText = title || 'Document Preview';
      body.innerHTML = '';

      let cleanPath = filePath.trim();
      if (cleanPath.startsWith('/')) {
        cleanPath = cleanPath.substring(1);
      }
      
      if (!cleanPath.startsWith('http://') && !cleanPath.startsWith('https://')) {
        const isSubdir = window.location.pathname.includes('/admin/');
        cleanPath = (isSubdir ? '../' : './') + cleanPath;
      }

      if (directLink) directLink.href = cleanPath;
      if (downloadBtn) downloadBtn.href = cleanPath;

      const ext = cleanPath.split('?')[0].split('.').pop().toLowerCase();

      if (ext === 'pdf') {
        body.innerHTML = `<iframe src="${cleanPath}" style="width:100%; height:100%; border:none; background:#ffffff;"></iframe>`;
      } else if (['jpg', 'jpeg', 'png', 'webp', 'gif'].includes(ext)) {
        body.innerHTML = `<img src="${cleanPath}" alt="${title}" style="max-width:100%; max-height:100%; object-fit:contain;">`;
      } else {
        body.innerHTML = `
          <div style="text-align:center; padding:40px; color:#ffffff; font-family:'Rajdhani', sans-serif;">
            <div style="font-size:48px; margin-bottom:16px;">📄</div>
            <h4 style="margin:0 0 10px 0; font-size:20px; font-weight:700;">${title || 'Document'}</h4>
            <p style="color:var(--text-muted); font-size:14px; margin-bottom:24px;">This file format (.${ext.toUpperCase()}) cannot be rendered directly inside the embedded viewer.</p>
            <a href="${cleanPath}" target="_blank" download class="btn-portal" style="padding:12px 24px; background:var(--gold-400); color:#000; font-weight:700; text-decoration:none; border-radius:6px; display:inline-block; text-transform:uppercase;">
              📥 Open / Download Document File
            </a>
          </div>
        `;
      }

      modal.style.display = 'flex';
      modal.style.opacity = '1';
      modal.style.pointerEvents = 'auto';
      modal.classList.add('active');
      document.body.style.overflow = 'hidden';
    };

    window.closeAttachmentModal = function() {
      const modal = document.getElementById('attachmentModal');
      if (!modal) return;
      modal.style.display = 'none';
      modal.style.opacity = '0';
      modal.style.pointerEvents = 'none';
      modal.classList.remove('active');
      document.body.style.overflow = '';
      const body = document.getElementById('attachmentBody');
      if (body) body.innerHTML = '';
    };

    // Escape key to close modals
    window.addEventListener('keydown', (e) => {
      if (e.key === 'Escape') {
        closeNews();
        closeAttachmentModal();
      }
    });

    // Stats counter animation when in viewport
    const countUp = () => {
      const counters = document.querySelectorAll('.stat-num');
      const speed = 150; // lower is slower

      counters.forEach(counter => {
        const updateCount = () => {
          const target = +counter.getAttribute('data-target');
          const count = +counter.innerText;
          const inc = Math.ceil(target / speed);

          if (count < target) {
            counter.innerText = count + inc > target ? target : count + inc;
            setTimeout(updateCount, 15);
          } else {
            counter.innerText = target + '+';
          }
        };
        updateCount();
      });
    };

    // Trigger counter when About section becomes visible
    const observer = new IntersectionObserver((entries, observer) => {
      entries.forEach(entry => {
        if (entry.isIntersecting) {
          countUp();
          observer.unobserve(entry.target);
        }
      });
    }, { threshold: 0.3 });

    const aboutSection = document.querySelector('.about-card-box');
    if (aboutSection) {
      observer.observe(aboutSection);
    }
  </script>

</body>
</html>
