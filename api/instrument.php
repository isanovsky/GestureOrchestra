<?php
// GET api/instrument.php?slug=piano
// Data satu instrumen aktif: info, daftar bunyi, dan pemetaan gestur aktif.
// Dipakai JavaScript supaya pemetaan jari -> bunyi tidak di-hardcode.

require __DIR__ . '/../includes/bootstrap.php';
require __DIR__ . '/../includes/api.php';

require_method('GET');

$slug = $_GET['slug'] ?? '';
if (!is_string($slug) || !preg_match('/^[a-z0-9-]{2,50}$/', $slug)) {
    json_error('Parameter slug tidak valid.', 400);
}

$stmt = db()->prepare(
    'SELECT id, name, slug, type, play_mode, color, description
     FROM instruments WHERE slug = ? AND is_active = 1'
);
$stmt->execute([$slug]);
$instrument = $stmt->fetch();

if ($instrument === false) {
    json_error('Instrumen tidak ditemukan atau sedang nonaktif.', 404);
}

$stmt = db()->prepare(
    'SELECT id, name, code, frequency, sample_path
     FROM sounds WHERE instrument_id = ? ORDER BY sort_order, id'
);
$stmt->execute([$instrument['id']]);
$sounds = [];
foreach ($stmt->fetchAll() as $row) {
    $sounds[] = [
        'id'          => (int) $row['id'],
        'name'        => $row['name'],
        'code'        => $row['code'],
        'frequency'   => $row['frequency'] === null ? null : (float) $row['frequency'],
        'sample_path' => $row['sample_path'],
    ];
}

$stmt = db()->prepare(
    'SELECT finger_count, sound_id FROM gestures
     WHERE instrument_id = ? AND is_active = 1 ORDER BY finger_count'
);
$stmt->execute([$instrument['id']]);
$gestures = [];
foreach ($stmt->fetchAll() as $row) {
    $gestures[] = ['finger_count' => (int) $row['finger_count'], 'sound_id' => (int) $row['sound_id']];
}

json_response([
    'ok'         => true,
    'instrument' => [
        'id'          => (int) $instrument['id'],
        'name'        => $instrument['name'],
        'slug'        => $instrument['slug'],
        'type'        => $instrument['type'],
        'play_mode'   => $instrument['play_mode'],
        'color'       => $instrument['color'],
        'description' => $instrument['description'],
    ],
    'sounds'     => $sounds,
    'gestures'   => $gestures,
]);
