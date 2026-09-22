<?php
/**
 * index.php - Saragarhi Shooting Academy Dashboard / Landing Page
 */
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$dataPath = __DIR__ . '/data.json';
if (file_exists($dataPath)) {
    $data = json_decode(file_get_contents($dataPath), true);
} else {
    // Default initial data
    $data = [
        'news' => [
            [
                'id' => 1,
                'title' => '51st TN State Shooting Championship Portal Open',
                'date' => 'Jul 2026',
                'img' => '../images/gallery/ssa_gallery_1.jpg',
                'excerpt' => 'Online registration is officially active! Shooters can log in to their profile portal to verify configurations, register for matches, select relays, and pay fees.',
                'body' => '<p>The online registrations for the highly anticipated <strong>51st Tamil Nadu State Shooting Championship</strong> have officially commenced.</p><p>Participating competitors must sign in to the registration portal using their verified credentials. Once logged in, you can verify your profile documents, choose matches under Pistol/Rifle categories, allocate preferred target lanes, configure relays, and complete the registration fee payment online.</p><p>Please note that entries are strictly online-only, and registrations will close on the scheduled deadline. Make sure to complete your profile verification early to avoid delays.</p>'
            ],
            [
                'id' => 2,
                'title' => 'Summer Elite Training Camp Concludes with Record Turnout',
                'date' => 'Jun 2026',
                'img' => '../images/gallery/ssa_action_1.jpg',
                'excerpt' => 'Our summer elite diagnostics and shooting camps wrapped up this week. Over 80 junior athletes participated under the strict guidance of national instructors.',
                'body' => '<p>Saragarhi Shooting Academy is thrilled to declare another highly successful completion of our annual <strong>Summer Elite Training Camp</strong>.</p><p>The program, which ran for three weeks, welcomed over 80 junior athletes from across the state. Under the rigorous coaching of ISSF-certified coaches and sports psychologists, the participants received direct instruction on posture alignment, breathing control, match preparation, and advanced electronic scoring feedback analysis.</p><p>A mock tournament held on the final day demonstrated exceptional scores, with several junior shooters matching national qualification benchmarks.</p>'
            ],
            [
                'id' => 3,
                'title' => 'New 10m Air Range Laser Target Systems Active',
                'date' => 'May 2026',
                'img' => '../images/gallery/ssa_range_2.jpg',
                'excerpt' => 'Upgraded range targets feature sub-millimeter diagnostics accuracy. Immediate shot detection monitors are now fully configured for all shooters.',
                'body' => '<p>Continuing our dedication to integrating state-of-the-art training aids, Saragarhi Shooting Academy has successfully configured and activated new <strong>Electronic Scoring Targets (EST)</strong> across all 10m indoor shooting lanes.</p><p>The newly installed lasers provide sub-millimeter acoustic detection feedback, translating into instant shot placement mapping displayed on individual shooter lane monitors. The software diagnostics track shot group consistency and timing patterns to help coaches analyze trigger follow-through with microscopic precision.</p><p>These ranges are fully active starting this week and are calibrated exactly to match ISSF competition standards.</p>'
            ]
        ],
        'gallery' => [
            [
                'img' => '../images/gallery/ssa_range_1.jpg',
                'title' => '50m Rifle Range',
                'desc' => 'Fully equipped electronic targets for match practice.'
            ],
            [
                'img' => '../images/gallery/ssa_gallery_3.jpg',
                'title' => '10m Air Pistol Arena',
                'desc' => 'Climate-controlled indoor arena with high accuracy systems.'
            ],
            [
                'img' => '../images/gallery/ssa_action_2.jpg',
                'title' => 'Precision Practice',
                'desc' => 'Real-time biomechanics feedback tracking shooter trigger weight.'
            ]
        ],
        'events' => [
            [
                'id' => 1,
                'title' => '51st TN State Shooting Championship',
                'date' => 'July 15 - July 22, 2026',
                'venue' => 'Guru Nanak College Shooting Range, Chennai',
                'desc' => 'The state\'s premier shooting event featuring Rifle, Pistol, and Para categories. Register via the portal to secure your relay slot.'
            ],
            [
                'id' => 2,
                'title' => 'National Selection Trials',
                'date' => 'August 5 - August 12, 2026',
                'venue' => 'Saragarhi Academy Main Range',
                'desc' => 'Elite national selection trials for ISSF category shooters. Biometric and biomechanics feedback tracking will be active on all lanes.'
            ],
            [
                'id' => 3,
                'title' => 'Monthly Club Pistol Tournament',
                'date' => 'July 28, 2026',
                'venue' => '10m Air Pistol Arena',
                'desc' => 'Local monthly championship for academy members to test speed, focus, and precision. Walk-in entries permitted for members.'
            ]
        ]
    ];
    file_put_contents($dataPath, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <meta name="description" content="Welcome to Saragarhi Shooting Academy - The premier academy for Olympic precision shooting sports. Learn about training, ranges, and news.">
  <meta name="robots" content="index, follow">
  <title>Saragarhi Shooting Academy | Official Dashboard</title>

  <!-- Google Fonts -->
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Cinzel:wght@400;600;700&family=Inter:wght@300;400;500;600;700&family=Rajdhani:wght@400;500;600;700&display=swap" rel="stylesheet">

  <!-- Local Custom Stylesheet -->
  <link rel="stylesheet" href="style.css">
</head>
<body>

  <!-- Cinematic Background Layer -->
  <div class="bg-cinematic" aria-hidden="true"></div>
  <div class="bg-overlay" aria-hidden="true"></div>

  <!-- Navigation Bar -->
  <nav class="navbar">
    <div class="nav-brand">
      <img src="../images/logo.png" alt="TARGET Logo" style="width:34px; height:34px; object-fit:contain; margin-right:8px;">
      <span class="nav-title">TARGET</span>
    </div>
    <ul class="nav-links">
      <li><a href="#hero">Home</a></li>
      <li><a href="#about">About</a></li>
      <li><a href="#news">News</a></li>
      <li><a href="#events">Events</a></li>
      <li><a href="#gallery">Gallery</a></li>
    </ul>
    <a href="../index.php" class="btn-portal">Portal Login</a>
  </nav>

  <!-- Hero Section -->
  <section id="hero" class="hero">
    <div class="hero-emblem" aria-hidden="true">
      <div class="hero-emblem-inner">
        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24">
          <circle cx="12" cy="12" r="10" stroke-width="1.5" stroke="currentColor" fill="none"/>
          <circle cx="12" cy="12" r="6"  stroke-width="1.5" stroke="currentColor" fill="none"/>
          <circle cx="12" cy="12" r="2"  fill="currentColor"/>
          <line x1="12" y1="2"  x2="12" y2="5"  stroke="currentColor" stroke-width="1.5"/>
          <line x1="12" y1="19" x2="12" y2="22" stroke="currentColor" stroke-width="1.5"/>
          <line x1="2"  y1="12" x2="5"  y2="12" stroke="currentColor" stroke-width="1.5"/>
          <line x1="19" y1="12" x2="22" y2="12" stroke="currentColor" stroke-width="1.5"/>
        </svg>
      </div>
    </div>
    <p class="hero-subtitle">Precision &bull; Discipline &bull; Excellence</p>
    <h1 class="hero-title">Saragarhi Shooting Academy</h1>
    <p class="hero-description">
      Empowering athletes with state-of-the-art infrastructure, advanced diagnostics, and olympic-grade coaching methodologies. Elevating precision shooting standard across the nation.
    </p>
    <div class="hero-actions">
      <a href="../index.php" class="btn-primary">
        Go to Portal
        <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="currentColor" viewBox="0 0 16 16">
          <path fill-rule="evenodd" d="M1 8a.5.5 0 0 1 .5-.5h11.793l-3.147-3.146a.5.5 0 0 1 .708-.708l4 4a.5.5 0 0 1 0 .708l-4 4a.5.5 0 0 1-.708-.708L13.293 8.5H1.5A.5.5 0 0 1 1 8z"/>
        </svg>
      </a>
      <a href="#about" class="btn-secondary">Explore Academy</a>
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
          Founded on the core principles of concentration, posture perfection, and dedicated discipline, <strong>Saragarhi Shooting Academy (SSA)</strong> stands as a premium center of athletic coaching. We offer specialized training formats for Pistol, Rifle, and Trap disciplines in various distance classes.
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
              <img class="news-img" src="<?= htmlspecialchars($item['img']) ?>" alt="<?= htmlspecialchars($item['title']) ?>">
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
            <img src="<?= htmlspecialchars($photo['img']) ?>" alt="<?= htmlspecialchars($photo['title']) ?>">
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
      <span>Saragarhi Shooting Academy</span>
    </div>
    <p class="footer-info">
      Affiliated to the National Rifle Association of India. Providing world class shooting systems, safety disciplines, and professional training models for the youth.
    </p>
    <div class="footer-divider" aria-hidden="true"></div>
    <p class="footer-copyright">&copy; <?php echo date('Y'); ?> Saragarhi Shooting Academy. All rights reserved.</p>
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

  <!-- Interactive JavaScript -->
  <script>
    // Detailed content for news modals
    const newsData = <?= json_encode(array_column($data['news'] ?? [], null, 'id'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES) ?>;

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

    // Escape key to close modal
    window.addEventListener('keydown', (e) => {
      if (e.key === 'Escape') {
        closeNews();
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
