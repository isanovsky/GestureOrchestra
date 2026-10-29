<?php
// Hapus akun admin. Hanya menerima POST dengan token CSRF.

require __DIR__ . '/../includes/bootstrap.php';
$user = require_role('superadmin');

if (!is_post()) {
    redirect('superadmin/admins.php');
}
csrf_verify();

$id = (int) ($_POST['id'] ?? 0);

if ($id === (int) $user['id']) {
    flash('error', 'Kamu tidak bisa menghapus akunmu sendiri.');
    redirect('superadmin/admins.php');
}

try {
    // role = 'admin' memastikan akun superadmin tidak bisa dihapus lewat sini.
    $stmt = db()->prepare("DELETE FROM users WHERE id = ? AND role = 'admin'");
    $stmt->execute([$id]);

    if ($stmt->rowCount() === 1) {
        flash('success', 'Akun admin sudah dihapus. Lagu buatannya tetap ada.');
    } else {
        flash('error', 'Akun admin tidak ditemukan. Mungkin sudah dihapus.');
    }
} catch (PDOException $e) {
    // 23000 = pelanggaran constraint (misalnya foreign key).
    if ($e->getCode() === '23000') {
        flash('error', 'Akun ini masih dipakai data lain, jadi belum bisa dihapus. Nonaktifkan saja.');
    } else {
        throw $e;
    }
}

redirect('superadmin/admins.php');
