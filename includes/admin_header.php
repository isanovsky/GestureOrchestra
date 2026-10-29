<?php
// Kepala halaman dashboard (admin dan superadmin).
// Wajib: halaman sudah memanggil require_role(...) dan menyimpan hasilnya di $user.
// Opsional: $pageTitle, $activeNav (kunci menu yang sedang aktif).

$pageTitle = $pageTitle ?? 'Dashboard';
$activeNav = $activeNav ?? '';

// Menu samping per role. Kunci dipakai untuk menandai menu aktif.
if ($user['role'] === 'superadmin') {
    $navItems = [
        'dashboard' => ['Ringkasan', 'superadmin/index.php'],
        'admins'    => ['Kelola admin', 'superadmin/admins.php'],
        'password'  => ['Ganti password', 'superadmin/password.php'],
    ];
} else {
    $navItems = [
        'dashboard'    => ['Ringkasan', 'admin/index.php'],
        'instruments'  => ['Instrumen, bunyi, gestur', 'admin/instruments.php'],
        'songs'        => ['Lagu dan partitur', 'admin/songs.php'],
        'arranger'     => ['Style arranger', 'admin/arranger_styles.php'],
        'performances' => ['Nilai permainan', 'admin/performances.php'],
        'password'     => ['Ganti password', 'admin/password.php'],
    ];
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title><?= e($pageTitle) ?> - Dashboard GestureOrchestra</title>
    <link rel="icon" href="<?= e(url('assets/img/favicon.svg')) ?>" type="image/svg+xml">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Atkinson+Hyperlegible:ital,wght@0,400;0,700;1,400&family=Fraunces:opsz,wght@9..144,600;9..144,800&display=swap">
    <link rel="stylesheet" href="<?= e(asset('assets/css/app.css')) ?>">
</head>
<body class="admin">
<a class="skip-link" href="#main">Langsung ke isi</a>
<div class="admin-shell">
    <aside class="admin-side">
        <a class="brand" href="<?= e(url('index.php')) ?>">
            <img src="<?= e(url('assets/img/logo-note.svg')) ?>" alt="" width="30" height="30">
            <span>Gesture<em>Orchestra</em></span>
        </a>
        <p class="role-tag"><?= $user['role'] === 'superadmin' ? 'Superadmin' : 'Admin konten' ?></p>
        <nav class="admin-nav" aria-label="Menu dashboard">
            <?php foreach ($navItems as $key => [$label, $path]): ?>
                <a href="<?= e(url($path)) ?>"<?= $key === $activeNav ? ' class="is-active" aria-current="page"' : '' ?>><?= e($label) ?></a>
            <?php endforeach; ?>
        </nav>
        <div class="admin-me">
            <p>Masuk sebagai <strong><?= e($user['name']) ?></strong></p>
            <form method="post" action="<?= e(url('logout.php')) ?>">
                <?= csrf_field() ?>
                <button type="submit" class="btn btn-small btn-ghost">Keluar</button>
            </form>
        </div>
    </aside>
    <main id="main" class="admin-main">
        <?php foreach (take_flashes() as $flash): ?>
            <p class="alert alert-<?= e($flash['type']) ?>" role="status"><?= e($flash['message']) ?></p>
        <?php endforeach; ?>
