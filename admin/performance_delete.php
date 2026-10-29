<?php
// Hapus satu nilai permainan. Hanya menerima POST dengan token CSRF.

require __DIR__ . '/../includes/bootstrap.php';
$user = require_role('admin');

if (!is_post()) {
    redirect('admin/performances.php');
}
csrf_verify();

$id = (int) ($_POST['id'] ?? 0);

// Kembali ke halaman dan saringan yang sama. Hanya angka dan nama parameter
// yang dikenal yang diterima, supaya tidak bisa dipakai untuk redirect ke situs lain.
$back = $_POST['back'] ?? '';
$back = is_string($back) && preg_match('/^[a-z_=&0-9]{0,80}$/', $back) ? $back : '';

$stmt = db()->prepare('DELETE FROM performances WHERE id = ?');
$stmt->execute([$id]);

if ($stmt->rowCount() === 1) {
    flash('success', 'Nilai sudah dihapus dari papan skor.');
} else {
    flash('error', 'Nilai tidak ditemukan. Mungkin sudah dihapus.');
}

redirect('admin/performances.php' . ($back === '' ? '' : '?' . $back));
