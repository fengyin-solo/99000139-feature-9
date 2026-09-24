</main>
<footer class="site-footer">
    <div class="container">
        <p>&copy; <?= date('Y') ?> 社区便民留言板 - 让社区生活更美好</p>
    </div>
</footer>
<script src="<?= $jsPath ?? 'assets/js/main.js' ?>"></script>
<?php if (!empty($extraJs)): ?>
<script src="<?= $extraJs ?>"></script>
<?php endif; ?>
</body>
</html>
