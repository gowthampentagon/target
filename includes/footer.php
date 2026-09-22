<?php
/**
 * includes/footer.php
 * Shared page footer – closes tags, injects JS
 */
?>

<?php
  require_once dirname(__DIR__) . '/config/db.php';
  $footerActiveChampionship = getActiveChampionship();
?>
  <!-- Footer -->
  <footer class="site-footer">
    <p style="font-weight:700; color:var(--gold-400, #c9a84c); margin-bottom:2px;">&copy; <?= date('Y') ?> TARGET</p>
    <p style="font-size:11px; opacity:0.85; margin:3px 0; line-height:1.4;">Tournament Administration and Registration Gateway for Event Tracking &nbsp;&middot;&nbsp; All Rights Reserved</p>
    <p style="margin-top:3px; font-size:11.5px; opacity:0.85; line-height:1.4;"><?= htmlspecialchars($footerActiveChampionship['championship_name'] ?? '51st Tamil Nadu State Shooting Championship') ?> &nbsp;&middot;&nbsp; Official Portal</p>
  </footer>

</div><!-- /.page-wrapper -->

<!-- Main Script -->
<script src="js/main.js?v=<?= time() ?>"></script>
</body>
</html>
