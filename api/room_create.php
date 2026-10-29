<?php
// POST api/room_create.php
// Body: { nickname, song_id, instrument_id, part_style, transpose, tempo_percent }
// Membuat room baru dengan kode 6 karakter, lalu memasukkan pembuatnya sebagai host.
// Balasan berisi player_token dan host_token yang harus disimpan klien.

require __DIR__ . '/../includes/bootstrap.php';
require __DIR__ . '/../includes/api.php';
require __DIR__ . '/../includes/rooms.php';

require_method('POST');
$body = read_json_body();

if (!session_rate_limit('room_creates', 5, 600)) {
    json_error('Terlalu banyak room dibuat dari perangkat ini. Tunggu beberapa menit.', 429);
}

$nickname = normalize_nickname($body['nickname'] ?? null);
if ($nickname === null) {
    json_error(NICKNAME_RULE, 422);
}

$songId = int_or_null($body['song_id'] ?? null);
$stmt = db()->prepare('SELECT id FROM songs WHERE id = ? AND is_published = 1');
$stmt->execute([$songId ?? 0]);
if ($stmt->fetch() === false) {
    json_error('Pilih lagu yang sudah tayang.', 422);
}

[$instrumentId, $style] = require_part_choice($body, $songId);

$transpose = int_or_null($body['transpose'] ?? 0);
if ($transpose === null || $transpose < -6 || $transpose > 6) {
    json_error('Nada dasar harus -6 sampai 6.', 422);
}
$tempo = int_or_null($body['tempo_percent'] ?? 100);
if (!in_array($tempo, TEMPO_OPTIONS, true)) {
    json_error('Tempo harus salah satu dari ' . implode(', ', TEMPO_OPTIONS) . '.', 422);
}

// Tidak ada cron: room kedaluwarsa dibersihkan setiap kali room baru dibuat.
cleanup_expired_rooms();

$pdo = db();
$hostToken = new_token();
$playerToken = new_token();

// Kode bisa (sangat jarang) bentrok dengan room lain; coba ulang beberapa kali.
$code = null;
for ($attempt = 0; $attempt < 5 && $code === null; $attempt++) {
    try {
        $candidate = new_room_code();
        $pdo->prepare(
            'INSERT INTO rooms (code, song_id, transpose, tempo_percent, host_token, expires_at)
             VALUES (?, ?, ?, ?, ?, NOW() + INTERVAL ' . ROOM_LIFETIME_HOURS . ' HOUR)'
        )->execute([$candidate, $songId, $transpose, $tempo, $hostToken]);
        $code = $candidate;
    } catch (PDOException $e) {
        if (db_error_number($e) !== 1062) {
            throw $e;
        }
    }
}
if ($code === null) {
    json_error('Gagal membuat kode room. Coba lagi.', 503);
}
$roomId = (int) $pdo->lastInsertId();

$pdo->prepare(
    'INSERT INTO room_players (room_id, nickname, instrument_id, part_style, player_token, is_host)
     VALUES (?, ?, ?, ?, ?, 1)'
)->execute([$roomId, $nickname, $instrumentId, $style, $playerToken]);

json_response([
    'ok'           => true,
    'code'         => $code,
    'player_id'    => (int) $pdo->lastInsertId(),
    'player_token' => $playerToken,
    'host_token'   => $hostToken,
], 201);
