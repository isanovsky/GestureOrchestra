<?php
// Uji penilaian (includes/scoring.php) dan importer not angka (includes/notation.php).
// Jalankan dari terminal:
//   php tests/scoring_and_import_test.php
// Tidak butuh database atau server. Data pembanding (derajat, start_beat,
// duration_beats) dibaca langsung dari file migrasi, jadi kalau importer
// menghasilkan baris yang sama, berarti sama dengan isi database.

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require __DIR__ . '/../includes/scoring.php';
require __DIR__ . '/../includes/notation.php';
require __DIR__ . '/../includes/arranger.php';

$failures = 0;

function check(string $name, mixed $actual, mixed $expected): void
{
    global $failures;
    if ($actual === $expected) {
        echo "LULUS  $name\n";
        return;
    }
    $failures++;
    echo "GAGAL  $name\n       hasil: " . json_encode($actual) . "\n       harap: " . json_encode($expected) . "\n";
}

// Ambil isi VALUES dari INSERT INTO <tabel> ke-$nth di sebuah file SQL.
// Setiap tuple dikembalikan sebagai array angka (float).
function sql_values(string $file, string $table, int $nth): array
{
    $sql = preg_replace('/--.*$/m', '', file_get_contents($file));
    preg_match_all('/INSERT INTO ' . $table . ' \([^)]*\) VALUES(.*?);/s', $sql, $blocks);
    if (!isset($blocks[1][$nth])) {
        // Nama tabel di migrasi berubah atau INSERT-nya hilang: gagal dengan pesan jelas.
        echo "GAGAL  INSERT INTO $table ke-$nth tidak ditemukan di " . basename($file) . "\n";
        exit(1);
    }
    preg_match_all('/\(([^()]*)\)/', $blocks[1][$nth], $tuples);
    return array_map(fn($t) => array_map('floatval', array_map('trim', explode(',', $t))), $tuples[1]);
}

// Baris importer melodi -> [derajat, octave_shift, start, durasi]
function melody_tuples(array $rows): array
{
    return array_map(fn($r) => [(float) $r['degree'], (float) $r['octave_shift'], (float) $r['start_beat'], (float) $r['duration_beats']], $rows);
}

// Baris importer akor -> [derajat, start, durasi]
function chord_tuples(array $rows): array
{
    return array_map(fn($r) => [(float) $r['degree'], (float) $r['start_beat'], (float) $r['duration_beats']], $rows);
}

$m002 = __DIR__ . '/../database/migrations/002_revisi_musik.sql';
$m003 = __DIR__ . '/../database/migrations/003_lagu_tambahan.sql';
$m004 = __DIR__ . '/../database/migrations/004_perbaikan_lagu.sql';

// =====================================================================
// 1. Importer: tiga lagu harus identik dengan data migrasi
// =====================================================================
echo "--- Importer vs migrasi ---\n";

$twinkleMelody = <<<TXT
# Melodi (Do = C)
1 1 5 5 | 6 6 5 . | 4 4 3 3 | 2 2 1 . |
5 5 4 4 | 3 3 2 . | 5 5 4 4 | 3 3 2 . |
1 1 5 5 | 6 6 5 . | 4 4 3 3 | 2 2 1 . |
TXT;
$twinkleChords = <<<TXT
# Akor
C | F C | F C | G C |
C G | C G | C G | C G |
C | F C | F C | G C |
TXT;

$melody = parse_melody_notation($twinkleMelody, 4);
check('Twinkle melodi: tanpa error', $melody['errors'], []);
// Migrasi 002 tidak punya kolom octave_shift, jadi ditambahkan 0.
$expected = array_map(fn($t) => [$t[0], 0.0, $t[1], $t[2]], sql_values($m002, 'mig_tmp_melody', 0));
check('Twinkle melodi: 42 nada identik dengan migrasi 002', melody_tuples($melody['rows']), $expected);
check('Twinkle melodi: total 48 ketukan', $melody['total_beats'], 48.0);

$chords = parse_chord_notation($twinkleChords, 4, 0);
check('Twinkle akor: tanpa error dan peringatan', [$chords['errors'], $chords['warnings']], [[], []]);
check('Twinkle akor: 22 akor identik dengan migrasi 002', chord_tuples($chords['rows']), sql_values($m002, 'mig_tmp_chords', 0));

// Teks disalin dari komentar migrasi 003.
$odeMelody = <<<TXT
3 3 4 5 | 5 4 3 2 | 1 1 2 3 | 3 . 2 2 |
3 3 4 5 | 5 4 3 2 | 1 1 2 3 | 2 . 1 1 |
2 2 3 1 | 2 [3 4] 3 1 | 2 [3 4] 3 2 | 1 2 5, . |
3 3 4 5 | 5 4 3 2 | 1 1 2 3 | 2 . 1 1 |
TXT;
$odeChords = <<<TXT
C | G | C | C G | C | G | C | G C |
G | G C | G Am | G | C | G | C | G C |
TXT;
$melody = parse_melody_notation($odeMelody, 4);
check('Ode to Joy melodi ([3 4] dan 5,): identik dengan migrasi 003', melody_tuples($melody['rows']), sql_values($m003, 'mig_tmp_melody', 0));
$chords = parse_chord_notation($odeChords, 4, 0);
check('Ode to Joy akor: identik dengan migrasi 003', chord_tuples($chords['rows']), sql_values($m003, 'mig_tmp_chords', 0));

$kakaktuaMelody = <<<TXT
5 5 3 1' | 3 2 . . | 3 4 6 5 | 4 3 . . |
5 5 3 1' | 3 2 . . | 7 6 5 4 | 3 2 1 . |
TXT;
$melody = parse_melody_notation($kakaktuaMelody, 4);
check('Burung Kakaktua melodi (1\'): identik dengan migrasi 003', melody_tuples($melody['rows']), sql_values($m003, 'mig_tmp_melody', 1));
$chords = parse_chord_notation('C | G | G | C | C | G | G | C |', 4, 0);
check('Burung Kakaktua akor: identik dengan migrasi 003', chord_tuples($chords['rows']), sql_values($m003, 'mig_tmp_chords', 1));

// Migrasi 004: Ode to Joy disederhanakan dan akor Kakaktua diperbaiki.
$odeSimple = <<<TXT
3 3 4 5 | 5 4 3 2 | 1 1 2 3 | 3 . 2 2 |
3 3 4 5 | 5 4 3 2 | 1 1 2 3 | 2 . 1 1 |
2 2 3 1 | 2 3 3 1 | 2 3 3 2 | 1 2 5, . |
3 3 4 5 | 5 4 3 2 | 1 1 2 3 | 2 . 1 1 |
TXT;
$melody = parse_melody_notation($odeSimple, 4);
check('Ode to Joy disederhanakan: 60 nada identik dengan migrasi 004', melody_tuples($melody['rows']), sql_values($m004, 'mig_tmp_melody', 0));
$chords = parse_chord_notation('C | G | F | C | C | G | G | C |', 4, 0);
check('Burung Kakaktua akor diperbaiki: identik dengan migrasi 004', chord_tuples($chords['rows']), sql_values($m004, 'mig_tmp_chords', 0));

// =====================================================================
// 2. Importer: kasus khusus
// =====================================================================
echo "--- Importer: kasus khusus ---\n";

$r = parse_melody_notation('1 [. 2] 3 4 |', 4);
check('[. 2]: nada 1 sepanjang 1,5 ketuk, nada 2 setengah ketuk',
    array_map(fn($x) => [$x['label'], $x['start_beat'], $x['duration_beats']], $r['rows']),
    [['1', 0.0, 1.5], ['2', 1.5, 0.5], ['3', 2.0, 1.0], ['4', 3.0, 1.0]]);

$r = parse_melody_notation('1 [2 [3 4]] 5 6 |', 4);
check('Kurung bersarang: seperempat ketuk',
    array_map(fn($x) => [$x['label'], $x['start_beat'], $x['duration_beats']], $r['rows']),
    [['1', 0.0, 1.0], ['2', 1.0, 0.5], ['3', 1.5, 0.25], ['4', 1.75, 0.25], ['5', 2.0, 1.0], ['6', 3.0, 1.0]]);

$r = parse_melody_notation("1 0 . 2 | 3 4 5 6 |  # komentar", 4);
check('Istirahat 0 diperpanjang ".", tidak menjadi nada',
    array_map(fn($x) => [$x['label'], $x['start_beat'], $x['duration_beats']], $r['rows']),
    [['1', 0.0, 1.0], ['2', 3.0, 1.0], ['3', 4.0, 1.0], ['4', 5.0, 1.0], ['5', 6.0, 1.0], ['6', 7.0, 1.0]]);

$r = parse_melody_notation('5 | 1 2 3 4 | 5 . . . |', 4);
check('Birama gantung: peringatan, bukan error', [count($r['errors']), count($r['warnings'])], [0, 1]);
check('Birama gantung: nada pertama digeser ke ketukan 3 (akhir birama 1)', [$r['rows'][0]['start_beat'], $r['rows'][1]['start_beat']], [3.0, 4.0]);

$r = parse_melody_notation('1 2 3 4 5 | 1 2 3 4 |', 4);
check('Error: birama kelebihan ketukan', str_contains($r['errors'][0] ?? '', 'Birama 1 berisi 5 ketukan'), true);

$r = parse_melody_notation('1 2 3 4 | 1 2 | 1 2 3 4 |', 4);
check('Error: birama tengah kekurangan ketukan', str_contains($r['errors'][0] ?? '', 'Birama 2 hanya berisi 2 ketukan'), true);

$r = parse_melody_notation('1 2 x 4 |', 4);
check('Error: token tidak dikenal menyebut birama dan ketukan', str_contains($r['errors'][0] ?? '', 'Birama 1, ketukan 3: token "x" tidak dikenal'), true);

$r = parse_melody_notation('1 [2 3 4] 5 6 |', 4);
check('Error: pembagian tiga tidak bisa disimpan tepat', str_contains($r['errors'][0] ?? '', 'tidak rata'), true);

$r = parse_melody_notation('[1 2 3 |', 4);
check('Error: kurung tidak ditutup', str_contains($r['errors'][0] ?? '', 'belum ditutup'), true);

// Bunyi piano setelah migrasi 003: G3 (Sol rendah) sampai C5.
$pianoSounds = [];
foreach (['G3', 'C4', 'D4', 'E4', 'F4', 'G4', 'A4', 'B4', 'C5'] as $i => $code) {
    $pianoSounds[] = ['id' => 100 + $i, 'code' => $code];
}
$pianoGestures = [['finger_count' => 1, 'sound_id' => 101], ['finger_count' => 9, 'sound_id' => 100]];
$stringsSounds = array_map(fn($s) => ['id' => $s['id'] + 100, 'code' => $s['code'][0] . ((int) $s['code'][1] + 1)], $pianoSounds);
$stringsGestures = [['finger_count' => 1, 'sound_id' => 201]];

check('Oktaf Do piano dari gestur 1 jari = 4', instrument_do_octave($pianoSounds, $pianoGestures), 4);
check('Oktaf Do strings dari gestur 1 jari = 5', instrument_do_octave($stringsSounds, $stringsGestures), 5);

$r = resolve_notation_sounds(parse_melody_notation("5, 1 1' 5 |", 4)['rows'], $pianoSounds, 4, 'Piano');
check("Piano: 5, -> G3, 1 -> C4, 1' -> C5", array_column($r['rows'], 'code'), ['G3', 'C4', 'C5', 'G4']);
$r = resolve_notation_sounds(parse_melody_notation('5, 1 2 3 |', 4)['rows'], $stringsSounds, 5, 'Strings');
check('Strings: 5, -> G4', $r['rows'][0]['code'], 'G4');

$r = resolve_notation_sounds(parse_melody_notation('1 2 6, 3 |', 4)['rows'], $pianoSounds, 4, 'Piano');
check('Error: nada di luar jangkauan menyebut posisi dan saran',
    str_contains($r['errors'][0] ?? '', "Birama 1, ketukan 3: nada 6, (A3) tidak tersedia di Piano. Nada yang tersedia: 5, sampai 1'"), true);

// =====================================================================
// 3. Importer akor: nada dasar dan peringatan
// =====================================================================
echo "--- Importer akor ---\n";

$r = parse_chord_notation('G | D | Em | C D |', 4, -5);   // lagu Do = G
check('Do = G: G D Em C D -> derajat 1 5 6 4 5', array_column($r['rows'], 'degree'), [1, 5, 6, 4, 5]);
check('Do = G: tanpa peringatan', $r['warnings'], []);

$r = parse_chord_notation('C D | F - G - |', 4, 0);
check('D mayor di Do = C: peringatan, dimainkan Dm', str_contains($r['warnings'][0] ?? '', 'Mesin akor akan memainkan Dm'), true);
check('"-" melanjutkan akor: F 2 ketuk, G 2 ketuk',
    array_map(fn($x) => [$x['label'], $x['start_beat'], $x['duration_beats']], array_slice($r['rows'], 2)),
    [['F', 4.0, 2.0], ['G', 6.0, 2.0]]);

$r = parse_chord_notation('C Bb |', 4, 0);
check('Error: Bb tidak ada di tangga nada Do = C', str_contains($r['errors'][0] ?? '', 'tidak ada di tangga nada Do = C'), true);

$r = parse_chord_notation('C F G |', 4, 0);
check('Error: 3 akor tidak bisa dibagi rata di 4 ketukan', str_contains($r['errors'][0] ?? '', 'tidak bisa dibagi rata'), true);

$r = parse_chord_notation('C# F#m |', 4, 1);   // Do = C#: tanda # bukan komentar
check('C# tidak dianggap komentar', array_column($r['rows'], 'label'), ['C#', 'F#']);

// =====================================================================
// 4. Penilaian
// =====================================================================
echo "--- Penilaian ---\n";

$ev = fn($sound, int $t) => ['sound_id' => $sound, 't_ms' => $t];
$notes = [
    ['sound_id' => 1, 'start_beat' => '0', 'duration_beats' => '1'],
    ['sound_id' => 1, 'start_beat' => '1', 'duration_beats' => '1'],
    ['sound_id' => 5, 'start_beat' => '2', 'duration_beats' => '2'],
];
$expected100 = notes_to_ms($notes, effective_bpm(80, 100));   // 750 ms per ketukan

check('Melodi: semua tepat waktu = 100%',
    score_note_part($expected100, [$ev(1, 0), $ev(null, 400), $ev(1, 760), $ev(5, 1490), $ev(null, 2900)]),
    ['total_notes' => 3, 'hits' => 3, 'misses' => 0, 'accuracy' => 100.0]);

check('Melodi: semua meleset = 0%',
    score_note_part($expected100, [$ev(2, 0), $ev(1, 400), $ev(5, 2000)]),
    ['total_notes' => 3, 'hits' => 0, 'misses' => 3, 'accuracy' => 0.0]);

check('Melodi: batas toleransi 250 ms', [
    score_note_part([['sound_id' => 1, 't_ms' => 1000]], [$ev(1, 1250)])['hits'],
    score_note_part([['sound_id' => 1, 't_ms' => 1000]], [$ev(1, 1251)])['hits'],
], [1, 0]);

check('Melodi: satu event tidak bisa dipakai dua nada',
    score_note_part([['sound_id' => 1, 't_ms' => 0], ['sound_id' => 1, 't_ms' => 200]], [$ev(1, 100)])['hits'], 1);

// Tempo 50%: satu ketukan 1500 ms, jadi nada kedua di 1500 ms.
$expected50 = notes_to_ms($notes, effective_bpm(80, 50));
check('Tempo 50%: waktu nada menjadi dua kali lipat', array_column($expected50, 't_ms'), [0, 1500, 3000]);
check('Tempo 50%: bermain di tempo lambat = 100%',
    score_note_part($expected50, [$ev(1, 30), $ev(null, 1000), $ev(1, 1480), $ev(5, 3100)])['accuracy'], 100.0);
check('Tempo 50%: waktu tempo penuh tidak lagi cocok',
    score_note_part($expected50, [$ev(1, 0), $ev(1, 750), $ev(5, 1500)])['hits'], 1);

// Akor: C (2 ketuk) - C (2 ketuk) - G (4 ketuk) di 80 bpm.
$entries = [
    ['root' => 'C', 't_ms' => 0, 'end_ms' => 1500],
    ['root' => 'C', 't_ms' => 1500, 'end_ms' => 3000],
    ['root' => 'G', 't_ms' => 3000, 'end_ms' => 6000],
];
$chord = fn(?string $root, int $t) => ['root' => $root, 't_ms' => $t];

check('Akor: C ditahan melewati dua entri C, lalu G = 100%',
    score_chord_part($entries, [$chord('C', 80), $chord('G', 3100), $chord(null, 6000)]),
    ['total_notes' => 3, 'hits' => 3, 'misses' => 0, 'accuracy' => 100.0]);

check('Akor: dilepas di tengah entri kedua (kurang dari 50%) = entri kedua meleset',
    score_chord_part($entries, [$chord('C', 0), $chord(null, 2000), $chord('G', 3000)])['hits'], 2);

check('Akor: akor salah sepanjang lagu = 0%',
    score_chord_part($entries, [$chord('F', 0)])['accuracy'], 0.0);

check('Akor: terlambat 600 ms masih kena (aktif 60% dari entri pertama)',
    score_chord_part([['root' => 'C', 't_ms' => 0, 'end_ms' => 1500]], [$chord('C', 600)])['hits'], 1);

check('Akor: tempo 50% memakai tempo efektif',
    score_chord_part([['root' => 'C', 't_ms' => 0, 'end_ms' => (int) (2 * beat_ms(effective_bpm(80, 50)))]], [$chord('C', 1400)])['hits'], 1);

check('Partitur kosong tidak membagi dengan nol', score_note_part([], [$ev(1, 0)])['accuracy'], 0.0);

// ---------------------------------------------------------------------
// Rentang nada keyboard (migrasi 005): oktaf rendah dan tinggi
// ---------------------------------------------------------------------
echo "--- Rentang nada keyboard ---\n";

// Bunyi piano setelah migrasi 005: C3 sampai C6 (22 bunyi). id = nomor urut.
$pianoCodes = ['C3', 'D3', 'E3', 'F3', 'G3', 'A3', 'B3', 'C4', 'D4', 'E4', 'F4', 'G4', 'A4', 'B4',
    'C5', 'D5', 'E5', 'F5', 'G5', 'A5', 'B5', 'C6'];
$pianoSounds = [];
foreach ($pianoCodes as $i => $code) {
    $pianoSounds[] = ['id' => $i + 1, 'code' => $code];
}

$r = parse_melody_notation("1, 7, 1 7 | 1' 7' 1'' 0 |", 4);
check('Not angka: 1, 7, 1\' 7\' 1\'\' terbaca dengan oktafnya',
    array_map(fn($row) => [$row['degree'], $row['octave_shift']], $r['rows']),
    [[1, -1], [7, -1], [1, 0], [7, 0], [1, 1], [7, 1], [1, 2]]);

$resolved = resolve_notation_sounds($r['rows'], $pianoSounds, 4, 'Piano');
check('Oktaf rendah dan tinggi dicocokkan ke kode bunyi (bukan urutan bunyi)',
    array_column($resolved['rows'], 'code'), ['C3', 'B3', 'C4', 'B4', 'C5', 'B5', 'C6']);
check('Oktaf rendah dan tinggi tidak menghasilkan error', $resolved['errors'], []);

$r = parse_melody_notation("2'' 1 1 1 |", 4);
$resolved = resolve_notation_sounds($r['rows'], $pianoSounds, 4, 'Piano');
check('Nada di luar rentang (2\'\' = D6) ditolak dengan pesan rentang',
    count($resolved['errors']) === 1 && str_contains($resolved['errors'][0], "1, sampai 1''"), true);

// Gestur kamera: 1-8 jari = C4..C5, 9 jari = D5 (setelah migrasi 006).
$gestureSoundIds = [8, 9, 10, 11, 12, 13, 14, 15, 16];
$r = parse_melody_notation("1, 1 2 3 | 5, 5, 2' 1'' |", 4);
$resolved = resolve_notation_sounds($r['rows'], $pianoSounds, 4, 'Piano');
$warnings = notation_keyboard_only_warnings($resolved['rows'], $gestureSoundIds);
check('Peringatan hanya untuk nada tanpa gestur: Do rendah, Sol rendah, Do sangat tinggi', count($warnings), 3);
check('Peringatan Sol rendah menyebut jumlah dan posisi pertama',
    str_contains($warnings[1], 'dipakai 2 kali, pertama di birama 2, ketukan 1') && str_contains($warnings[1], 'hanya bisa dimainkan penuh lewat keyboard'), true);
check('Re tinggi (9 jari) tidak diberi peringatan', count(array_filter($warnings, fn($w) => str_contains($w, '(D5)'))), 0);

// Penilaian tetap memakai sound_id, jadi nada oktaf rendah/tinggi dinilai seperti nada lain.
$low = 1;      // C3
$high = 22;    // C6
$expected = notes_to_ms([
    ['sound_id' => $low, 'start_beat' => '0', 'duration_beats' => '1'],
    ['sound_id' => $high, 'start_beat' => '1', 'duration_beats' => '1'],
], 60.0);
check('Penilaian: Do rendah dan Do sangat tinggi kena bila sound_id cocok',
    score_note_part($expected, [$ev($low, 30), $ev($high, 980)])['hits'], 2);
check('Penilaian: Do (C4) tidak dihitung sebagai Do rendah (C3)',
    score_note_part($expected, [$ev(8, 0), $ev($high, 1000)])['hits'], 1);

// ---------------------------------------------------------------------
// Arranger (Fase 6 bagian D): pembaca pola dan data awal migrasi 007.
// Kasus yang sama dijalankan uji JS (tests/js/arranger.test.mjs), supaya
// validasi admin (PHP) dan pemutar di browser (JS) memakai aturan yang sama.
// ---------------------------------------------------------------------
$fixture = json_decode(file_get_contents(__DIR__ . '/fixtures/arranger-patterns.json'), true);
foreach ($fixture['cases'] as $i => $case) {
    $result = arranger_parse_pattern($case['text'], $case['track'], $case['length']);
    check("arranger: kasus pola #$i ({$case['track']})", [count($result['events']), $result['errors']], [$case['events'], $case['errors']]);
}

$arrangerTooMany = implode("\n", array_fill(0, ARRANGER_MAX_EVENTS + 1, '0 KICK'));
check('arranger: lebih dari 128 kejadian ditolak',
    arranger_parse_pattern($arrangerTooMany, 'drum', 4)['errors'],
    ['Terlalu banyak kejadian (129), maksimal 128 per pola.']);

// Data awal di 007: semua pola harus lolos validasi dengan panjang bagiannya.
$m007 = file_get_contents(__DIR__ . '/../database/migrations/007_arranger.sql');
preg_match_all("/SELECT '(\\w+)'(?: AS slug)?, '(\\w+)'(?: AS section)?, (\\d+)/", $m007, $lengthRows, PREG_SET_ORDER);
$seedLengths = [];
foreach ($lengthRows as $row) {
    $seedLengths[$row[1]][$row[2]] = (int) $row[3];
}
preg_match_all("/SELECT '(\\w+)'(?: AS slug)?, '(\\w+)'(?: AS section)?, '(\\w+)'(?: AS track)?,\\s*'([^']*)'/", $m007, $patternRows, PREG_SET_ORDER);
check('arranger: 007 punya 15 bagian dan 45 pola', [count($lengthRows), count($patternRows)], [15, 45]);
$seedErrors = [];
foreach ($patternRows as [, $slug, $section, $track, $events]) {
    $result = arranger_parse_pattern(str_replace('\n', "\n", $events), $track, $seedLengths[$slug][$section] ?? 0);
    if ($result['errors'] !== [] || $result['events'] === []) {
        $seedErrors[] = "$slug/$section/$track: " . implode(' ', $result['errors']);
    }
}
check('arranger: semua pola awal di 007 valid', $seedErrors, []);

// ---------------------------------------------------------------------
// Aturan SQL InfinityFree: user database tanpa hak istimewa.
// Komentar dibuang dulu, supaya catatan seperti "tidak mengizinkan CREATE
// TEMPORARY TABLE" di komentar tidak dianggap pelanggaran.
// ---------------------------------------------------------------------
$forbiddenSql = [
    'CREATE TEMPORARY TABLE' => '/\bCREATE\s+TEMPORARY\b/i',
    'stored procedure/function' => '/\bCREATE\s+(DEFINER\s*=\S+\s+)?(PROCEDURE|FUNCTION)\b/i',
    'trigger' => '/\bCREATE\s+(DEFINER\s*=\S+\s+)?TRIGGER\b/i',
    'event' => '/\bCREATE\s+(DEFINER\s*=\S+\s+)?EVENT\b/i',
    'CREATE DATABASE' => '/\bCREATE\s+(DATABASE|SCHEMA)\b/i',
    'USE' => '/^\s*USE\s+/im',
    'LOAD DATA' => '/\bLOAD\s+DATA\b/i',
    'SET GLOBAL' => '/\bSET\s+GLOBAL\b/i',
    'DELIMITER' => '/^\s*DELIMITER\b/im',
];
$sqlFiles = array_merge(glob(__DIR__ . '/../database/*.sql'), glob(__DIR__ . '/../database/migrations/*.sql'));
foreach ($sqlFiles as $file) {
    $sql = preg_replace(['/--.*$/m', '#/\*.*?\*/#s'], '', file_get_contents($file));
    $found = [];
    foreach ($forbiddenSql as $label => $pattern) {
        if (preg_match($pattern, $sql)) {
            $found[] = $label;
        }
    }
    check('SQL aman untuk InfinityFree: ' . basename($file), $found, []);
}

echo $failures === 0 ? "\nSemua uji lulus.\n" : "\n{$failures} uji gagal.\n";
exit($failures === 0 ? 0 : 1);
