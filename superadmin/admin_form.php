<?php
// Form tambah admin (tanpa ?id) dan ubah admin (?id=angka).
// Password wajib saat tambah, opsional saat ubah (kosong = tidak diganti).

require __DIR__ . '/../includes/bootstrap.php';
$user = require_role('superadmin');

$id = (int) ($_GET['id'] ?? 0);
$isEdit = $id > 0;

// Nilai awal form.
$admin = ['name' => '', 'username' => '', 'email' => '', 'is_active' => 1];

if ($isEdit) {
    // Akun sendiri tidak diubah lewat form ini, supaya superadmin tidak
    // bisa menonaktifkan dirinya sendiri.
    if ($id === (int) $user['id']) {
        render_error_page(403, 'Ini akunmu sendiri', 'Untuk akunmu sendiri, gunakan menu Ganti password.');
    }

    // Hanya akun ber-role admin yang boleh diubah di sini.
    $stmt = db()->prepare("SELECT id, name, username, email, is_active FROM users WHERE id = ? AND role = 'admin'");
    $stmt->execute([$id]);
    $found = $stmt->fetch();
    if ($found === false) {
        render_error_page(404, 'Admin tidak ditemukan', 'Mungkin akun ini sudah dihapus.');
    }
    $admin = $found;
}

$errors = [];

if (is_post()) {
    csrf_verify();

    $admin['name'] = post_str('name');
    $admin['username'] = post_str('username');
    $admin['email'] = post_str('email');
    $admin['is_active'] = isset($_POST['is_active']) ? 1 : 0;
    // Password tidak di-trim: spasi di awal/akhir dianggap bagian password.
    $password = is_string($_POST['password'] ?? null) ? $_POST['password'] : '';
    $passwordConfirm = is_string($_POST['password_confirm'] ?? null) ? $_POST['password_confirm'] : '';

    // --- Validasi ---
    if ($admin['name'] === '' || mb_strlen($admin['name']) > 100) {
        $errors['name'] = 'Nama wajib diisi, maksimal 100 karakter.';
    }
    if (!preg_match('/^[A-Za-z0-9_.]{3,50}$/', $admin['username'])) {
        $errors['username'] = 'Username 3 sampai 50 karakter: huruf, angka, titik, atau garis bawah.';
    }
    if (!filter_var($admin['email'], FILTER_VALIDATE_EMAIL) || strlen($admin['email']) > 150) {
        $errors['email'] = 'Alamat email tidak valid.';
    }

    $passwordRequired = !$isEdit;
    if ($passwordRequired || $password !== '') {
        // Batas 72 byte karena password_hash() (bcrypt) mengabaikan sisanya.
        if (strlen($password) < 8 || strlen($password) > 72) {
            $errors['password'] = 'Password 8 sampai 72 karakter.';
        } elseif ($password !== $passwordConfirm) {
            $errors['password_confirm'] = 'Konfirmasi password tidak sama.';
        }
    }

    // Username dan email harus unik di seluruh tabel users.
    if (!isset($errors['username'])) {
        $stmt = db()->prepare('SELECT COUNT(*) FROM users WHERE username = ? AND id <> ?');
        $stmt->execute([$admin['username'], $id]);
        if ((int) $stmt->fetchColumn() > 0) {
            $errors['username'] = 'Username ini sudah dipakai.';
        }
    }
    if (!isset($errors['email'])) {
        $stmt = db()->prepare('SELECT COUNT(*) FROM users WHERE email = ? AND id <> ?');
        $stmt->execute([$admin['email'], $id]);
        if ((int) $stmt->fetchColumn() > 0) {
            $errors['email'] = 'Email ini sudah dipakai.';
        }
    }

    // --- Simpan ---
    if ($errors === []) {
        if ($isEdit) {
            $stmt = db()->prepare(
                "UPDATE users SET name = ?, username = ?, email = ?, is_active = ?
                 WHERE id = ? AND role = 'admin'"
            );
            $stmt->execute([$admin['name'], $admin['username'], $admin['email'], $admin['is_active'], $id]);

            if ($password !== '') {
                $stmt = db()->prepare("UPDATE users SET password_hash = ? WHERE id = ? AND role = 'admin'");
                $stmt->execute([password_hash($password, PASSWORD_DEFAULT), $id]);
            }
            flash('success', 'Data admin ' . $admin['username'] . ' tersimpan.');
        } else {
            $stmt = db()->prepare(
                "INSERT INTO users (name, username, email, password_hash, role, is_active)
                 VALUES (?, ?, ?, ?, 'admin', ?)"
            );
            $stmt->execute([
                $admin['name'],
                $admin['username'],
                $admin['email'],
                password_hash($password, PASSWORD_DEFAULT),
                $admin['is_active'],
            ]);
            flash('success', 'Admin ' . $admin['username'] . ' sudah ditambahkan.');
        }
        redirect('superadmin/admins.php');
    }
}

$pageTitle = $isEdit ? 'Ubah admin' : 'Tambah admin';
$activeNav = 'admins';
$formAction = url('superadmin/admin_form.php' . ($isEdit ? '?id=' . $id : ''));
require __DIR__ . '/../includes/admin_header.php';
?>
<p class="crumb"><a href="<?= e(url('superadmin/admins.php')) ?>">Kelola admin</a> / <?= e($pageTitle) ?></p>
<h1 class="wavy"><?= e($pageTitle) ?></h1>

<?php if ($errors !== []): ?>
    <p class="alert alert-error" role="alert">Ada isian yang perlu dibetulkan. Lihat tanda merah di bawah.</p>
<?php endif; ?>

<form method="post" action="<?= e($formAction) ?>" class="form form-panel paper" novalidate>
    <?= csrf_field() ?>

    <label for="name">Nama lengkap</label>
    <input id="name" name="name" type="text" maxlength="100" required value="<?= e($admin['name']) ?>">
    <?php if (isset($errors['name'])): ?><p class="field-error"><?= e($errors['name']) ?></p><?php endif; ?>

    <label for="username">Username</label>
    <input id="username" name="username" type="text" maxlength="50" required autocomplete="off" value="<?= e($admin['username']) ?>">
    <?php if (isset($errors['username'])): ?><p class="field-error"><?= e($errors['username']) ?></p><?php endif; ?>

    <label for="email">Email</label>
    <input id="email" name="email" type="email" maxlength="150" required value="<?= e($admin['email']) ?>">
    <?php if (isset($errors['email'])): ?><p class="field-error"><?= e($errors['email']) ?></p><?php endif; ?>

    <label for="password">Password<?= $isEdit ? ' baru' : '' ?></label>
    <input id="password" name="password" type="password" maxlength="72" autocomplete="new-password" <?= $isEdit ? '' : 'required' ?>>
    <p class="hint"><?= $isEdit ? 'Kosongkan bila password tidak diganti.' : 'Minimal 8 karakter.' ?></p>
    <?php if (isset($errors['password'])): ?><p class="field-error"><?= e($errors['password']) ?></p><?php endif; ?>

    <label for="password_confirm">Ulangi password</label>
    <input id="password_confirm" name="password_confirm" type="password" maxlength="72" autocomplete="new-password">
    <?php if (isset($errors['password_confirm'])): ?><p class="field-error"><?= e($errors['password_confirm']) ?></p><?php endif; ?>

    <label class="check">
        <input type="checkbox" name="is_active" value="1" <?= (int) $admin['is_active'] === 1 ? 'checked' : '' ?>>
        Akun aktif (boleh login)
    </label>

    <div class="form-actions">
        <button type="submit" class="btn btn-primary">Simpan</button>
        <a class="btn btn-ghost" href="<?= e(url('superadmin/admins.php')) ?>">Batal</a>
    </div>
</form>
<?php require __DIR__ . '/../includes/admin_footer.php'; ?>
