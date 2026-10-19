    </div>
  </div>
</div>
<div class="sh-toasts" id="sh-toasts" aria-live="polite"></div>
<script src="<?= e(sh_asset('assets/js/app.js')) ?>" defer></script>
<?php foreach (($adminScripts ?? []) as $src): ?>
<script src="<?= e(sh_asset((string)$src)) ?>" defer></script>
<?php endforeach; ?>
</body>
</html>
