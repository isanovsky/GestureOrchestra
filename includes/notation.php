<?php
// Importer partitur dari teks not angka. Semua fungsi MURNI: masukan teks dan
// array, keluaran array. Tidak menyentuh database, jadi mudah diuji
// (tests/scoring_and_import_test.php) dan mudah dijelaskan.
//
// Sintaks melodi (part 'notes'):
//   1-7      Do sampai Si. 0 = diam (istirahat).
//   1' / 5,  oktaf tinggi (titik di atas) / oktaf rendah (titik di bawah).
//   spasi    pemisah ketukan: satu token = satu ketukan.
//   .        perpanjang nada sebelumnya satu ketukan.
//   [5 6]    satu ketukan dibagi rata untuk isinya; boleh bersarang satu tingkat.
//   [. 2]    nada sebelumnya diperpanjang setengah ketuk, lalu 2 setengah ketuk.
//   |        garis birama.
//   # ...    komentar sampai akhir baris (harus diawali spasi atau di awal baris,
//            supaya C# di teks akor tidak dianggap komentar).
//
// Sintaks akor (part 'chords'):
//   Nama akor dipisah spasi, dibagi rata sepanjang birama: "F C" di 4/4 = 2 + 2 ketuk.
//   -        lanjutkan akor sebelumnya.
//   Nama akor ditulis dalam nada dasar lagu, lalu diubah menjadi derajat.

require_once __DIR__ . '/music.php';

// Batas ukuran teks supaya parser tidak dipakai untuk membebani server.
const NOTATION_MAX_CHARS = 20000;
// Kedalaman maksimal kurung siku: [1 [2 3]] boleh, [1 [2 [3 4]]] tidak.
const NOTATION_MAX_DEPTH = 2;

// ---------------------------------------------------------------------
// Bagian bersama: komentar dan birama
// ---------------------------------------------------------------------

// Buang komentar, gabungkan baris, lalu pecah per garis birama.
// Hasil: daftar teks birama (birama kosong dilewati), nomor dari 1.
function notation_split_bars(string $text): array
{
    $lines = preg_split('/\R/u', $text);
    $clean = [];
    foreach ($lines as $line) {
        $clean[] = preg_replace('/(^|\s)#.*$/u', '', $line);
    }
    $bars = [];
    foreach (explode('|', implode(' ', $clean)) as $bar) {
        $bar = trim($bar);
        if ($bar !== '') {
            $bars[] = $bar;
        }
    }
    return $bars;
}

// Pastikan durasi bisa disimpan tepat di kolom DECIMAL(...,3).
// Contoh: 1/3 ketuk tidak bisa (0.333...), 1/2 dan 1/4 bisa.
function notation_is_exact(float $value): bool
{
    return abs(round($value, 3) - $value) < 1e-9;
}

// Teks posisi untuk pesan error, misalnya "birama 3, ketukan 2".
function notation_position(int $bar, float $beatInBar): string
{
    return 'birama ' . $bar . ', ketukan ' . rtrim(rtrim(number_format($beatInBar + 1, 3, '.', ''), '0'), '.');
}

// ---------------------------------------------------------------------
// Melodi
// ---------------------------------------------------------------------

// Ubah teks satu birama menjadi pohon token: string untuk token biasa,
// array untuk isi kurung siku. Mengembalikan [pohon, pesan error atau null].
function notation_tokenize_bar(string $bar): array
{
    // Beri spasi di sekitar kurung supaya "[5 6]" dan "[ 5 6 ]" sama saja.
    $parts = preg_split('/\s+/', trim(str_replace(['[', ']'], [' [ ', ' ] '], $bar)));
    $stack = [[]];
    foreach ($parts as $part) {
        if ($part === '[') {
            if (count($stack) > NOTATION_MAX_DEPTH) {
                return [null, 'kurung siku hanya boleh bersarang satu tingkat'];
            }
            $stack[] = [];
        } elseif ($part === ']') {
            if (count($stack) === 1) {
                return [null, 'ada "]" tanpa pasangan "["'];
            }
            $group = array_pop($stack);
            if ($group === []) {
                return [null, 'kurung siku "[ ]" tidak boleh kosong'];
            }
            $stack[count($stack) - 1][] = $group;
        } else {
            $stack[count($stack) - 1][] = $part;
        }
    }
    if (count($stack) !== 1) {
        return [null, 'ada "[" yang belum ditutup "]"'];
    }
    return [$stack[0], null];
}

// Uraikan teks not angka menjadi baris nada.
// Hasil:
//   'rows'     => [['bar', 'beat', 'start_beat', 'duration_beats', 'degree', 'octave_shift', 'label'], ...]
//                 'beat' = posisi di dalam birama, mulai dari 0.
//   'errors'   => daftar pesan error (bila ada, rows tidak boleh disimpan)
//   'warnings' => daftar peringatan
//   'total_beats' => panjang seluruh teks dalam ketukan
function parse_melody_notation(string $text, int $beatsPerBar): array
{
    $result = ['rows' => [], 'errors' => [], 'warnings' => [], 'total_beats' => 0.0];
    if (mb_strlen($text) > NOTATION_MAX_CHARS) {
        $result['errors'][] = 'Teks terlalu panjang (maksimal ' . NOTATION_MAX_CHARS . ' karakter).';
        return $result;
    }
    $bars = notation_split_bars($text);
    if ($bars === []) {
        $result['errors'][] = 'Teks masih kosong. Tulis not angka, misalnya: 1 2 3 4 |';
        return $result;
    }

    // Semua kejadian (nada dan istirahat) berurutan. Istirahat disimpan juga
    // supaya "." setelah 0 memperpanjang istirahat, bukan nada sebelumnya.
    $events = [];
    $cursor = 0.0;           // ketukan saat ini sejak awal teks
    $barStarts = [];

    // Proses satu daftar token yang mengisi $span ketukan.
    $walk = function (array $tokens, float $span, int $barNo, float $barStart) use (&$walk, &$events, &$cursor, &$result): void {
        $each = $span / count($tokens);
        foreach ($tokens as $token) {
            $where = notation_position($barNo, $cursor - $barStart);
            if (!notation_is_exact($each)) {
                $result['errors'][] = ucfirst($where) . ': pembagian ketukan tidak rata (hasilnya ' . round($each, 4)
                    . ' ketuk). Gunakan 2 atau 4 nada di dalam satu kurung siku.';
                $cursor += $each;
                continue;
            }
            if (is_array($token)) {
                $walk($token, $each, $barNo, $barStart);
                continue;
            }
            if ($token === '.') {
                if ($events === []) {
                    $result['errors'][] = ucfirst($where) . ': tanda "." di awal lagu tidak punya nada untuk diperpanjang.';
                } else {
                    $events[count($events) - 1]['duration_beats'] += $each;
                }
            } elseif (preg_match("/^([0-7])('+|,+)?$/", $token, $m)) {
                $marks = $m[2] ?? '';
                $events[] = [
                    'bar'            => $barNo,
                    'beat'           => $cursor - $barStart,
                    'start_beat'     => $cursor,
                    'duration_beats' => $each,
                    'degree'         => (int) $m[1],
                    'octave_shift'   => $marks === '' ? 0 : ($marks[0] === "'" ? strlen($marks) : -strlen($marks)),
                ];
            } else {
                $result['errors'][] = ucfirst($where) . ': token "' . $token . '" tidak dikenal. '
                    . 'Yang boleh: 0-7, 1\' (oktaf tinggi), 5, (oktaf rendah), ".", "[ ]", dan "|".';
            }
            $cursor += $each;
        }
    };

    foreach ($bars as $index => $barText) {
        $barNo = $index + 1;
        [$tokens, $error] = notation_tokenize_bar($barText);
        if ($error !== null) {
            $result['errors'][] = 'Birama ' . $barNo . ': ' . $error . '.';
            continue;
        }
        $barStart = $cursor;
        $barStarts[$barNo] = $barStart;
        // Setiap token tingkat atas = satu ketukan.
        $walk($tokens, (float) count($tokens), $barNo, $barStart);

        $length = $cursor - $barStart;
        $isFirst = $barNo === 1;
        $isLast = $barNo === count($bars);
        if ($length > $beatsPerBar + 1e-9) {
            $result['errors'][] = "Birama {$barNo} berisi " . notation_beats_text($length) . " ketukan, padahal satu birama {$beatsPerBar} ketukan.";
        } elseif ($length < $beatsPerBar - 1e-9) {
            if ($isFirst) {
                $result['warnings'][] = "Birama 1 hanya " . notation_beats_text($length) . " ketukan. Dianggap birama gantung "
                    . "(ketukan awal), jadi nadanya ditaruh di akhir birama pertama.";
            } elseif ($isLast) {
                $result['warnings'][] = "Birama terakhir ({$barNo}) hanya " . notation_beats_text($length) . " ketukan.";
            } else {
                $result['errors'][] = "Birama {$barNo} hanya berisi " . notation_beats_text($length) . " ketukan, padahal satu birama {$beatsPerBar} ketukan.";
            }
        }
    }

    // Birama gantung: geser semua nada supaya birama gantung berakhir tepat di
    // akhir birama pertama. Dengan begitu garis birama lagu tetap sejajar dengan
    // part lain (akor dan drum) yang ditulis birama penuh.
    $shift = 0.0;
    if (isset($barStarts[2]) && $barStarts[2] < $beatsPerBar) {
        $shift = $beatsPerBar - $barStarts[2];
    }

    foreach ($events as $event) {
        if ($event['degree'] === 0) {
            continue;   // istirahat tidak disimpan
        }
        $event['start_beat'] += $shift;
        if ($event['bar'] === 1) {
            $event['beat'] += $shift;
        }
        $event['label'] = degree_label($event['degree'], $event['octave_shift']);
        $result['rows'][] = $event;
    }
    $result['total_beats'] = $cursor + $shift;

    if ($result['rows'] === [] && $result['errors'] === []) {
        $result['errors'][] = 'Tidak ada nada sama sekali (semuanya istirahat).';
    }
    return $result;
}

// ---------------------------------------------------------------------
// Akor
// ---------------------------------------------------------------------

// Uraikan teks akor. $transpose = nada dasar lagu (default_transpose).
// Hasil sama seperti parse_melody_notation(), tetapi setiap baris berisi
// 'degree' (nada dasar akor), 'written' (nama yang ditulis) dan 'label'
// (nama akor diatonis yang benar-benar dimainkan).
function parse_chord_notation(string $text, int $beatsPerBar, int $transpose): array
{
    $result = ['rows' => [], 'errors' => [], 'warnings' => [], 'total_beats' => 0.0];
    if (mb_strlen($text) > NOTATION_MAX_CHARS) {
        $result['errors'][] = 'Teks terlalu panjang (maksimal ' . NOTATION_MAX_CHARS . ' karakter).';
        return $result;
    }
    $bars = notation_split_bars($text);
    if ($bars === []) {
        $result['errors'][] = 'Teks masih kosong. Tulis nama akor per birama, misalnya: C | F C | G C |';
        return $result;
    }

    $key = 'Do = ' . key_name($transpose);
    foreach ($bars as $index => $barText) {
        $barNo = $index + 1;
        $barStart = $index * $beatsPerBar;
        $tokens = preg_split('/\s+/', $barText);
        $each = (float) $beatsPerBar / count($tokens);
        if (!notation_is_exact($each)) {
            $result['errors'][] = "Birama {$barNo}: " . count($tokens) . " akor tidak bisa dibagi rata dalam {$beatsPerBar} ketukan. "
                . 'Pakai "-" untuk menyusun, misalnya "C - - G".';
            continue;
        }

        foreach ($tokens as $i => $token) {
            $beat = $i * $each;
            $where = ucfirst(notation_position($barNo, $beat));
            if ($token === '-') {
                if ($result['rows'] === []) {
                    $result['errors'][] = $where . ': tanda "-" di awal tidak punya akor untuk dilanjutkan.';
                } else {
                    $result['rows'][count($result['rows']) - 1]['duration_beats'] += $each;
                }
                continue;
            }
            $chord = parse_chord_symbol($token);
            if ($chord === null) {
                $result['errors'][] = $where . ': akor "' . $token . '" tidak dikenal. Contoh yang benar: C, Dm, F#m, Bb, Bdim.';
                continue;
            }
            $degree = pitch_class_to_degree($chord['pc'], $transpose);
            if ($degree === null) {
                $result['errors'][] = $where . ': akor ' . $token . ' tidak ada di tangga nada ' . $key
                    . '. Periksa nada dasar lagu, atau ganti akornya.';
                continue;
            }
            $diatonic = chord_name($degree, $transpose);
            if ($chord['quality'] !== DIATONIC_QUALITY[$degree]) {
                $result['warnings'][] = $where . ': ' . $token . ' tidak diatonis di ' . $key . '. Mesin akor akan memainkan ' . $diatonic . '.';
            } elseif ($chord['extra'] !== '') {
                $result['warnings'][] = $where . ': ' . $token . ' dimainkan sebagai akor tiga nada ' . $diatonic . '.';
            }
            $result['rows'][] = [
                'bar'            => $barNo,
                'beat'           => $beat,
                'start_beat'     => (float) $barStart + $beat,
                'duration_beats' => $each,
                'degree'         => $degree,
                'octave_shift'   => 0,
                'written'        => $token,
                'label'          => $diatonic,
            ];
        }
    }
    $result['total_beats'] = (float) count($bars) * $beatsPerBar;
    return $result;
}

// ---------------------------------------------------------------------
// Dari derajat ke bunyi di database
// ---------------------------------------------------------------------

// Cari sound_id untuk setiap baris berdasarkan kode bunyi (huruf + oktaf).
//   $rows     : hasil parse_*_notation()
//   $sounds   : bunyi instrumen tujuan, [['id' => .., 'code' => 'C4'], ...]
//   $doOctave : oktaf Do instrumen (lihat instrument_do_octave())
// Hasil: ['rows' => baris + 'sound_id' + 'code', 'errors' => [...]]
function resolve_notation_sounds(array $rows, array $sounds, int $doOctave, string $instrumentName): array
{
    $idByCode = array_column($sounds, 'id', 'code');

    // Daftar nada yang tersedia untuk pesan error, misalnya "5, sampai 1'".
    $available = [];
    foreach ($sounds as $sound) {
        $deg = code_to_degree($sound['code'], $doOctave);
        if ($deg !== null) {
            $available[] = $deg;
        }
    }
    usort($available, fn($a, $b) => [$a['octave_shift'], $a['degree']] <=> [$b['octave_shift'], $b['degree']]);
    $range = $available === []
        ? 'tidak ada'
        : degree_label(...array_values(reset($available))) . ' sampai ' . degree_label(...array_values(end($available)));

    $resolved = ['rows' => [], 'errors' => []];
    foreach ($rows as $row) {
        $code = degree_to_code($row['degree'], $row['octave_shift'], $doOctave);
        if (!isset($idByCode[$code])) {
            $resolved['errors'][] = ucfirst(notation_position($row['bar'], $row['beat'])) . ': nada ' . $row['label']
                . ' (' . $code . ') tidak tersedia di ' . $instrumentName . '. Nada yang tersedia: ' . $range
                . '. Ubah nada dasar lagu atau tulis ulang nada ini satu oktaf lebih tinggi/rendah.';
            continue;
        }
        $row['code'] = $code;
        $row['sound_id'] = (int) $idByCode[$code];
        $resolved['rows'][] = $row;
    }
    return $resolved;
}

// Peringatan (bukan error) untuk nada yang tidak punya gestur kamera aktif.
// Nada seperti itu tetap tersimpan dan bisa dimainkan lewat keyboard, tetapi
// tidak lewat kamera. Satu peringatan per nada, dengan jumlah dan posisi pertamanya.
//   $rows            : baris yang sudah punya 'sound_id', 'code', 'label' (hasil resolve)
//   $gestureSoundIds : id bunyi yang punya gestur aktif di instrumen ini
function notation_keyboard_only_warnings(array $rows, array $gestureSoundIds): array
{
    $withGesture = array_flip(array_map('intval', $gestureSoundIds));
    $missing = [];
    foreach ($rows as $row) {
        $id = (int) $row['sound_id'];
        if (isset($withGesture[$id])) {
            continue;
        }
        if (!isset($missing[$id])) {
            $missing[$id] = ['row' => $row, 'count' => 0];
        }
        $missing[$id]['count']++;
    }
    $warnings = [];
    foreach ($missing as $item) {
        $row = $item['row'];
        $warnings[] = 'Nada ' . $row['label'] . ' (' . ($row['code'] ?? '') . ') belum punya gestur kamera: dipakai '
            . $item['count'] . ' kali, pertama di ' . notation_position($row['bar'], $row['beat'])
            . '. Part ini hanya bisa dimainkan penuh lewat keyboard.';
    }
    return $warnings;
}

// Angka ketukan tanpa nol berlebih: 4.0 -> "4", 1.5 -> "1,5".
function notation_beats_text(float $beats): string
{
    return str_replace('.', ',', rtrim(rtrim(number_format($beats, 3, '.', ''), '0'), '.'));
}
