<?php
// GET api/song.php?id=1
// Lagu yang sudah tayang beserta partitur semua part-nya.
// Satu instrumen bisa punya dua part: 'notes' (melodi) dan 'chords' (akor;
// sound_id = nada dasar akor).
// Panjang lagu (length_beats) = nada yang paling akhir selesai di antara semua part,
// supaya semua pemain di satu room selesai pada waktu yang sama.

require __DIR__ . '/../includes/bootstrap.php';
require __DIR__ . '/../includes/api.php';

require_method('GET');

$id = int_or_null($_GET['id'] ?? null);
if ($id === null || $id < 1) {
    json_error('Parameter id tidak valid.', 400);
}

$stmt = db()->prepare(
    'SELECT id, title, composer, bpm, beats_per_bar, default_transpose, difficulty
     FROM songs WHERE id = ? AND is_published = 1'
);
$stmt->execute([$id]);
$song = $stmt->fetch();

if ($song === false) {
    json_error('Lagu tidak ditemukan atau belum tayang.', 404);
}

$stmt = db()->prepare(
    'SELECT p.instrument_id, p.style, n.sound_id, n.start_beat, n.duration_beats
     FROM song_parts p
     JOIN song_notes n ON n.song_part_id = p.id
     WHERE p.song_id = ?
     ORDER BY p.instrument_id, p.style, n.start_beat'
);
$stmt->execute([$id]);

$parts = [];        // "instrument_id:style" => part
$lengthBeats = 0.0;
foreach ($stmt->fetchAll() as $row) {
    $start = (float) $row['start_beat'];
    $duration = (float) $row['duration_beats'];
    $key = $row['instrument_id'] . ':' . $row['style'];
    if (!isset($parts[$key])) {
        $parts[$key] = ['instrument_id' => (int) $row['instrument_id'], 'style' => $row['style'], 'notes' => []];
    }
    $parts[$key]['notes'][] = [
        'sound_id'       => (int) $row['sound_id'],
        'start_beat'     => $start,
        'duration_beats' => $duration,
    ];
    $lengthBeats = max($lengthBeats, $start + $duration);
}

json_response([
    'ok'   => true,
    'song' => [
        'id'            => (int) $song['id'],
        'title'         => $song['title'],
        'composer'      => $song['composer'],
        'bpm'           => (int) $song['bpm'],
        'beats_per_bar' => (int) $song['beats_per_bar'],
        'default_transpose' => (int) $song['default_transpose'],
        'difficulty'    => $song['difficulty'],
        'length_beats'  => $lengthBeats,
    ],
    // Daftar part: [{ instrument_id, style, notes: [...] }, ...]
    'parts' => array_values($parts),
]);
