<?php
// Hapus style arranger. Hanya menerima POST dengan token CSRF.
// Bagian dan pola style ini ikut terhapus (CASCADE).

require __DIR__ . '/../includes/bootstrap.php';
$user = require_role('admin');

if (!is_post()) {
    redirect('admin/arranger_styles.php');
}
csrf_verify();

$id = (int) ($_POST['id'] ?? 0);

$stmt = db()->prepare('DELETE FROM arranger_styles WHERE id = ?');
$stmt->execute([$id]);

if ($stmt->rowCount() === 1) {
    flash('success', 'Style sudah dihapus beserta polanya.');
} else {
    flash('error', 'Style tidak ditemukan. Mungkin sudah dihapus.');
}

redirect('admin/arranger_styles.php');
