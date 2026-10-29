<?php
// Dashboard superadmin: ringkasan akun admin dan isi situs.

require __DIR__ . '/../includes/bootstrap.php';
$user = require_role('superadmin');

$stats = db()->query(
    "SELECT
        (SELECT COUNT(*) FROM users WHERE role = 'admin')                  AS admins_total,
        (SELECT COUNT(*) FROM users WHERE role = 'admin' AND is_active = 1) AS admins_active,
        (SELECT COUNT(*) FROM songs)                                       AS songs_total,
        (SELECT COUNT(*) FROM performances WHERE played_at >= CURDATE())   AS plays_today"
)->fetch();

// Lima admin yang terakhir login, untuk memantau aktivitas.
$recentLogins = db()->query(
    "SELECT name, username, last_login_at
     FROM users
     WHERE role = 'admin' AND last_login_at IS NOT NULL
     ORDER BY last_login_at DESC
     LIMIT 5"
)->fetchAll();

$pageTitle = 'Ringkasan';
$activeNav = 'dashboard';
require __DIR__ . '/../includes/admin_header.php';
?>
<h1 class="wavy">Halo, <?= e($user['name']) ?>.</h1>
<p class="muted">Kamu memegang kunci akun admin.</p>

<div class="stat-grid">
    <div class="stat stat-tomato">
        <span class="stat-num"><?= e($stats['admins_total']) ?></span>
        <span class="stat-label">akun admin</span>
    </div>
    <div class="stat stat-teal">
        <span class="stat-num"><?= e($stats['admins_active']) ?></span>
        <span class="stat-label">admin aktif</span>
    </div>
    <div class="stat stat-mustard">
        <span class="stat-num"><?= e($stats['songs_total']) ?></span>
        <span class="stat-label">lagu di database</span>
    </div>
    <div class="stat">
        <span class="stat-num"><?= e($stats['plays_today']) ?></span>
        <span class="stat-label">permainan hari ini</span>
    </div>
</div>

<section class="panel">
    <div class="panel-head">
        <h2>Login admin terakhir</h2>
        <a class="btn btn-small" href="<?= e(url('superadmin/admins.php')) ?>">Kelola admin</a>
    </div>
    <?php if ($recentLogins === []): ?>
        <p class="empty">Belum ada admin yang pernah masuk.</p>
    <?php else: ?>
        <ul class="plain-list">
            <?php foreach ($recentLogins as $row): ?>
                <li><strong><?= e($row['name']) ?></strong> <span class="muted">(<?= e($row['username']) ?>)</span>, <?= e(format_datetime($row['last_login_at'])) ?></li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
</section>
<?php require __DIR__ . '/../includes/admin_footer.php'; ?>
