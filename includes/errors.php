<?php
// Halaman error yang rapi (403, 404, 500, dan lain-lain).
// Dipanggil lalu langsung menghentikan eksekusi script.

function render_error_page(int $code, string $title, string $message, ?string $detail = null): never
{
    // Buang output halaman yang mungkin sudah setengah jadi.
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    if (!headers_sent()) {
        http_response_code($code);
    }

    $pageTitle = $title;
    $bodyClass = 'page-error';
    require __DIR__ . '/layout_header.php';
    ?>
    <section class="error-box">
        <img class="error-mascot" src="<?= e(url('assets/img/mascot-oops.svg')) ?>" alt="" width="160" height="186">
        <div>
            <p class="error-code">Kode <?= e($code) ?></p>
            <h1 class="wavy"><?= e($title) ?></h1>
            <p><?= e($message) ?></p>
            <?php if ($detail !== null): ?>
                <pre class="error-detail"><?= e($detail) ?></pre>
            <?php endif; ?>
            <p><a class="btn" href="<?= e(url('index.php')) ?>">Kembali ke beranda</a></p>
        </div>
    </section>
    <?php
    require __DIR__ . '/layout_footer.php';
    exit;
}
