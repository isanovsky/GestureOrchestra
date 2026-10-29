<?php
// Mode Arranger: konstanta, pembaca pola, dan pengambilan style dari database.
//
// Pembaca pola di sini dipakai untuk VALIDASI saat admin menyimpan. Browser
// membaca pola yang sama dengan assets/js/arranger-pattern.js. Keduanya harus
// menerima dan menolak teks yang sama; tests/fixtures/arranger-patterns.json
// dijalankan di uji PHP dan uji JS supaya aturannya tidak berbeda.
//
// Format pola: satu kejadian per baris, # sampai akhir baris = komentar.
//   drum : <ketukan> <KODE> [velocity]
//   bass : <ketukan> <nada> [durasi] [velocity]
//   akor : <ketukan> <nada> [durasi] [velocity]
// Ketukan: angka dari 0 (desimal 1.5 atau pecahan 4/3), kurang dari panjang bagian.
// Nada: gabungan R (dasar), 3 (terts), 5 (kuint), 8 (oktaf), boleh diakhiri '-'
// (satu oktaf lebih rendah). Bass hanya satu nada per kejadian.
// Durasi dalam ketukan (bawaan 1, maksimal 32). Velocity lebih dari 0 sampai 1 (bawaan 1).

const ARRANGER_SECTIONS = [
    'intro'  => 'Intro',
    'main_a' => 'Main A',
    'main_b' => 'Main B',
    'fill'   => 'Fill',
    'ending' => 'Ending',
];

const ARRANGER_TRACKS = [
    'drum'  => 'Drum',
    'bass'  => 'Bass',
    'chord' => 'Akor',
];

const ARRANGER_CHORD_VOICES = [
    'piano' => 'Piano (pukulan meluruh)',
    'pad'   => 'Pad (bunyi panjang mengalun)',
    'pluck' => 'Petik (gitar, pendek)',
];

// Kode drum yang dikenal audio-engine.js. KDUT, KTAK, TAMB, OHAT khusus arranger.
const ARRANGER_DRUM_CODES = ['KICK', 'SNARE', 'HIHAT', 'OHAT', 'RIM', 'TOM', 'CRASH', 'CYMBAL', 'KDUT', 'KTAK', 'TAMB'];

const ARRANGER_MAX_EVENTS = 128;
const ARRANGER_MAX_DURATION = 32;

// Angka ketukan/durasi: "2", "1.5", "4/3". Mengembalikan float atau null.
function arranger_number(string $token): ?float
{
    if (preg_match('/^\d{1,3}(\.\d{1,4})?$/', $token)) {
        return (float) $token;
    }
    if (preg_match('/^(\d{1,3})\/(\d{1,2})$/', $token, $m) && (int) $m[2] > 0) {
        return (int) $m[1] / (int) $m[2];
    }
    return null;
}

// Baca teks pola. Hasil: ['events' => [...], 'errors' => ['Baris 3: ...', ...]].
// Kejadian drum: ['beat', 'code', 'vel']; bass/akor: ['beat', 'tones', 'low', 'dur', 'vel'].
function arranger_parse_pattern(string $text, string $track, int $lengthBeats): array
{
    $events = [];
    $errors = [];
    $lines = preg_split('/\r\n|\r|\n/', $text);

    foreach ($lines as $index => $raw) {
        $lineNo = $index + 1;
        $line = trim(preg_replace('/#.*$/', '', $raw));
        if ($line === '') {
            continue;
        }
        $parts = preg_split('/\s+/', $line);

        $beat = arranger_number($parts[0]);
        if ($beat === null) {
            $errors[] = "Baris $lineNo: ketukan \"{$parts[0]}\" bukan angka (contoh: 0, 1.5, 4/3).";
            continue;
        }
        if ($beat >= $lengthBeats) {
            $errors[] = "Baris $lineNo: ketukan $parts[0] di luar bagian (panjang $lengthBeats ketukan, jadi 0 sampai di bawah $lengthBeats).";
            continue;
        }
        if (!isset($parts[1])) {
            $errors[] = "Baris $lineNo: isi kejadian belum ditulis.";
            continue;
        }

        if ($track === 'drum') {
            if (count($parts) > 3) {
                $errors[] = "Baris $lineNo: drum ditulis <ketukan> <KODE> [velocity].";
                continue;
            }
            $code = strtoupper($parts[1]);
            if (!in_array($code, ARRANGER_DRUM_CODES, true)) {
                $errors[] = "Baris $lineNo: kode drum \"{$parts[1]}\" tidak dikenal. Pilihan: " . implode(', ', ARRANGER_DRUM_CODES) . '.';
                continue;
            }
            $vel = arranger_velocity($parts[2] ?? null, $lineNo, $errors);
            if ($vel === null) {
                continue;
            }
            $events[] = ['beat' => $beat, 'code' => $code, 'vel' => $vel];
            continue;
        }

        if (count($parts) > 4) {
            $errors[] = "Baris $lineNo: ditulis <ketukan> <nada> [durasi] [velocity].";
            continue;
        }
        $pattern = $track === 'bass' ? '/^([R358])(-?)$/' : '/^([R358]{1,4})(-?)$/';
        if (!preg_match($pattern, strtoupper($parts[1]), $m) || count(array_unique(str_split($m[1]))) !== strlen($m[1])) {
            $errors[] = $track === 'bass'
                ? "Baris $lineNo: nada bass \"{$parts[1]}\" harus satu dari R, 3, 5, 8 (boleh diakhiri -)."
                : "Baris $lineNo: nada akor \"{$parts[1]}\" harus gabungan R, 3, 5, 8 tanpa pengulangan (boleh diakhiri -).";
            continue;
        }
        $dur = 1.0;
        if (isset($parts[2])) {
            $dur = arranger_number($parts[2]);
            if ($dur === null || $dur <= 0 || $dur > ARRANGER_MAX_DURATION) {
                $errors[] = "Baris $lineNo: durasi \"{$parts[2]}\" harus angka lebih dari 0 sampai " . ARRANGER_MAX_DURATION . '.';
                continue;
            }
        }
        $vel = arranger_velocity($parts[3] ?? null, $lineNo, $errors);
        if ($vel === null) {
            continue;
        }
        $events[] = ['beat' => $beat, 'tones' => $m[1], 'low' => $m[2] === '-', 'dur' => $dur, 'vel' => $vel];
    }

    if (count($events) > ARRANGER_MAX_EVENTS) {
        $errors[] = 'Terlalu banyak kejadian (' . count($events) . '), maksimal ' . ARRANGER_MAX_EVENTS . ' per pola.';
    }

    return ['events' => $events, 'errors' => $errors];
}

// Velocity: kosong = 1, selain itu angka lebih dari 0 sampai 1.
function arranger_velocity(?string $token, int $lineNo, array &$errors): ?float
{
    if ($token === null) {
        return 1.0;
    }
    if (!preg_match('/^(0(\.\d{1,3})?|1(\.0{1,3})?)$/', $token) || (float) $token <= 0) {
        $errors[] = "Baris $lineNo: velocity \"$token\" harus lebih dari 0 sampai 1 (contoh: 0.6).";
        return null;
    }
    return (float) $token;
}

// Style yang tayang dan punya Main A, untuk pilihan di halaman Arranger.
function fetch_published_arranger_styles(): array
{
    $styles = db()->query(
        "SELECT s.id, s.slug, s.name, s.description, s.default_bpm, s.beats_per_bar, s.chord_voice
         FROM arranger_styles s
         WHERE s.is_published = 1
           AND EXISTS (SELECT 1 FROM arranger_sections sec WHERE sec.style_id = s.id AND sec.section = 'main_a')
         ORDER BY s.sort_order, s.name"
    )->fetchAll();
    if ($styles === []) {
        return [];
    }

    $byId = [];
    foreach ($styles as $style) {
        $byId[(int) $style['id']] = [
            'id'            => (int) $style['id'],
            'slug'          => $style['slug'],
            'name'          => $style['name'],
            'description'   => $style['description'],
            'bpm'           => (int) $style['default_bpm'],
            'beats_per_bar' => (int) $style['beats_per_bar'],
            'chord_voice'   => $style['chord_voice'],
            'sections'      => [],
        ];
    }

    // Satu query untuk semua bagian dan pola (hemat request di hosting gratis).
    $placeholders = implode(',', array_fill(0, count($byId), '?'));
    $stmt = db()->prepare(
        "SELECT sec.style_id, sec.section, sec.length_beats, p.track, p.events
         FROM arranger_sections sec
         LEFT JOIN arranger_patterns p ON p.section_id = sec.id
         WHERE sec.style_id IN ($placeholders)"
    );
    $stmt->execute(array_keys($byId));
    foreach ($stmt->fetchAll() as $row) {
        $styleId = (int) $row['style_id'];
        $section = $row['section'];
        if (!isset($byId[$styleId]['sections'][$section])) {
            $byId[$styleId]['sections'][$section] = ['length' => (int) $row['length_beats'], 'tracks' => []];
        }
        if ($row['track'] !== null) {
            $byId[$styleId]['sections'][$section]['tracks'][$row['track']] = $row['events'];
        }
    }

    return array_values($byId);
}
