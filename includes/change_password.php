<?php
// Isi halaman "Ganti password" yang dipakai bersama oleh admin dan superadmin.
// Wajib sebelum include: $user (hasil require_role) dan $passwordPath
// (path halaman pemanggil, misalnya 'admin/password.php').

$errors = [];

if (is_post()) {
    csrf_verify();

    $current = is_string($_POST['current_password'] ?? null) ? $_POST['current_password'] : '';
    $new = is_string($_POST['new_password'] ?? null) ? $_POST['new_password'] : '';
    $confirm = is_string($_POST['new_password_confirm'] ?? null) ? $_POST['new_password_confirm'] : '';

    $stmt = db()->prepare('SELECT password_hash FROM users WHERE id = ?');
    $stmt->execute([(int) $user['id']]);
    $hash = (string) $stmt->fetchColumn();

    if (!password_verify($current, $hash)) {
        $errors['current_password'] = 'Password lama salah.';
    }
    if (strlen($new) < 8 || strlen($new) > 72) {
        $errors['new_password'] = 'Password baru 8 sampai 72 karakter.';
    } elseif ($new !== $confirm) {
        $errors['new_password_confirm'] = 'Konfirmasi password tidak sama.';
    }

    if ($errors === []) {
        $stmt = db()->prepare('UPDATE users SET password_hash = ? WHERE id = ?');
        $stmt->execute([password_hash($new, PASSWORD_DEFAULT), (int) $user['id']]);

        // ID session baru setelah ganti password.
        session_regenerate_id(true);
        flash('success', 'Password sudah diganti. Simpan baik-baik.');
        redirect($passwordPath);
    }
}

$pageTitle = 'Ganti password';
$activeNav = 'password';
require __DIR__ . '/admin_header.php';
?>
<h1 class="wavy">Ganti password</h1>
<p class="muted">Gunakan password yang tidak dipakai di situs lain.<?= $user['role'] === 'superadmin' ? ' Password awal dari file seed wajib diganti sebelum presentasi.' : '' ?></p>

<form method="post" action="<?= e(url($passwordPath)) ?>" class="form form-panel paper" novalidate>
    <?= csrf_field() ?>

    <label for="current_password">Password lama</label>
    <input id="current_password" name="current_password" type="password" maxlength="255" autocomplete="current-password" required>
    <?php if (isset($errors['current_password'])): ?><p class="field-error"><?= e($errors['current_password']) ?></p><?php endif; ?>

    <label for="new_password">Password baru</label>
    <input id="new_password" name="new_password" type="password" maxlength="72" autocomplete="new-password" required>
    <p class="hint">Minimal 8 karakter.</p>
    <?php if (isset($errors['new_password'])): ?><p class="field-error"><?= e($errors['new_password']) ?></p><?php endif; ?>

    <label for="new_password_confirm">Ulangi password baru</label>
    <input id="new_password_confirm" name="new_password_confirm" type="password" maxlength="72" autocomplete="new-password" required>
    <?php if (isset($errors['new_password_confirm'])): ?><p class="field-error"><?= e($errors['new_password_confirm']) ?></p><?php endif; ?>

    <div class="form-actions">
        <button type="submit" class="btn btn-primary">Ganti password</button>
    </div>
</form>
<?php require __DIR__ . '/admin_footer.php'; ?>
