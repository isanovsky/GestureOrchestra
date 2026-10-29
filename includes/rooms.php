<?php
// Helper bersama untuk endpoint room (main bersama).
//
// Identitas pemain tanpa login: setiap pemain mendapat player_token acak
// (64 karakter heksadesimal) saat membuat atau bergabung ke room. Host juga
// mendapat host_token. Token disimpan di sessionStorage browser pemain dan
// dikirim di setiap request; server mencocokkannya dengan database.

require_once __DIR__ . '/music.php';
require_once __DIR__ . '/scoring.php';

// Kode room: 6 karakter huruf besar dan angka, tanpa O/0 dan I/1 yang mudah tertukar.
const ROOM_CODE_ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
const ROOM_CODE_LENGTH = 6;
// Room kedaluwarsa 2 jam setelah dibuat.
const ROOM_LIFETIME_HOURS = 2;
// Pemain dianggap terputus bila tidak polling lebih dari 15 detik.
const ROOM_DISCONNECT_SECONDS = 15;
// Lagu mulai 5 detik setelah host menekan mulai.
const ROOM_START_DELAY_MS = 5000;
// Setelah lagu selesai, hasil ditunggu paling lama 20 detik.
const ROOM_RESULT_WAIT_MS = 20000;

// Waktu server dalam milidetik (dipakai jam bersama semua pemain).
function now_ms(): int
{
    return (int) floor(microtime(true) * 1000);
}

function new_token(): string
{
    return bin2hex(random_bytes(32));
}

function new_room_code(): string
{
    $code = '';
    for ($i = 0; $i < ROOM_CODE_LENGTH; $i++) {
        $code .= ROOM_CODE_ALPHABET[random_int(0, strlen(ROOM_CODE_ALPHABET) - 1)];
    }
    return $code;
}

// Kode dari input pemain: huruf kecil dijadikan besar, spasi dibuang.
// Hasil null bila formatnya bukan kode room.
function normalize_room_code(mixed $value): ?string
{
    if (!is_string($value)) {
        return null;
    }
    $code = strtoupper(preg_replace('/\s+/', '', $value));
    $allowed = preg_quote(ROOM_CODE_ALPHABET, '/');
    return preg_match('/^[' . $allowed . ']{' . ROOM_CODE_LENGTH . '}$/', $code) ? $code : null;
}

// Hapus room yang sudah kedaluwarsa. Tidak ada cron di InfinityFree, jadi
// dipanggil setiap kali room baru dibuat. Pemain ikut terhapus (CASCADE),
// nilai permainan tetap ada (room_id menjadi NULL).
function cleanup_expired_rooms(): int
{
    return db()->exec('DELETE FROM rooms WHERE expires_at < NOW()');
}

// Room aktif (belum kedaluwarsa) berdasarkan kode, atau null.
function find_room(string $code): ?array
{
    $stmt = db()->prepare(
        'SELECT r.*, s.title AS song_title, s.bpm AS song_bpm
         FROM rooms r JOIN songs s ON s.id = r.song_id
         WHERE r.code = ? AND r.expires_at > NOW()'
    );
    $stmt->execute([$code]);
    $room = $stmt->fetch();
    return $room === false ? null : $room;
}

// Pemain di room tertentu berdasarkan token, atau null.
function find_player(int $roomId, string $token): ?array
{
    if (!preg_match('/^[a-f0-9]{64}$/', $token)) {
        return null;
    }
    $stmt = db()->prepare('SELECT * FROM room_players WHERE room_id = ? AND player_token = ?');
    $stmt->execute([$roomId, $token]);
    $player = $stmt->fetch();
    return $player === false ? null : $player;
}

// Ambil room dan pemain dari body JSON {code, token}. Gagal = langsung balas error.
function require_room_player(array $body): array
{
    $code = normalize_room_code($body['code'] ?? null);
    $room = $code === null ? null : find_room($code);
    if ($room === null) {
        json_error('Room tidak ditemukan atau sudah kedaluwarsa.', 404);
    }
    $player = find_player((int) $room['id'], is_string($body['token'] ?? null) ? $body['token'] : '');
    if ($player === null) {
        json_error('Kamu bukan pemain di room ini. Gabung ulang dengan kode room.', 403);
    }
    return [$room, $player];
}

// Pastikan pengirim adalah host (punya host_token yang benar).
function require_host(array $room, array $body): void
{
    $hostToken = is_string($body['host_token'] ?? null) ? $body['host_token'] : '';
    if (!hash_equals($room['host_token'], $hostToken)) {
        json_error('Hanya host yang boleh melakukan ini.', 403);
    }
}

// Ubah status countdown menjadi playing bila waktu mulai sudah lewat.
// Server tidak punya proses yang berjalan terus, jadi status diperbarui
// saat ada request yang membacanya.
function refresh_room_status(array $room): array
{
    if ($room['status'] === 'countdown' && $room['start_at_ms'] !== null && now_ms() >= (int) $room['start_at_ms']) {
        db()->prepare("UPDATE rooms SET status = 'playing' WHERE id = ? AND status = 'countdown'")->execute([(int) $room['id']]);
        $room['status'] = 'playing';
    }
    return $room;
}

// Panjang lagu dalam milidetik pada tempo room (part terpanjang).
function room_song_length_ms(array $room): int
{
    $stmt = db()->prepare(
        'SELECT COALESCE(MAX(n.start_beat + n.duration_beats), 0)
         FROM song_notes n JOIN song_parts p ON p.id = n.song_part_id
         WHERE p.song_id = ?'
    );
    $stmt->execute([(int) $room['song_id']]);
    $bpm = effective_bpm((int) $room['song_bpm'], (int) $room['tempo_percent']);
    return (int) ceil((float) $stmt->fetchColumn() * beat_ms($bpm));
}

// Validasi instrumen + gaya pemain terhadap lagu room.
// Hasil: [instrument_id, style] atau langsung balas error.
function require_part_choice(array $body, int $songId): array
{
    $instrumentId = int_or_null($body['instrument_id'] ?? null);
    $style = $body['part_style'] ?? 'notes';
    if ($instrumentId === null || !in_array($style, ['notes', 'chords'], true)) {
        json_error('Pilih instrumen dan gaya main.', 400);
    }
    $stmt = db()->prepare(
        'SELECT COUNT(*)
         FROM song_parts p
         JOIN instruments i ON i.id = p.instrument_id AND i.is_active = 1
         JOIN song_notes n ON n.song_part_id = p.id
         WHERE p.song_id = ? AND p.instrument_id = ? AND p.style = ?'
    );
    $stmt->execute([$songId, $instrumentId, $style]);
    if ((int) $stmt->fetchColumn() === 0) {
        json_error('Lagu ini tidak punya part ' . ($style === 'chords' ? 'akor' : 'melodi') . ' untuk instrumen itu. Pilih yang lain.', 422);
    }
    return [$instrumentId, $style];
}

// Data room untuk dikirim ke klien (tanpa token apa pun).
function room_payload(array $room, int $myPlayerId): array
{
    $stmt = db()->prepare(
        'SELECT p.id, p.nickname, p.part_style, p.is_host, p.is_ready,
                TIMESTAMPDIFF(SECOND, p.last_seen_at, NOW()) AS seen_ago,
                i.id AS instrument_id, i.name AS instrument_name, i.slug, i.type, i.color
         FROM room_players p JOIN instruments i ON i.id = p.instrument_id
         WHERE p.room_id = ?
         ORDER BY p.is_host DESC, p.joined_at, p.id'
    );
    $stmt->execute([(int) $room['id']]);
    $players = [];
    foreach ($stmt->fetchAll() as $row) {
        $players[] = [
            'id'         => (int) $row['id'],
            'nickname'   => $row['nickname'],
            'part_style' => $row['part_style'],
            'is_host'    => (int) $row['is_host'] === 1,
            'is_ready'   => (int) $row['is_ready'] === 1,
            'connected'  => (int) $row['seen_ago'] <= ROOM_DISCONNECT_SECONDS,
            'is_me'      => (int) $row['id'] === $myPlayerId,
            'instrument' => [
                'id' => (int) $row['instrument_id'], 'name' => $row['instrument_name'],
                'slug' => $row['slug'], 'type' => $row['type'], 'color' => $row['color'],
            ],
        ];
    }

    return [
        'code'          => $room['code'],
        'status'        => $room['status'],
        'song'          => ['id' => (int) $room['song_id'], 'title' => $room['song_title']],
        'transpose'     => (int) $room['transpose'],
        'key_name'      => key_name((int) $room['transpose']),
        'tempo_percent' => (int) $room['tempo_percent'],
        'max_players'   => (int) $room['max_players'],
        'start_at_ms'   => $room['start_at_ms'] === null ? null : (int) $room['start_at_ms'],
        'server_ms'     => now_ms(),
        'players'       => $players,
    ];
}
