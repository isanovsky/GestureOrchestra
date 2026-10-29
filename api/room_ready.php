<?php
// POST api/room_ready.php   Body: { code, token, ready: true|false }
// Pemain menandai dirinya siap (setelah menyiapkan instrumen) atau batal siap.

require __DIR__ . '/../includes/bootstrap.php';
require __DIR__ . '/../includes/api.php';
require __DIR__ . '/../includes/rooms.php';

require_method('POST');
$body = read_json_body();
[$room, $player] = require_room_player($body);

if ($room['status'] !== 'waiting') {
    json_error('Lagu sudah dimulai.', 409);
}
$ready = ($body['ready'] ?? false) === true ? 1 : 0;
db()->prepare('UPDATE room_players SET is_ready = ?, last_seen_at = NOW() WHERE id = ?')
    ->execute([$ready, (int) $player['id']]);

json_response(['ok' => true, 'room' => room_payload($room, (int) $player['id'])]);
