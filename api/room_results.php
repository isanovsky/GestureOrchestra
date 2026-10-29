<?php
// POST api/room_results.php   Body: { code, token }
// Nilai semua pemain di room. 'done' = true bila semua pemain sudah mengirim,
// atau batas waktu (lagu selesai + 20 detik) sudah lewat. Klien memanggil ini
// setiap 2 detik SETELAH lagunya selesai, sampai done.

require __DIR__ . '/../includes/bootstrap.php';
require __DIR__ . '/../includes/api.php';
require __DIR__ . '/../includes/rooms.php';

require_method('POST');
[$room, $player] = require_room_player(read_json_body());
$room = refresh_room_status($room);

if ($room['start_at_ms'] === null) {
    json_error('Lagu belum dimulai.', 409);
}

$stmt = db()->prepare(
    'SELECT p.id, p.nickname, p.part_style, i.name AS instrument_name, i.color,
            pf.accuracy, pf.hits, pf.total_notes
     FROM room_players p
     JOIN instruments i ON i.id = p.instrument_id
     LEFT JOIN performances pf ON pf.room_id = p.room_id AND pf.nickname = p.nickname
     WHERE p.room_id = ?
     ORDER BY pf.accuracy IS NULL, pf.accuracy DESC, p.joined_at'
);
$stmt->execute([(int) $room['id']]);

$results = [];
$pending = 0;
foreach ($stmt->fetchAll() as $row) {
    $submitted = $row['accuracy'] !== null;
    $pending += $submitted ? 0 : 1;
    $results[] = [
        'nickname'   => $row['nickname'],
        'instrument' => $row['instrument_name'],
        'color'      => $row['color'],
        'part_style' => $row['part_style'],
        'submitted'  => $submitted,
        'accuracy'   => $submitted ? (float) $row['accuracy'] : null,
        'hits'       => $submitted ? (int) $row['hits'] : null,
        'total'      => $submitted ? (int) $row['total_notes'] : null,
        'is_me'      => (int) $row['id'] === (int) $player['id'],
    ];
}

$deadline = (int) $room['start_at_ms'] + room_song_length_ms($room) + ROOM_RESULT_WAIT_MS;
$done = $pending === 0 || now_ms() > $deadline;
if ($done && $room['status'] !== 'finished') {
    db()->prepare("UPDATE rooms SET status = 'finished' WHERE id = ?")->execute([(int) $room['id']]);
}

json_response(['ok' => true, 'done' => $done, 'results' => $results]);
