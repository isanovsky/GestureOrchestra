<?php
// Hapus instrumen. Hanya menerima POST dengan token CSRF.
//
// Di database, menghapus instrumen akan ikut menghapus (CASCADE) part lagu
// dan nilai permainan instrumen itu. Supaya data penting tidak hilang tanpa
// sengaja, penghapusan ditolak bila instrumen sudah dipakai.

require __DIR__ . '/../includes/bootstrap.php';
$user = require_role('admin');

if (!is_post()) {
    redirect('admin/instruments.php');
}
csrf_verify();

$id = (int) ($_POST['id'] ?? 0);

$stmt = db()->prepare(
    'SELECT
        (SELECT COUNT(*) FROM song_parts WHERE instrument_id = ?)   AS part_count,
        (SELECT COUNT(*) FROM performances WHERE instrument_id = ?) AS performance_count,
        (SELECT COUNT(*) FROM room_players WHERE instrument_id = ?) AS player_count'
);
$stmt->execute([$id, $id, $id]);
$usage = $stmt->fetch();

if ((int) $usage['part_count'] > 0 || (int) $usage['performance_count'] > 0 || (int) $usage['player_count'] > 0) {
    flash('error', 'Instrumen ini masih dipakai di ' . $usage['part_count'] . ' lagu, '
        . $usage['performance_count'] . ' nilai permainan, dan ' . $usage['player_count']
        . ' pemain room. Nonaktifkan saja lewat tombol Ubah supaya data lama tetap aman.');
    redirect('admin/instruments.php');
}

try {
    $stmt = db()->prepare('DELETE FROM instruments WHERE id = ?');
    $stmt->execute([$id]);

    if ($stmt->rowCount() === 1) {
        flash('success', 'Instrumen sudah dihapus, termasuk bunyi dan gesturnya.');
    } else {
        flash('error', 'Instrumen tidak ditemukan. Mungkin sudah dihapus.');
    }
} catch (PDOException $e) {
    if (db_error_number($e) === 1451) {
        flash('error', 'Instrumen ini masih dipakai data lain, jadi belum bisa dihapus. Nonaktifkan saja.');
    } else {
        throw $e;
    }
}

redirect('admin/instruments.php');
