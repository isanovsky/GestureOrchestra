<?php
// Landing page publik: konsep, tiga instrumen, cara main, dan tombol main.

require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/content.php';
require __DIR__ . '/includes/icons.php';

$instruments = fetch_active_instruments();
$songs = fetch_published_songs();

// Rotasi kartu instrumen sengaja dibuat berbeda-beda supaya terlihat ditempel tangan.
$tilts = ['-2deg', '1.5deg', '-1deg', '2deg'];

$pageTitle = 'Beranda';
$activeNav = 'home';
$bodyClass = 'page-home';
require __DIR__ . '/includes/layout_header.php';
?>
<section class="poster">
    <div class="poster-text">
        <p class="label-tag">Orkestra mini, 1 sampai 4 pemain</p>
        <h1 class="poster-title">Angkat jari, <span class="wavy">panggungnya bunyi.</span></h1>
        <p class="poster-lead">
            Kamera laptop membaca berapa jari yang kamu angkat. Tiga jari di depan piano?
            Itu Mi. Lima jari di depan drum? Crash! Main sendiri untuk latihan,
            atau ajak sampai tiga teman dan mainkan satu lagu bareng dari perangkat masing-masing.
        </p>
        <div class="poster-actions">
            <a class="btn btn-primary btn-big" href="<?= e(url('play.php')) ?>">Main Solo</a>
            <a class="btn btn-big" href="<?= e(url('room.php')) ?>">Main Bersama</a>
        </div>
        <p class="muted small">Tidak ada kamera? Tombol 1 sampai 9 di keyboard juga bisa.</p>
    </div>
    <div class="poster-art" aria-hidden="true">
        <img class="poster-mascot" src="<?= e(url('assets/img/mascot-wave.svg')) ?>" alt="" width="220" height="257">
        <?php foreach (array_slice($instruments, 0, 3) as $i => $instrument): ?>
            <span class="poster-icon poster-icon-<?= $i + 1 ?>"><?= instrument_icon($instrument['type'], $instrument['color']) ?></span>
        <?php endforeach; ?>
        <img class="poster-note poster-note-1" src="<?= e(url('assets/img/logo-note.svg')) ?>" alt="" width="40" height="40">
        <img class="poster-note poster-note-2" src="<?= e(url('assets/img/logo-note.svg')) ?>" alt="" width="40" height="40">
    </div>
</section>

<section class="home-section">
    <h2 class="section-title"><?= count($instruments) ?> instrumen, satu panggung</h2>
    <?php if ($instruments === []): ?>
        <p class="empty">Belum ada instrumen aktif. Admin sedang menyetemnya.</p>
    <?php else: ?>
        <div class="sticker-row">
            <?php foreach ($instruments as $i => $instrument): ?>
                <article class="sticker" style="--tilt: <?= $tilts[$i % count($tilts)] ?>; --inst-color: <?= e($instrument['color']) ?>">
                    <span class="sticker-icon"><?= instrument_icon($instrument['type'], $instrument['color']) ?></span>
                    <h3><?= e($instrument['name']) ?></h3>
                    <p><?= e($instrument['description'] ?? '') ?></p>
                    <?php if ($instrument['gestures'] !== []): ?>
                        <?php // Contoh berbeda per kartu: gestur ke-3, ke-5, ke-7, dan seterusnya. ?>
                        <?php $example = $instrument['gestures'][min($i * 2 + 2, count($instrument['gestures']) - 1)]; ?>
                        <p class="sticker-example">"<?= e(gesture_sentence($example)) ?>"</p>
                    <?php endif; ?>
                </article>
            <?php endforeach; ?>
        </div>
        <p><a href="<?= e(url('instruments.php')) ?>">Lihat semua pemetaan jari</a></p>
    <?php endif; ?>
</section>

<section class="home-section how">
    <h2 class="section-title">Cara main</h2>
    <ol class="steps">
        <li>
            <span class="step-num">1</span>
            <strong>Pilih instrumen dan lagu.</strong>
            Mau bebas tanpa lagu juga boleh.
        </li>
        <li>
            <span class="step-num">2</span>
            <strong>Izinkan kamera.</strong>
            Duduk sekitar satu lengan dari laptop, tangan kelihatan jelas dan ruangan cukup terang.
        </li>
        <li>
            <span class="step-num">3</span>
            <strong>Ikuti lajur nada.</strong>
            Angka di balok = jumlah jari. Angkat tepat saat balok menyentuh garis.
        </li>
        <li>
            <span class="step-num">4</span>
            <strong>Pamer di papan skor.</strong>
            Server yang menghitung nilaimu, jadi tidak ada yang bisa curang lewat browser.
        </li>
    </ol>
</section>

<section class="home-section">
    <h2 class="section-title">Lagu yang bisa dimainkan</h2>
    <?php if ($songs === []): ?>
        <div class="empty-state paper">
            <img src="<?= e(url('assets/img/mascot-oops.svg')) ?>" alt="" width="90" height="105">
            <p>Belum ada lagu yang tayang. Sambil menunggu, coba mode bebas dulu.</p>
        </div>
    <?php else: ?>
        <ul class="song-strip">
            <?php foreach (array_slice($songs, 0, 4) as $song): ?>
                <li>
                    <a href="<?= e(url('play.php?song=' . (int) $song['id'])) ?>">
                        <strong><?= e($song['title']) ?></strong>
                        <span class="muted"><?= e($song['bpm']) ?> bpm, <?= e(format_duration($song['length_seconds'])) ?></span>
                    </a>
                </li>
            <?php endforeach; ?>
        </ul>
        <p><a href="<?= e(url('songs.php')) ?>">Semua lagu</a></p>
    <?php endif; ?>
</section>
<?php require __DIR__ . '/includes/layout_footer.php'; ?>
