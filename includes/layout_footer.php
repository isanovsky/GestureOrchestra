</main>
<footer class="site-footer">
    <p>GestureOrchestra, Mini Orchestra using finger.</p>
</footer>
<?php foreach ($pageScripts ?? [] as $script): ?>
    <script type="module" src="<?= e(asset($script)) ?>"></script>
<?php endforeach; ?>
</body>
</html>
