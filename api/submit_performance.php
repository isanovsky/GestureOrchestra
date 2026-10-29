<?php
// POST api/submit_performance.php  (body JSON)
//
// Klien mengirim apa yang DIMAINKAN, bukan nilainya:
// Mode room: { "mode": "room", "code": "K7Q2MX", "token": "<player_token>", "events": [...] }
//   (lagu, instrumen, gaya, dan tempo diambil dari room di database).
// Mode solo:
// {
//   "mode": "solo",
//   "song_id": 1,
//   "instrument_id": 1,
//   "part_style": "notes",       // "notes" (melodi) atau "chords" (akor)
//   "tempo_percent": 100,        // 50, 60, 70, 80, 90, atau 100
//   "nickname": "Rina",          // opsional: kosong = hanya hitung nilai, tidak disimpan
//   "events": [ { "t_ms": 0, "sound_id": 1 }, { "t_ms": 600, "sound_id": null }, ... ]
// }
// t_ms = milidetik sejak lagu mulai. sound_id null = event lepas (jari kembali
// ke 0 atau tangan hilang). Server mengambil partitur dari database, mengubah
// ketukan menjadi milidetik dengan tempo efektif (bpm x tempo_percent / 100),
// lalu menilai dengan fungsi murni di includes/scoring.php.
//
// Mode solo memakai alur dua langkah: kirim tanpa nickname untuk melihat nilai,
// lalu kirim lagi dengan nickname bila pemain mau menyimpannya. Nilai selalu
// dihitung ulang dari event, jadi klien tidak pernah bisa mengirim nilai sendiri.

require __DIR__ . '/../includes/bootstrap.php';
require __DIR__ . '/../includes/api.php';
require __DIR__ . '/../includes/scoring.php';
require_once __DIR__ . '/../includes/music.php';
require __DIR__ . '/../includes/rooms.php';

require_method('POST');

// Batas penyimpanan per session supaya papan skor tidak dibanjiri.
const MAX_SAVES_PER_WINDOW = 10;
const SAVE_WINDOW_SECONDS = 600;
// Event yang masih diterima setelah lagu selesai.
const LATE_GRACE_MS = 2000;
// Jumlah event maksimal: setiap entri partitur boleh dicoba 4 kali, dan setiap
// percobaan bisa punya event lepas, jadi 8 x jumlah entri. Minimal 40, supaya
// part akor yang entrinya sedikit tetap memberi ruang untuk berganti akor.
const MAX_EVENTS_PER_ENTRY = 8;
const MIN_EVENT_LIMIT = 40;

$body = read_json_body();

// --- Validasi field dasar ---
$mode = $body['mode'] ?? '';
if (!in_array($mode, ['solo', 'room'], true)) {
    json_error('Mode harus "solo" atau "room".', 400);
}

$events = $body['events'] ?? null;
if (!is_array($events) || !array_is_list($events)) {
    json_error('events harus berupa daftar.', 400);
}

$room = null;
if ($mode === 'room') {
    // Mode room: lagu, instrumen, gaya, tempo, dan nickname diambil dari
    // database room, bukan dari klien. Klien cukup mengirim kode, token, dan event.
    [$room, $player] = require_room_player($body);
    $room = refresh_room_status($room);
    if ($room['start_at_ms'] === null || now_ms() < (int) $room['start_at_ms']) {
        json_error('Lagu di room ini belum dimulai.', 409);
    }
    $stmt = db()->prepare('SELECT COUNT(*) FROM performances WHERE room_id = ? AND nickname = ?');
    $stmt->execute([(int) $room['id'], $player['nickname']]);
    if ((int) $stmt->fetchColumn() > 0) {
        json_error('Nilaimu untuk lagu ini sudah terkirim. Satu pemain hanya bisa mengirim sekali.', 409);
    }
    $songId = (int) $room['song_id'];
    $instrumentId = (int) $player['instrument_id'];
    $partStyle = $player['part_style'];
    $tempoPercent = (int) $room['tempo_percent'];
    $nickname = $player['nickname'];
} else {
    $songId = int_or_null($body['song_id'] ?? null);
    $instrumentId = int_or_null($body['instrument_id'] ?? null);
    if ($songId === null || $songId < 1 || $instrumentId === null || $instrumentId < 1) {
        json_error('song_id dan instrument_id wajib berupa angka positif.', 400);
    }

    $partStyle = $body['part_style'] ?? 'notes';
    if (!in_array($partStyle, ['notes', 'chords'], true)) {
        json_error('part_style harus "notes" atau "chords".', 400);
    }

    $tempoPercent = int_or_null($body['tempo_percent'] ?? 100);
    if (!in_array($tempoPercent, TEMPO_OPTIONS, true)) {
        json_error('tempo_percent harus salah satu dari ' . implode(', ', TEMPO_OPTIONS) . '.', 400);
    }

    // Nickname kosong = hanya hitung nilai, tidak disimpan.
    $rawNickname = $body['nickname'] ?? '';
    $nickname = $rawNickname === '' ? '' : normalize_nickname($rawNickname);
    if ($nickname === null) {
        json_error(NICKNAME_RULE, 422);
    }
}

// --- Data lagu dan partitur dari database ---
// Mode room tidak mensyaratkan lagu masih tayang, supaya permainan yang sedang
// berjalan tidak gagal bila admin menyembunyikan lagu di tengah jalan.
$stmt = db()->prepare('SELECT id, bpm FROM songs WHERE id = ?' . ($mode === 'solo' ? ' AND is_published = 1' : ''));
$stmt->execute([$songId]);
$song = $stmt->fetch();
if ($song === false) {
    json_error('Lagu tidak ditemukan atau belum tayang.', 404);
}

$stmt = db()->prepare('SELECT id FROM instruments WHERE id = ? AND is_active = 1');
$stmt->execute([$instrumentId]);
if ($stmt->fetch() === false) {
    json_error('Instrumen tidak ditemukan atau sedang nonaktif.', 404);
}

$stmt = db()->prepare(
    'SELECT n.sound_id, n.start_beat, n.duration_beats
     FROM song_notes n JOIN song_parts p ON p.id = n.song_part_id
     WHERE p.song_id = ? AND p.instrument_id = ? AND p.style = ?'
);
$stmt->execute([$songId, $instrumentId, $partStyle]);
$partNotes = $stmt->fetchAll();
if ($partNotes === []) {
    json_error('Lagu ini tidak punya part ' . ($partStyle === 'chords' ? 'akor' : 'melodi') . ' untuk instrumen tersebut.', 404);
}

$bpm = effective_bpm((int) $song['bpm'], $tempoPercent);

// Panjang lagu dihitung dari semua part (sama dengan api/song.php).
$stmt = db()->prepare(
    'SELECT COALESCE(MAX(n.start_beat + n.duration_beats), 0)
     FROM song_notes n JOIN song_parts p ON p.id = n.song_part_id
     WHERE p.song_id = ?'
);
$stmt->execute([$songId]);
$maxTimeMs = (int) ceil((float) $stmt->fetchColumn() * beat_ms($bpm)) + LATE_GRACE_MS;

// Bunyi yang sah = bunyi milik instrumen ini, beserta kodenya
// (dipakai part akor untuk mengambil huruf nada dasar).
$stmt = db()->prepare('SELECT id, code FROM sounds WHERE instrument_id = ?');
$stmt->execute([$instrumentId]);
$codeBySound = [];
foreach ($stmt->fetchAll() as $row) {
    $codeBySound[(int) $row['id']] = $row['code'];
}

// --- Validasi setiap event ---
$maxEvents = max(count($partNotes) * MAX_EVENTS_PER_ENTRY, MIN_EVENT_LIMIT);
if (count($events) > $maxEvents) {
    json_error("Terlalu banyak event: maksimal {$maxEvents} untuk part ini.", 422);
}

$cleanEvents = [];
foreach ($events as $index => $event) {
    if (!is_array($event)) {
        json_error("Event ke-{$index} harus berupa objek.", 422);
    }
    $tMs = int_or_null($event['t_ms'] ?? null);
    $rawSound = $event['sound_id'] ?? null;
    $soundId = $rawSound === null ? null : int_or_null($rawSound);

    if ($tMs === null || ($rawSound !== null && $soundId === null)) {
        json_error("Event ke-{$index} harus berisi t_ms berupa angka dan sound_id berupa angka atau null.", 422);
    }
    if ($tMs < 0 || $tMs > $maxTimeMs) {
        json_error("Event ke-{$index} di luar durasi lagu.", 422);
    }
    if ($soundId !== null && !isset($codeBySound[$soundId])) {
        json_error("Event ke-{$index} memakai bunyi yang bukan milik instrumen ini.", 422);
    }
    $cleanEvents[] = ['t_ms' => $tMs, 'sound_id' => $soundId];
}

// --- Hitung nilai sesuai gaya part ---
$expected = notes_to_ms($partNotes, $bpm);

if ($partStyle === 'notes') {
    $result = score_note_part($expected, $cleanEvents);
} else {
    // Akor dibandingkan dari huruf nada dasarnya (C, F, G, ...), bukan sound_id,
    // supaya akor I dari 1 jari (C4) dan 8 jari (C5) dianggap sama.
    $rootOf = function (?int $soundId) use ($codeBySound): ?string {
        $parsed = $soundId === null ? null : parse_sound_code($codeBySound[$soundId]);
        return $parsed === null ? null : $parsed['letter'] . ($parsed['sharp'] ? '#' : '');
    };
    $entries = array_map(fn($n) => ['root' => $rootOf($n['sound_id']), 't_ms' => $n['t_ms'], 'end_ms' => $n['end_ms']], $expected);
    $chordEvents = array_map(fn($e) => ['root' => $rootOf($e['sound_id']), 't_ms' => $e['t_ms']], $cleanEvents);
    $result = score_chord_part($entries, $chordEvents);
}

if ($mode === 'room') {
    // Satu kali kirim per pemain; nilai langsung disimpan dengan room_id.
    $stmt = db()->prepare(
        "INSERT INTO performances
            (room_id, song_id, instrument_id, part_style, nickname, mode, tempo_percent, total_notes, hits, misses, accuracy)
         VALUES (?, ?, ?, ?, ?, 'room', ?, ?, ?, ?, ?)"
    );
    $stmt->execute([
        (int) $room['id'], $songId, $instrumentId, $partStyle, $nickname, $tempoPercent,
        $result['total_notes'], $result['hits'], $result['misses'], $result['accuracy'],
    ]);
    json_response(['ok' => true, 'saved' => true, 'nickname' => $nickname, 'tempo_percent' => $tempoPercent] + $result);
}

if ($nickname === '') {
    json_response(['ok' => true, 'saved' => false, 'tempo_percent' => $tempoPercent] + $result);
}

// --- Simpan ke papan skor ---
$now = time();
$saves = array_values(array_filter(
    $_SESSION['performance_saves'] ?? [],
    fn(int $t) => $now - $t < SAVE_WINDOW_SECONDS
));
if (count($saves) >= MAX_SAVES_PER_WINDOW) {
    json_error('Kamu sudah menyimpan banyak nilai barusan. Istirahat sebentar, lalu coba lagi.', 429);
}

$stmt = db()->prepare(
    "INSERT INTO performances
        (room_id, song_id, instrument_id, part_style, nickname, mode, tempo_percent, total_notes, hits, misses, accuracy)
     VALUES (NULL, ?, ?, ?, ?, 'solo', ?, ?, ?, ?, ?)"
);
$stmt->execute([
    $songId, $instrumentId, $partStyle, $nickname, $tempoPercent,
    $result['total_notes'], $result['hits'], $result['misses'], $result['accuracy'],
]);

$saves[] = $now;
$_SESSION['performance_saves'] = $saves;

// Peringkat = 1 + jumlah nilai yang lebih tinggi di lagu, instrumen, gaya, dan tempo yang sama.
$stmt = db()->prepare(
    'SELECT COUNT(*) + 1 FROM performances
     WHERE song_id = ? AND instrument_id = ? AND part_style = ? AND tempo_percent = ? AND accuracy > ?'
);
$stmt->execute([$songId, $instrumentId, $partStyle, $tempoPercent, $result['accuracy']]);
$rank = (int) $stmt->fetchColumn();

json_response(['ok' => true, 'saved' => true, 'rank' => $rank, 'nickname' => $nickname, 'tempo_percent' => $tempoPercent] + $result);
