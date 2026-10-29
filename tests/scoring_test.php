<?php
// Uji fungsi penilaian (includes/scoring.php). Jalankan dari terminal:
//   php tests/scoring_test.php
// Tidak butuh database atau server.

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require __DIR__ . '/../includes/scoring.php';

$failures = 0;

function check(string $name, mixed $actual, mixed $expected): void
{
    global $failures;
    if ($actual === $expected) {
        echo "LULUS  $name\n";
    } else {
        $failures++;
        echo "GAGAL  $name\n       hasil: " . var_export($actual, true) . "\n       harap: " . var_export($expected, true) . "\n";
    }
}

$note = fn(int $sound, int $t) => ['sound_id' => $sound, 't_ms' => $t];

// 80 bpm: satu ketukan = 750 ms.
check('notes_to_ms mengubah ketukan ke ms dan mengurutkan',
    notes_to_ms([['sound_id' => 2, 'start_beat' => '2.000'], ['sound_id' => 1, 'start_beat' => '0.500']], 80),
    [$note(1, 375), $note(2, 1500)]);

$expected = [$note(1, 0), $note(1, 750), $note(5, 1500)];

check('semua tepat waktu = 100%',
    match_performance($expected, [$note(1, 0), $note(1, 750), $note(5, 1500)]),
    ['total_notes' => 3, 'hits' => 3, 'misses' => 0, 'accuracy' => 100.0]);

check('tepat di batas toleransi (250 ms) masih kena',
    match_performance([$note(1, 1000)], [$note(1, 1250)])['hits'], 1);

check('lewat 1 ms dari toleransi tidak kena',
    match_performance([$note(1, 1000)], [$note(1, 1251)])['hits'], 0);

check('bunyi salah tidak dihitung',
    match_performance([$note(1, 1000)], [$note(2, 1000)])['hits'], 0);

check('satu event tidak bisa dipakai untuk dua nada',
    match_performance([$note(1, 0), $note(1, 200)], [$note(1, 100)]),
    ['total_notes' => 2, 'hits' => 1, 'misses' => 1, 'accuracy' => 50.0]);

check('event paling dekat yang dipilih, sisanya untuk nada berikutnya',
    match_performance([$note(1, 0), $note(1, 300)], [$note(1, 280), $note(1, 20)])['hits'], 2);

check('tanpa event = 0%',
    match_performance($expected, []),
    ['total_notes' => 3, 'hits' => 0, 'misses' => 3, 'accuracy' => 0.0]);

check('pembulatan 2 desimal (1 dari 3)',
    match_performance($expected, [$note(1, 0)])['accuracy'], 33.33);

check('partitur kosong tidak membagi dengan nol',
    match_performance([], [$note(1, 0)])['accuracy'], 0.0);

echo $failures === 0 ? "\nSemua uji lulus.\n" : "\n{$failures} uji gagal.\n";
exit($failures === 0 ? 0 : 1);
