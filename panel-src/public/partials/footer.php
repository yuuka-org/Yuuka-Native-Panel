    </main>
    <footer class="app-footer">
      <span>Yuuka Server Panel</span>
      <span class="text-muted">&middot; PHP <?= e(PHP_VERSION) ?> &middot; <?= e(date('Y')) ?></span>
    </footer>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="/assets/js/app.js"></script>
<?php if (!empty($extraBodyHtml)): ?>
<?= $extraBodyHtml ?>
<?php endif; ?>
</body>
</html>
