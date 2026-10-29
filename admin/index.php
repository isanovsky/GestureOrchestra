<?php
// Dashboard admin konten: ringkasan angka.

require __DIR__ . '/../includes/bootstrap.php';
$user = require_role('admin');

// Satu query, beberapa angka sekaligus. Setiap subquery menghitung satu hal.
$stats = db()->query(
    "SELECT
        (SELECT COUNT(*) FROM songs)                               AS songs_total,
        (SELECT COUNT(*) FROM songs WHERE is_published = 1)        AS songs_published,
        (SELECT COUNT(*) FROM instruments WHERE is_active = 1)     AS instruments_active,
        (SELECT COUNT(*) FROM sounds)                              AS sounds_total,
        (SELECT COUNT(*) FROM rooms
            WHERE status <> 'finished' AND expires_at > NOW())     AS rooms_active,
        (SELECT COUNT(*) FROM performances
            WHERE played_at >= CURDATE())                          AS plays_today"
)->fetch();

$pageTitle = 'Ringkasan';
$activeNav = 'dashboard';
require __DIR__ . '/../includes/admin_header.php';
?>
<h1 class="wavy">Halo, <?= e($user['name']) ?>.</h1>
<p class="muted">Ini isi panggung saat ini.</p>

<div class="stat-grid">
    <div class="stat stat-tomato">
        <span class="stat-num"><?= e($stats['songs_total']) ?></span>
        <span class="stat-label">lagu, <?= e($stats['songs_published']) ?> sudah tayang</span>
    </div>
    <div class="stat stat-teal">
        <span class="stat-num"><?= e($stats['instruments_active']) ?></span>
        <span class="stat-label">instrumen aktif</span>
    </div>
    <div class="stat stat-mustard">
        <span class="stat-num"><?= e($stats['sounds_total']) ?></span>
        <span class="stat-label">bunyi terdaftar</span>
    </div>
    <div class="stat">
        <span class="stat-num"><?= e($stats['rooms_active']) ?></span>
        <span class="stat-label">room sedang buka</span>
    </div>
    <div class="stat">
        <span class="stat-num"><?= e($stats['plays_today']) ?></span>
        <span class="stat-label">permainan hari ini</span>
    </div>
</div>

<section class="panel">
    <h2>Mau mengerjakan apa?</h2>
    <ul class="plain-list">
        <li><a href="<?= e(url('admin/songs.php')) ?>">Tulis atau ubah partitur lagu</a></li>
        <li><a href="<?= e(url('admin/instruments.php')) ?>">Atur bunyi dan pemetaan jari per instrumen</a></li>
        <li><a href="<?= e(url('admin/performances.php')) ?>">Periksa nilai permainan yang tidak wajar</a></li>
    </ul>
</section>
<?php require __DIR__ . '/../includes/admin_footer.php'; ?>
