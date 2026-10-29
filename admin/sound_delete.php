<?php
// Hapus bunyi. Hanya menerima POST dengan token CSRF.
// Database menolak (RESTRICT) bila bunyi masih dipakai di partitur lagu;
// penolakan itu diubah menjadi pesan yang menyebut lagu mana yang memakainya.

require __DIR__ . '/../includes/bootstrap.php';
$user = require_role('admin');

if (!is_post()) {
    redirect('admin/instruments.php');
}
csrf_verify();

$id = (int) ($_POST['id'] ?? 0);

$stmt = db()->prepare('SELECT id, name, instrument_id FROM sounds WHERE id = ?');
$stmt->execute([$id]);
$sound = $stmt->fetch();

if ($sound === false) {
    flash('error', 'Bunyi tidak ditemukan. Mungkin sudah dihapus.');
    redirect('admin/instruments.php');
}

$back = 'admin/instrument.php?id=' . (int) $sound['instrument_id'];

try {
    $stmt = db()->prepare('DELETE FROM sounds WHERE id = ?');
    $stmt->execute([$id]);
    flash('success', 'Bunyi ' . $sound['name'] . ' sudah dihapus.');
} catch (PDOException $e) {
    if (db_error_number($e) !== 1451) {
        throw $e;
    }
    // Cari judul lagu yang masih memakai bunyi ini.
    $stmt = db()->prepare(
        'SELECT DISTINCT so.title
         FROM song_notes n
         JOIN song_parts p ON p.id = n.song_part_id
         JOIN songs so ON so.id = p.song_id
         WHERE n.sound_id = ?
         ORDER BY so.title'
    );
    $stmt->execute([$id]);
    $titles = $stmt->fetchAll(PDO::FETCH_COLUMN);

    flash('error', 'Bunyi ' . $sound['name'] . ' belum bisa dihapus karena masih dipakai di partitur: '
        . implode(', ', $titles) . '. Hapus atau ganti nada itu di editor partitur dulu.');
}

redirect($back);
