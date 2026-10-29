<?php
// Hapus satu part beserta semua nadanya (CASCADE). POST: id.

require __DIR__ . '/../includes/bootstrap.php';
require __DIR__ . '/../includes/content.php';
$user = require_role('admin');

if (!is_post()) {
    redirect('admin/songs.php');
}
csrf_verify();

$id = (int) ($_POST['id'] ?? 0);

$stmt = db()->prepare(
    'SELECT p.song_id, i.name AS instrument_name
     FROM song_parts p JOIN instruments i ON i.id = p.instrument_id
     WHERE p.id = ?'
);
$stmt->execute([$id]);
$part = $stmt->fetch();

if ($part === false) {
    flash('error', 'Part tidak ditemukan. Mungkin sudah dihapus.');
    redirect('admin/songs.php');
}

$stmt = db()->prepare('DELETE FROM song_parts WHERE id = ?');
$stmt->execute([$id]);
flash('success', 'Part ' . $part['instrument_name'] . ' sudah dihapus.');
if (unpublish_if_empty((int) $part['song_id'])) {
    flash('error', 'Lagu ini tidak punya nada lagi, jadi otomatis disembunyikan dari halaman publik.');
}

redirect('admin/song.php?id=' . (int) $part['song_id']);
