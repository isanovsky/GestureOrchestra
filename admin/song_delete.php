<?php
// Hapus lagu. Hanya menerima POST dengan token CSRF.
// Part, partitur, room, dan nilai permainan lagu ini ikut terhapus (CASCADE).

require __DIR__ . '/../includes/bootstrap.php';
$user = require_role('admin');

if (!is_post()) {
    redirect('admin/songs.php');
}
csrf_verify();

$id = (int) ($_POST['id'] ?? 0);

try {
    $stmt = db()->prepare('DELETE FROM songs WHERE id = ?');
    $stmt->execute([$id]);

    if ($stmt->rowCount() === 1) {
        flash('success', 'Lagu sudah dihapus beserta partitur dan nilai permainannya.');
    } else {
        flash('error', 'Lagu tidak ditemukan. Mungkin sudah dihapus.');
    }
} catch (PDOException $e) {
    if (db_error_number($e) === 1451) {
        flash('error', 'Lagu ini masih dipakai data lain, jadi belum bisa dihapus. Sembunyikan saja.');
    } else {
        throw $e;
    }
}

redirect('admin/songs.php');
