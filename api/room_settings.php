<?php
// POST api/room_settings.php   Body: { code, token, host_token, transpose, tempo_percent }
// Host mengubah nada dasar dan tempo untuk semua pemain, selama room masih menunggu.

require __DIR__ . '/../includes/bootstrap.php';
require __DIR__ . '/../includes/api.php';
require __DIR__ . '/../includes/rooms.php';

require_method('POST');
$body = read_json_body();
[$room, $player] = require_room_player($body);
require_host($room, $body);

if ($room['status'] !== 'waiting') {
    json_error('Pengaturan tidak bisa diubah setelah lagu dimulai.', 409);
}
$transpose = int_or_null($body['transpose'] ?? null);
$tempo = int_or_null($body['tempo_percent'] ?? null);
if ($transpose === null || $transpose < -6 || $transpose > 6 || !in_array($tempo, TEMPO_OPTIONS, true)) {
    json_error('Nada dasar -6 sampai 6, tempo salah satu dari ' . implode(', ', TEMPO_OPTIONS) . '.', 422);
}

db()->prepare('UPDATE rooms SET transpose = ?, tempo_percent = ? WHERE id = ?')
    ->execute([$transpose, $tempo, (int) $room['id']]);
$room['transpose'] = $transpose;
$room['tempo_percent'] = $tempo;

json_response(['ok' => true, 'room' => room_payload($room, (int) $player['id'])]);
