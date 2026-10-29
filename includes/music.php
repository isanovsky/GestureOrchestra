<?php
// Teori musik dasar untuk not angka. Semua fungsi MURNI (tanpa database),
// dipakai oleh importer partitur, editor admin, halaman publik, dan penilaian.
//
// Istilah:
// - derajat (degree): 1 = Do, 2 = Re, ... 7 = Si (sistem do bergerak).
// - octave_shift : 0 = oktaf Do instrumen, -1 = bertitik bawah, 1 = bertitik atas.
// - transpose    : nada dasar lagu dalam semitone dari C. Do = D berarti 2.
// - pitch class  : 0 = C, 1 = C#, ... 11 = B.

// Huruf nada untuk derajat 1 sampai 7, dan jaraknya (semitone) dari C.
const DEGREE_LETTERS = [1 => 'C', 2 => 'D', 3 => 'E', 4 => 'F', 5 => 'G', 6 => 'A', 7 => 'B'];
const DEGREE_SEMITONES = [1 => 0, 2 => 2, 3 => 4, 4 => 5, 5 => 7, 6 => 9, 7 => 11];

// Jenis akor diatonis per derajat di tangga nada mayor:
// I mayor, ii minor, iii minor, IV mayor, V mayor, vi minor, vii diminished.
const DIATONIC_QUALITY = [1 => '', 2 => 'm', 3 => 'm', 4 => '', 5 => '', 6 => 'm', 7 => 'dim'];

// Nama nada per pitch class, dengan ejaan yang lazim di buku lagu.
const PITCH_NAMES = ['C', 'C#', 'D', 'Eb', 'E', 'F', 'F#', 'G', 'Ab', 'A', 'Bb', 'B'];

// Pilihan nada dasar untuk form admin: nilai transpose => label.
// Arah transpose dipilih yang paling dekat dengan C (maksimal 6 semitone).
const KEY_OPTIONS = [
    0 => 'Do = C', 1 => 'Do = C#', 2 => 'Do = D', 3 => 'Do = Eb', 4 => 'Do = E', 5 => 'Do = F',
    6 => 'Do = F#', -5 => 'Do = G', -4 => 'Do = Ab', -3 => 'Do = A', -2 => 'Do = Bb', -1 => 'Do = B',
];

// Nama nada dasar, misalnya key_name(2) = 'D' (dipakai sebagai "Do = D").
function key_name(int $transpose): string
{
    return PITCH_NAMES[(($transpose % 12) + 12) % 12];
}

// Pecah kode bunyi seperti 'G3' atau 'F#4' menjadi huruf, tanda kres, dan oktaf.
// Kode drum (KICK, RIM, ...) menghasilkan null.
function parse_sound_code(string $code): ?array
{
    if (!preg_match('/^([A-G])(#?)(-?\d)$/', $code, $m)) {
        return null;
    }
    return ['letter' => $m[1], 'sharp' => $m[2] === '#', 'octave' => (int) $m[3]];
}

// Derajat dan pergeseran oktaf sebuah kode bunyi, relatif terhadap oktaf Do
// instrumen. Contoh dengan Do = C4: 'G3' -> derajat 5, octave_shift -1.
// Nada kromatis (berkres) tidak punya derajat diatonis, hasilnya null.
function code_to_degree(string $code, int $doOctave): ?array
{
    $parsed = parse_sound_code($code);
    if ($parsed === null || $parsed['sharp']) {
        return null;
    }
    return [
        'degree'       => array_search($parsed['letter'], DEGREE_LETTERS, true),
        'octave_shift' => $parsed['octave'] - $doOctave,
    ];
}

// Kebalikan code_to_degree(): derajat 5, shift -1, Do = C4 -> 'G3'.
function degree_to_code(int $degree, int $octaveShift, int $doOctave): string
{
    return DEGREE_LETTERS[$degree] . ($doOctave + $octaveShift);
}

// Tulisan not angka, misalnya derajat 5 shift -1 -> "5," dan derajat 1 shift 1 -> "1'".
function degree_label(int $degree, int $octaveShift): string
{
    return $degree . str_repeat($octaveShift > 0 ? "'" : ',', abs($octaveShift));
}

// Oktaf Do sebuah instrumen. Aturan: jari 1 selalu Do, jadi oktaf Do diambil
// dari bunyi berhuruf C yang dipetakan ke 1 jari (piano C4, strings C5).
// Bila tidak ada, dipakai C dengan oktaf terendah. Bukan dari sort_order.
//   $sounds   : [['id' => 1, 'code' => 'C4'], ...]
//   $gestures : [['finger_count' => 1, 'sound_id' => 1], ...]
function instrument_do_octave(array $sounds, array $gestures): ?int
{
    $codeById = array_column($sounds, 'code', 'id');
    foreach ($gestures as $gesture) {
        if ((int) $gesture['finger_count'] === 1) {
            $parsed = parse_sound_code($codeById[$gesture['sound_id']] ?? '');
            if ($parsed !== null && $parsed['letter'] === 'C' && !$parsed['sharp']) {
                return $parsed['octave'];
            }
        }
    }
    $lowest = null;
    foreach ($sounds as $sound) {
        $parsed = parse_sound_code($sound['code']);
        if ($parsed !== null && $parsed['letter'] === 'C' && !$parsed['sharp']) {
            $lowest = $lowest === null ? $parsed['octave'] : min($lowest, $parsed['octave']);
        }
    }
    return $lowest;
}

// Nama akor diatonis untuk derajat tertentu di nada dasar tertentu.
// Contoh: chord_name(2, 0) = 'Dm', chord_name(5, -5) = 'D' (lagu Do = G).
function chord_name(int $degree, int $transpose): string
{
    return key_name(DEGREE_SEMITONES[$degree] + $transpose) . DIATONIC_QUALITY[$degree];
}

// Uraikan simbol akor yang ditulis admin, misalnya 'Am', 'F#m', 'Bbdim', 'G7'.
// Hasil: pitch class nada dasar, jenis (''/'m'/'dim'), dan tambahan (misalnya '7').
// Simbol yang tidak dikenali menghasilkan null.
function parse_chord_symbol(string $symbol): ?array
{
    if (!preg_match('/^([A-G])(#|b)?(m(?!aj)|dim|o)?(7|maj7|sus2|sus4|add9|6|9)?$/', $symbol, $m)) {
        return null;
    }
    $pc = DEGREE_SEMITONES[array_search($m[1], DEGREE_LETTERS, true)];
    if (($m[2] ?? '') === '#') {
        $pc++;
    } elseif (($m[2] ?? '') === 'b') {
        $pc--;
    }
    $quality = $m[3] ?? '';
    return [
        'pc'      => ($pc + 12) % 12,
        'quality' => $quality === 'o' ? 'dim' : $quality,
        'extra'   => $m[4] ?? '',
    ];
}

// Derajat sebuah pitch class di lagu dengan transpose tertentu.
// Contoh: D (pc 2) di lagu Do = G (transpose -5) adalah derajat 5.
// Pitch class di luar tangga nada (misalnya Bb di Do = C) menghasilkan null.
function pitch_class_to_degree(int $pc, int $transpose): ?int
{
    $relative = (($pc - $transpose) % 12 + 12) % 12;
    $degree = array_search($relative, DEGREE_SEMITONES, true);
    return $degree === false ? null : $degree;
}
