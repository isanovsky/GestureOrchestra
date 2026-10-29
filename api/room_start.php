<?php
// POST api/room_start.php   Body: { code, token, host_token }
// Host memulai lagu. Status menjadi 'countdown' dan start_at_ms diisi
// waktu server sekarang + 5 detik. Setiap klien melihatnya di polling
// berikutnya, lalu memulai lagu tepat pada waktu itu menurut jam server.

require __DIR__ . '/../includes/bootstrap.php';
require __DIR__ . '/../includes/api.php';
require __DIR__ . '/../includes/rooms.php';

require_method('POST');
$body = read_json_body();
[$room, $player] = require_room_player($body);
require_host($room, $body);

if ($room['status'] !== 'waiting') {
    json_error('Lagu sudah dimulai.', 409);
}

// Semua pemain yang masih terhubung harus sudah siap. Pemain yang terputus diabaikan.
$stmt = db()->prepare(
    'SELECT nickname FROM room_players
     WHERE room_id = ? AND is_ready = 0
       AND TIMESTAMPDIFF(SECOND, last_seen_at, NOW()) <= ?'
);
$stmt->execute([(int) $room['id'], ROOM_DISCONNECT_SECONDS]);
$notReady = $stmt->fetchAll(PDO::FETCH_COLUMN);
if ($notReady !== []) {
    json_error('Belum siap: ' . implode(', ', $notReady) . '. Minta mereka menekan "Siapkan instrumen".', 409);
}

$startAt = now_ms() + ROOM_START_DELAY_MS;
$stmt = db()->prepare("UPDATE rooms SET status = 'countdown', start_at_ms = ? WHERE id = ? AND status = 'waiting'");
$stmt->execute([$startAt, (int) $room['id']]);
if ($stmt->rowCount() !== 1) {
    json_error('Lagu sudah dimulai.', 409);
}
$room['status'] = 'countdown';
$room['start_at_ms'] = $startAt;

json_response(['ok' => true, 'room' => room_payload($room, (int) $player['id'])]);
