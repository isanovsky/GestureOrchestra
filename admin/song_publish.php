<?php
// Tayangkan atau sembunyikan lagu. POST: id, publish (1 atau 0).

require __DIR__ . '/../includes/bootstrap.php';
$user = require_role('admin');

if (!is_post()) {
    redirect('admin/songs.php');
}
csrf_verify();

$id = (int) ($_POST['id'] ?? 0);
$publish = ($_POST['publish'] ?? '') === '1' ? 1 : 0;
$back = ($_POST['back'] ?? '') === 'song' ? 'admin/song.php?id=' . $id : 'admin/songs.php';

$stmt = db()->prepare('SELECT title FROM songs WHERE id = ?');
$stmt->execute([$id]);
$title = $stmt->fetchColumn();

if ($title === false) {
    flash('error', 'Lagu tidak ditemukan.');
    redirect('admin/songs.php');
}

// Lagu kosong tidak boleh tayang: minimal satu part harus punya nada.
if ($publish === 1) {
    $stmt = db()->prepare(
        'SELECT COUNT(*) FROM song_notes n JOIN song_parts p ON p.id = n.song_part_id WHERE p.song_id = ?'
    );
    $stmt->execute([$id]);
    if ((int) $stmt->fetchColumn() === 0) {
        flash('error', 'Lagu ' . $title . ' belum punya nada sama sekali. Isi partiturnya dulu sebelum ditayangkan.');
        redirect($back);
    }
}

$stmt = db()->prepare('UPDATE songs SET is_published = ? WHERE id = ?');
$stmt->execute([$publish, $id]);

flash('success', $publish === 1
    ? 'Lagu ' . $title . ' sekarang tayang di halaman publik.'
    : 'Lagu ' . $title . ' disembunyikan dari halaman publik.');
redirect($back);
