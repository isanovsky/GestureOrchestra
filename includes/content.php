<?php
// Daftar pilihan (enum) dan helper kecil untuk halaman pengelolaan konten.
// Kunci array = nilai di database, isi array = label yang tampil di layar.

const INSTRUMENT_TYPES = [
    'piano' => 'Piano (bernada)',
    'synth' => 'Synth (bernada)',
    'drum'  => 'Drum (tanpa nada)',
];

const PLAY_MODES = [
    'decay'   => 'Decay: bunyi sekali lalu meluruh',
    'sustain' => 'Sustain: bunyi selama gestur ditahan',
    'hit'     => 'Hit: pukulan pendek',
];

const DIFFICULTIES = [
    'easy'   => 'Mudah',
    'medium' => 'Sedang',
    'hard'   => 'Sulit',
];

// Gaya part lagu (kolom song_parts.style).
const PART_STYLES = [
    'notes'  => 'Melodi',
    'chords' => 'Akor',
];

// Tempo metronom di mode bebas (dikali persen tempo latihan), dipakai ritme akor.
const FREE_MODE_BPM = 90;

const PERFORMANCE_MODES = [
    'solo' => 'Solo',
    'room' => 'Room',
];

// Ubah teks angka desimal dari form menjadi string siap simpan.
// Menerima koma atau titik ("1,5" atau "1.5"), maksimal $decimals angka
// di belakang koma. Hasil null berarti formatnya salah.
function parse_decimal(string $text, int $decimals): ?string
{
    $text = str_replace(',', '.', trim($text));
    if (!preg_match('/^\d{1,6}(\.\d{1,' . $decimals . '})?$/', $text)) {
        return null;
    }
    return $text;
}

// Tampilkan angka ketukan tanpa nol berlebih: "4.000" -> "4", "1.500" -> "1.5".
function format_beat(string|float|null $value): string
{
    if ($value === null || $value === '') {
        return '-';
    }
    return rtrim(rtrim(number_format((float) $value, 3, '.', ''), '0'), '.');
}

// Kotak warna kecil untuk menandai instrumen.
function color_swatch(string $hex): string
{
    return '<span class="swatch" style="background:' . e($hex) . '"></span>';
}

// Lagu yang sudah tayang tapi tidak punya nada lagi (setelah nada atau part
// dihapus) otomatis disembunyikan, supaya pemain tidak memilih lagu kosong.
// Mengembalikan true bila lagu baru saja disembunyikan.
function unpublish_if_empty(int $songId): bool
{
    $stmt = db()->prepare(
        'UPDATE songs SET is_published = 0
         WHERE id = ? AND is_published = 1
           AND NOT EXISTS (
               SELECT 1 FROM song_notes n JOIN song_parts p ON p.id = n.song_part_id WHERE p.song_id = ?
           )'
    );
    $stmt->execute([$songId, $songId]);
    return $stmt->rowCount() === 1;
}

// ---------------------------------------------------------------------
// Data untuk halaman publik
// ---------------------------------------------------------------------

// Instrumen aktif beserta pemetaan gesturnya (jumlah jari -> bunyi).
// Setiap baris punya kunci tambahan 'gestures'.
function fetch_active_instruments(): array
{
    $instruments = db()->query(
        'SELECT id, name, slug, type, play_mode, color, description
         FROM instruments WHERE is_active = 1 ORDER BY id'
    )->fetchAll();

    $stmt = db()->query(
        'SELECT g.instrument_id, g.finger_count, s.name AS sound_name, s.code AS sound_code
         FROM gestures g JOIN sounds s ON s.id = g.sound_id
         WHERE g.is_active = 1
         ORDER BY g.instrument_id, g.finger_count'
    );
    $gesturesByInstrument = [];
    foreach ($stmt->fetchAll() as $row) {
        $gesturesByInstrument[(int) $row['instrument_id']][] = $row;
    }

    foreach ($instruments as &$instrument) {
        $instrument['gestures'] = $gesturesByInstrument[(int) $instrument['id']] ?? [];
    }
    unset($instrument);

    return $instruments;
}

// Lagu yang tayang beserta panjangnya dan daftar part yang bisa dimainkan.
// Setiap lagu mendapat kunci tambahan:
//   'parts'          => [['instrument_id' => 1, 'style' => 'chords'], ...]
//   'instrument_ids' => id instrumen unik yang punya part (untuk ikon)
//   'length_seconds' => panjang lagu pada tempo 100%
function fetch_published_songs(): array
{
    $songs = db()->query(
        'SELECT so.id, so.title, so.composer, so.bpm, so.beats_per_bar, so.default_transpose,
            so.difficulty, so.description,
            (SELECT COALESCE(MAX(n.start_beat + n.duration_beats), 0)
             FROM song_notes n JOIN song_parts p ON p.id = n.song_part_id
             WHERE p.song_id = so.id) AS length_beats
         FROM songs so
         WHERE so.is_published = 1
         ORDER BY so.title'
    )->fetchAll();

    // Hanya part yang punya nada dan instrumennya aktif yang bisa dimainkan.
    // Satu instrumen bisa punya dua part (melodi dan akor).
    // keyboard_only = ada nada yang tidak punya gestur kamera aktif, jadi part
    // ini hanya bisa dimainkan penuh lewat keyboard.
    $stmt = db()->query(
        'SELECT p.song_id, p.instrument_id, p.style, MAX(g.id IS NULL) AS keyboard_only
         FROM song_parts p
         JOIN instruments i ON i.id = p.instrument_id AND i.is_active = 1
         JOIN song_notes n ON n.song_part_id = p.id
         LEFT JOIN gestures g ON g.instrument_id = p.instrument_id AND g.sound_id = n.sound_id AND g.is_active = 1
         GROUP BY p.id, p.song_id, p.instrument_id, p.style
         ORDER BY p.instrument_id, p.style DESC'
    );
    $partsBySong = [];
    foreach ($stmt->fetchAll() as $row) {
        $partsBySong[(int) $row['song_id']][] = [
            'instrument_id' => (int) $row['instrument_id'],
            'style'         => $row['style'],
            'keyboard_only' => (int) $row['keyboard_only'] === 1,
        ];
    }

    foreach ($songs as &$song) {
        $song['parts'] = $partsBySong[(int) $song['id']] ?? [];
        $song['instrument_ids'] = array_values(array_unique(array_column($song['parts'], 'instrument_id')));
        $song['length_seconds'] = (float) $song['length_beats'] * 60 / (int) $song['bpm'];
    }
    unset($song);

    return $songs;
}

// Part tertentu dari sebuah lagu (hasil fetch_published_songs), atau null.
function song_part(array $song, int $instrumentId, string $style): ?array
{
    foreach ($song['parts'] as $part) {
        if ($part['instrument_id'] === $instrumentId && $part['style'] === $style) {
            return $part;
        }
    }
    return null;
}

// Apakah lagu punya part untuk instrumen dan gaya tertentu?
function song_has_part(array $song, int $instrumentId, string $style): bool
{
    foreach ($song['parts'] as $part) {
        if ($part['instrument_id'] === $instrumentId && $part['style'] === $style) {
            return true;
        }
    }
    return false;
}

// Kalimat contoh untuk satu gestur, misalnya "Angkat 3 jari, bunyilah Mi".
function gesture_sentence(array $gesture): string
{
    return 'Angkat ' . $gesture['finger_count'] . ' jari, bunyilah ' . $gesture['sound_name'];
}

// Durasi dalam detik menjadi teks, misalnya "12 detik" atau "1 menit 5 detik".
function format_duration(float $seconds): string
{
    $total = (int) round($seconds);
    if ($total < 60) {
        return $total . ' detik';
    }
    return intdiv($total, 60) . ' menit' . ($total % 60 > 0 ? ' ' . ($total % 60) . ' detik' : '');
}

// Cara membentuk hitungan jari untuk sebuah cara membaca gestur ('sum' atau 'leftPlus5').
// Teks yang sama dibuat JavaScript di assets/js/hand-tracker.js (fingerHowTo); ubah keduanya bersamaan.
function finger_how_to(int $count, string $mode): string
{
    if ($count === 0) {
        return 'kepalan';
    }
    if ($mode === 'leftPlus5') {
        return $count <= 5 ? "kanan {$count}" : 'kiri buka + kanan ' . ($count - 5);
    }
    return $count <= 5 ? "{$count} jari" : '5 + ' . ($count - 5) . ', dua tangan';
}
