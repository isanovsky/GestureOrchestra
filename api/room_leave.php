<?php
// POST api/room_leave.php   Body: { code, token }
// Pemain keluar dari room. Bila host keluar dari lobby, room ditutup untuk semua.
// Setelah lagu dimulai tidak ada yang dihapus: pemain lain masih perlu mengirim
// nilai dan melihat hasil, jadi room dibiarkan sampai kedaluwarsa sendiri.

require __DIR__ . '/../includes/bootstrap.php';
require __DIR__ . '/../includes/api.php';
require __DIR__ . '/../includes/rooms.php';

require_method('POST');
[$room, $player] = require_room_player(read_json_body());

if ($room['status'] !== 'waiting') {
    json_response(['ok' => true, 'closed' => false]);
}

if ((int) $player['is_host'] === 1) {
    db()->prepare('DELETE FROM rooms WHERE id = ?')->execute([(int) $room['id']]);
    json_response(['ok' => true, 'closed' => true]);
}

db()->prepare('DELETE FROM room_players WHERE id = ?')->execute([(int) $player['id']]);
json_response(['ok' => true, 'closed' => false]);
