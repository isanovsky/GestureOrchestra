<?php
// Penilaian permainan. Semua fungsi di sini MURNI: hanya menerima array dan
// mengembalikan array, tanpa database atau session. Karena itu mudah diuji
// (lihat tests/scoring_and_import_test.php) dan mudah dijelaskan.
//
// Ada dua cara menilai, sesuai gaya part:
// - score_note_part()  : part melodi ('notes'), setiap nada harus DIMAINKAN
//                        tepat waktu (dicocokkan dengan event).
// - score_chord_part() : part akor ('chords'), setiap akor harus SEDANG AKTIF
//                        (ditahan) cukup lama selama durasinya.

// Jendela toleransi melodi: nada dianggap kena bila dimainkan paling cepat
// 250 ms sebelum atau paling lambat 250 ms sesudah waktu yang seharusnya.
const SCORE_TOLERANCE_MS = 250;
// Akor dianggap kena bila akor yang benar aktif minimal 50% dari durasinya.
const CHORD_MIN_COVERAGE = 0.5;
// Pilihan tempo latihan (persen dari bpm asli lagu).
const TEMPO_OPTIONS = [50, 60, 70, 80, 90, 100];

// Tempo efektif. Contoh: 80 bpm dimainkan 50% -> 40 bpm.
function effective_bpm(int $bpm, int $tempoPercent): float
{
    return $bpm * $tempoPercent / 100;
}

// Lama satu ketukan dalam milidetik. Contoh: 80 bpm -> 750 ms.
function beat_ms(float $bpm): float
{
    return 60000 / $bpm;
}

// Ubah partitur (ketukan) menjadi waktu (milidetik) pada tempo efektif.
// Masukan : [['sound_id' => 1, 'start_beat' => '2.000', 'duration_beats' => '1.000'], ...]
// Keluaran: [['sound_id' => 1, 't_ms' => 1500, 'end_ms' => 2250], ...] terurut dari yang paling awal.
function notes_to_ms(array $notes, float $bpm): array
{
    $result = [];
    foreach ($notes as $note) {
        $start = (float) $note['start_beat'];
        $duration = (float) ($note['duration_beats'] ?? 0);
        $result[] = [
            'sound_id' => (int) $note['sound_id'],
            't_ms'     => (int) round($start * beat_ms($bpm)),
            'end_ms'   => (int) round(($start + $duration) * beat_ms($bpm)),
        ];
    }
    usort($result, fn(array $a, array $b) => $a['t_ms'] <=> $b['t_ms']);
    return $result;
}

// Ringkasan nilai yang sama untuk kedua cara menilai.
function score_summary(int $total, int $hits): array
{
    return [
        'total_notes' => $total,
        'hits'        => $hits,
        'misses'      => $total - $hits,
        'accuracy'    => $total === 0 ? 0.0 : round($hits / $total * 100, 2),
    ];
}

// Nilai part MELODI.
//
// Aturan:
// - Nada dianggap kena bila ada event dengan sound_id sama dan selisih
//   waktunya paling banyak $toleranceMs.
// - Bila ada beberapa event yang cocok, dipilih yang selisihnya paling kecil.
// - Setiap event hanya boleh dipakai satu kali, jadi menahan atau
//   mengulang satu gestur tidak bisa "menghabiskan" banyak nada.
// - Event lepas (sound_id null) diabaikan.
//
// Masukan : $expected = [['sound_id' => int, 't_ms' => int], ...]
//           $events   = [['sound_id' => int|null, 't_ms' => int], ...]
function score_note_part(array $expected, array $events, int $toleranceMs = SCORE_TOLERANCE_MS): array
{
    $used = [];   // indeks event yang sudah dipakai => true
    $hits = 0;

    foreach ($expected as $note) {
        $bestIndex = null;
        $bestDiff = null;

        foreach ($events as $i => $event) {
            if (isset($used[$i]) || $event['sound_id'] === null || $event['sound_id'] !== $note['sound_id']) {
                continue;
            }
            $diff = abs($event['t_ms'] - $note['t_ms']);
            if ($diff <= $toleranceMs && ($bestDiff === null || $diff < $bestDiff)) {
                $bestIndex = $i;
                $bestDiff = $diff;
            }
        }

        if ($bestIndex !== null) {
            $used[$bestIndex] = true;
            $hits++;
        }
    }

    return score_summary(count($expected), $hits);
}

// Nilai part AKOR, berbasis keadaan.
//
// Langkah:
// 1. Dari event, susun garis waktu "akor apa yang sedang aktif": setiap event
//    berlaku sejak waktunya sampai event berikutnya. Event lepas (root null)
//    berarti tidak ada akor aktif.
// 2. Untuk setiap entri partitur, hitung berapa milidetik akor dengan nada
//    dasar yang benar aktif di dalam rentang entri itu.
// 3. Entri dianggap kena bila waktu itu minimal $minCoverage dari durasinya.
// Akibatnya, akor yang sama di dua entri berurutan (C lalu C) cukup ditahan.
//
// 'root' = huruf nada dasar akor (misalnya 'C'), bukan sound_id, supaya
// akor I dari 1 jari (C4) dan dari 8 jari (C5) dihitung sebagai akor yang sama.
//
// Masukan : $entries = [['root' => 'C', 't_ms' => int, 'end_ms' => int], ...]
//           $events  = [['root' => 'C'|null, 't_ms' => int], ...]
function score_chord_part(array $entries, array $events, float $minCoverage = CHORD_MIN_COVERAGE): array
{
    usort($events, fn(array $a, array $b) => $a['t_ms'] <=> $b['t_ms']);

    // Garis waktu: [mulai, selesai, root]. Event terakhir berlaku sampai tak hingga.
    $segments = [];
    foreach ($events as $i => $event) {
        if ($event['root'] === null) {
            continue;
        }
        $end = isset($events[$i + 1]) ? $events[$i + 1]['t_ms'] : PHP_INT_MAX;
        $segments[] = [$event['t_ms'], $end, $event['root']];
    }

    $hits = 0;
    foreach ($entries as $entry) {
        $covered = 0;
        foreach ($segments as [$start, $end, $root]) {
            if ($root === $entry['root']) {
                // Panjang irisan rentang segmen dengan rentang entri.
                $covered += max(0, min($end, $entry['end_ms']) - max($start, $entry['t_ms']));
            }
        }
        $length = $entry['end_ms'] - $entry['t_ms'];
        if ($length > 0 && $covered >= $length * $minCoverage) {
            $hits++;
        }
    }

    return score_summary(count($entries), $hits);
}
