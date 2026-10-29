<?php
// Daftar akun admin (role 'admin'). Superadmin bisa tambah, ubah, hapus.

require __DIR__ . '/../includes/bootstrap.php';
$user = require_role('superadmin');

$admins = db()->query(
    "SELECT id, name, username, email, is_active, last_login_at
     FROM users
     WHERE role = 'admin'
     ORDER BY name"
)->fetchAll();

$pageTitle = 'Kelola admin';
$activeNav = 'admins';
require __DIR__ . '/../includes/admin_header.php';
?>
<div class="page-head">
    <h1 class="wavy">Kelola admin</h1>
    <a class="btn btn-primary" href="<?= e(url('superadmin/admin_form.php')) ?>">Tambah admin</a>
</div>

<?php if ($admins === []): ?>
    <div class="empty-state paper">
        <img src="<?= e(url('assets/img/mascot-wave.svg')) ?>" alt="" width="90" height="105">
        <p>Belum ada admin. Tambahkan satu supaya ada yang mengurus lagu dan instrumen.</p>
    </div>
<?php else: ?>
    <div class="table-wrap">
        <table class="table table-cards">
            <thead>
            <tr>
                <th>Nama</th>
                <th>Username</th>
                <th>Email</th>
                <th>Status</th>
                <th>Login terakhir</th>
                <th><span class="sr-only">Aksi</span></th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($admins as $row): ?>
                <tr>
                    <td data-label="Nama"><strong><?= e($row['name']) ?></strong></td>
                    <td data-label="Username"><?= e($row['username']) ?></td>
                    <td data-label="Email"><?= e($row['email']) ?></td>
                    <td data-label="Status">
                        <?php if ((int) $row['is_active'] === 1): ?>
                            <span class="badge badge-on">Aktif</span>
                        <?php else: ?>
                            <span class="badge badge-off">Nonaktif</span>
                        <?php endif; ?>
                    </td>
                    <td data-label="Login terakhir"><?= e(format_datetime($row['last_login_at'])) ?></td>
                    <td class="row-actions">
                        <a class="btn btn-small" href="<?= e(url('superadmin/admin_form.php?id=' . (int) $row['id'])) ?>">Ubah</a>
                        <form method="post" action="<?= e(url('superadmin/admin_delete.php')) ?>"
                              onsubmit="return confirm('Hapus akun admin ini? Tindakan ini tidak bisa dibatalkan.');">
                            <?= csrf_field() ?>
                            <input type="hidden" name="id" value="<?= (int) $row['id'] ?>">
                            <button type="submit" class="btn btn-small btn-danger">Hapus</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
<?php endif; ?>
<?php require __DIR__ . '/../includes/admin_footer.php'; ?>
