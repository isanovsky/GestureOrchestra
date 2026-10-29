<?php
// Tambah part ke sebuah lagu. POST: song_id, part ("instrument_id:style").
// Satu lagu boleh punya part melodi dan part akor untuk instrumen yang sama.

require __DIR__ . '/../includes/bootstrap.php';
require __DIR__ . '/../includes/content.php';
$user = require_role('admin');

if (!is_post()) {
    redirect('admin/songs.php');
}
csrf_verify();

$songId = (int) ($_POST['song_id'] ?? 0);
$partValue = post_str('part');

fetch_row_or_404('SELECT id FROM songs WHERE id = ?', [$songId], 'Lagu tidak ditemukan');

if (!preg_match('/^(\d+):(notes|chords)$/', $partValue, $m)) {
    flash('error', 'Pilih part yang akan ditambahkan.');
    redirect('admin/song.php?id=' . $songId);
}
$instrumentId = (int) $m[1];
$style = $m[2];

$instrument = fetch_row_or_404('SELECT id, name, type FROM instruments WHERE id = ?', [$instrumentId], 'Instrumen tidak ditemukan');
if ($style === 'chords' && $instrument['type'] === 'drum') {
    flash('error', 'Drum tidak bisa memainkan akor. Pilih part melodi untuk drum.');
    redirect('admin/song.php?id=' . $songId);
}

$label = $instrument['name'] . ' (' . strtolower(PART_STYLES[$style]) . ')';
try {
    $stmt = db()->prepare('INSERT INTO song_parts (song_id, instrument_id, style) VALUES (?, ?, ?)');
    $stmt->execute([$songId, $instrumentId, $style]);
    $partId = (int) db()->lastInsertId();
    flash('success', 'Part ' . $label . ' dibuat. Tempel not angkanya di sini.');
    redirect('admin/part_import.php?part_id=' . $partId);
} catch (PDOException $e) {
    // 1062: lagu ini sudah punya part instrumen + gaya ini (UNIQUE song_id, instrument_id, style).
    if (db_error_number($e) !== 1062) {
        throw $e;
    }
    flash('error', 'Lagu ini sudah punya part ' . $label . '.');
    redirect('admin/song.php?id=' . $songId);
}
