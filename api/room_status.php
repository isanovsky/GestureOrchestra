<?php
// POST api/room_status.php   Body: { code, token }
// Dipanggil lobby setiap 2 detik. Sekaligus mencatat bahwa pemain ini masih
// terhubung (last_seen_at). Polling berhenti begitu start_at_ms terisi.

require __DIR__ . '/../includes/bootstrap.php';
require __DIR__ . '/../includes/api.php';
require __DIR__ . '/../includes/rooms.php';

require_method('POST');
[$room, $player] = require_room_player(read_json_body());

db()->prepare('UPDATE room_players SET last_seen_at = NOW() WHERE id = ?')->execute([(int) $player['id']]);
$room = refresh_room_status($room);

json_response(['ok' => true, 'room' => room_payload($room, (int) $player['id'])]);
