<?php
// POST api/room_join.php
// Body: { code, nickname, instrument_id, part_style }
// Bergabung ke room yang masih menunggu (maksimal 4 pemain).

require __DIR__ . '/../includes/bootstrap.php';
require __DIR__ . '/../includes/api.php';
require __DIR__ . '/../includes/rooms.php';

require_method('POST');
$body = read_json_body();

if (!session_rate_limit('room_joins', 20, 600)) {
    json_error('Terlalu banyak percobaan bergabung. Tunggu beberapa menit.', 429);
}

$code = normalize_room_code($body['code'] ?? null);
if ($code === null) {
    json_error('Kode room terdiri dari 6 huruf dan angka, misalnya K7Q2MX.', 422);
}
$nickname = normalize_nickname($body['nickname'] ?? null);
if ($nickname === null) {
    json_error(NICKNAME_RULE, 422);
}

$room = find_room($code);
if ($room === null) {
    json_error('Room ' . $code . ' tidak ditemukan atau sudah kedaluwarsa. Periksa kodenya.', 404);
}
if ($room['status'] !== 'waiting') {
    json_error('Room ini sudah mulai bermain. Tunggu lagu selesai atau buat room baru.', 409);
}

[$instrumentId, $style] = require_part_choice($body, (int) $room['song_id']);

// Transaksi + kunci baris room supaya dua orang yang bergabung bersamaan
// tidak membuat room berisi 5 pemain.
$pdo = db();
$pdo->beginTransaction();
try {
    $pdo->prepare('SELECT id FROM rooms WHERE id = ? FOR UPDATE')->execute([(int) $room['id']]);
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM room_players WHERE room_id = ?');
    $stmt->execute([(int) $room['id']]);
    if ((int) $stmt->fetchColumn() >= (int) $room['max_players']) {
        $pdo->rollBack();
        json_error('Room sudah penuh (' . $room['max_players'] . ' pemain).', 409);
    }

    $token = new_token();
    $pdo->prepare(
        'INSERT INTO room_players (room_id, nickname, instrument_id, part_style, player_token)
         VALUES (?, ?, ?, ?, ?)'
    )->execute([(int) $room['id'], $nickname, $instrumentId, $style, $token]);
    $playerId = (int) $pdo->lastInsertId();
    $pdo->commit();
} catch (PDOException $e) {
    $pdo->rollBack();
    if (db_error_number($e) === 1062) {
        json_error('Nickname ' . $nickname . ' sudah dipakai di room ini. Pakai nama lain.', 409);
    }
    throw $e;
}

json_response(['ok' => true, 'code' => $code, 'player_id' => $playerId, 'player_token' => $token], 201);
