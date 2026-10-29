<?php
// Impor partitur dari teks not angka untuk satu part.
//
// Alur: tempel teks -> "Pratinjau" (tabel hasil + error/peringatan) -> "Simpan".
// Saat menyimpan, teks diurai ULANG di server (pratinjau tidak dipercaya),
// lalu dalam satu transaksi: hapus semua nada lama part ini, sisipkan yang baru.
// Logika penguraian ada di includes/notation.php (fungsi murni, ada ujinya).

require __DIR__ . '/../includes/bootstrap.php';
require __DIR__ . '/../includes/content.php';
require __DIR__ . '/../includes/notation.php';
$user = require_role('admin');

$partId = (int) ($_GET['part_id'] ?? 0);
$part = fetch_row_or_404(
    'SELECT p.id, p.song_id, p.instrument_id, p.style, so.title, so.bpm, so.beats_per_bar,
            so.default_transpose, i.name AS instrument_name, i.type AS instrument_type, i.color
     FROM song_parts p
     JOIN songs so ON so.id = p.song_id
     JOIN instruments i ON i.id = p.instrument_id
     WHERE p.id = ?',
    [$partId],
    'Part tidak ditemukan'
);
$thisUrl = 'admin/part_import.php?part_id=' . $partId;
$isChords = $part['style'] === 'chords';
$isDrum = $part['instrument_type'] === 'drum';
$bpb = (int) $part['beats_per_bar'];
$transpose = (int) $part['default_transpose'];

$stmt = db()->prepare('SELECT id, name, code FROM sounds WHERE instrument_id = ?');
$stmt->execute([(int) $part['instrument_id']]);
$sounds = $stmt->fetchAll();
$soundNames = array_column($sounds, 'name', 'id');

$stmt = db()->prepare('SELECT finger_count, sound_id FROM gestures WHERE instrument_id = ? AND is_active = 1 ORDER BY finger_count');
$stmt->execute([(int) $part['instrument_id']]);
$gestures = $stmt->fetchAll();
$doOctave = instrument_do_octave($sounds, $gestures);

$stmt = db()->prepare('SELECT COUNT(*) FROM song_notes WHERE song_part_id = ?');
$stmt->execute([$partId]);
$existingCount = (int) $stmt->fetchColumn();

// Panjang part lain di lagu ini, untuk mengingatkan bila panjangnya berbeda.
$stmt = db()->prepare(
    'SELECT COALESCE(MAX(n.start_beat + n.duration_beats), 0)
     FROM song_notes n JOIN song_parts p ON p.id = n.song_part_id
     WHERE p.song_id = ? AND p.id <> ?'
);
$stmt->execute([(int) $part['song_id'], $partId]);
$otherLength = (float) $stmt->fetchColumn();

// Urai teks dan cocokkan ke bunyi. Hasil: rows, errors, warnings, total_beats.
function import_parse(string $text, array $ctx): array
{
    if ($ctx['isChords']) {
        $parsed = parse_chord_notation($text, $ctx['bpb'], $ctx['transpose']);
    } else {
        $parsed = parse_melody_notation($text, $ctx['bpb']);
    }
    if ($parsed['errors'] !== []) {
        return $parsed;
    }

    if ($ctx['isDrum']) {
        // Drum tidak bernada: angka = jumlah jari, bunyinya dari pemetaan gestur.
        $soundByFinger = array_column($ctx['gestures'], 'sound_id', 'finger_count');
        $rows = [];
        foreach ($parsed['rows'] as $row) {
            $where = ucfirst(notation_position($row['bar'], $row['beat']));
            if ($row['octave_shift'] !== 0) {
                $parsed['errors'][] = $where . ': drum tidak punya oktaf, hapus tanda \' atau , pada ' . $row['label'] . '.';
            } elseif (!isset($soundByFinger[$row['degree']])) {
                $parsed['errors'][] = $where . ': drum tidak punya gestur ' . $row['degree'] . ' jari.';
            } else {
                $row['sound_id'] = (int) $soundByFinger[$row['degree']];
                $row['code'] = $ctx['soundNames'][$row['sound_id']] ?? '';
                $rows[] = $row;
            }
        }
        $parsed['rows'] = $rows;
        return $parsed;
    }

    if ($ctx['doOctave'] === null) {
        $parsed['errors'][] = 'Instrumen ini tidak punya bunyi Do (kode C...) yang dipetakan ke 1 jari, jadi not angka tidak bisa dicocokkan.';
        return $parsed;
    }
    $resolved = resolve_notation_sounds($parsed['rows'], $ctx['sounds'], $ctx['doOctave'], $ctx['instrumentName']);
    $parsed['rows'] = $resolved['rows'];
    $parsed['errors'] = $resolved['errors'];
    // Nada di luar gestur kamera (misalnya oktaf rendah/tinggi) boleh, tetapi diberi tahu.
    $parsed['warnings'] = array_merge(
        $parsed['warnings'],
        notation_keyboard_only_warnings($parsed['rows'], array_column($ctx['gestures'], 'sound_id'))
    );
    return $parsed;
}

$ctx = [
    'isChords' => $isChords, 'isDrum' => $isDrum, 'bpb' => $bpb, 'transpose' => $transpose,
    'gestures' => $gestures, 'soundNames' => $soundNames, 'sounds' => $sounds,
    'doOctave' => $doOctave, 'instrumentName' => $part['instrument_name'],
];

$text = '';
$result = null;

if (is_post()) {
    csrf_verify();
    $text = is_string($_POST['notation'] ?? null) ? str_replace("\r\n", "\n", $_POST['notation']) : '';
    $result = import_parse($text, $ctx);

    if (post_str('action') === 'save' && $result['errors'] === []) {
        $pdo = db();
        $pdo->beginTransaction();
        try {
            $pdo->prepare('DELETE FROM song_notes WHERE song_part_id = ?')->execute([$partId]);
            $insert = $pdo->prepare(
                'INSERT INTO song_notes (song_part_id, sound_id, start_beat, duration_beats) VALUES (?, ?, ?, ?)'
            );
            foreach ($result['rows'] as $row) {
                $insert->execute([$partId, $row['sound_id'], round($row['start_beat'], 3), round($row['duration_beats'], 3)]);
            }
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
        flash('success', count($result['rows']) . ' ' . ($isChords ? 'akor' : 'nada') . ' tersimpan di part '
            . $part['instrument_name'] . '. Partitur lama (' . $existingCount . ') sudah diganti.');
        redirect('admin/song.php?id=' . (int) $part['song_id']);
    }
}

$beatSeconds = 60 / (int) $part['bpm'];
$styleName = strtolower(PART_STYLES[$part['style']] ?? $part['style']);

$pageTitle = 'Impor not angka';
$activeNav = 'songs';
require __DIR__ . '/../includes/admin_header.php';
?>
<p class="crumb">
    <a href="<?= e(url('admin/songs.php')) ?>">Lagu</a> /
    <a href="<?= e(url('admin/song.php?id=' . (int) $part['song_id'])) ?>"><?= e($part['title']) ?></a> /
    Impor not angka
</p>
<h1 class="wavy"><?= color_swatch($part['color']) ?> Impor: <?= e($part['instrument_name']) ?> (<?= e($styleName) ?>)</h1>
<p class="muted">
    <?= e($part['title']) ?>, <?= $bpb ?> ketukan per birama, Do = <?= e(key_name($transpose)) ?>, <?= e($part['bpm']) ?> bpm.
    Part ini sekarang berisi <?= $existingCount ?> <?= $isChords ? 'akor' : 'nada' ?>.
</p>

<div class="import-grid">
    <form method="post" action="<?= e(url($thisUrl)) ?>" class="form paper import-form">
        <?= csrf_field() ?>
        <label for="notation"><?= $isChords ? 'Teks akor' : 'Teks not angka' ?></label>
        <textarea id="notation" name="notation" rows="12" spellcheck="false" maxlength="<?= NOTATION_MAX_CHARS ?>"
                  placeholder="<?= e($isChords ? "# Contoh\nC | F C | G C |" : ($isDrum ? "# Contoh: 1 Kick, 2 Rim\n5 2 1 2 | 1 2 1 2 |" : "# Contoh\n1 1 5 5 | 6 6 5 . |")) ?>"><?= e($text) ?></textarea>
        <div class="form-actions">
            <button type="submit" name="action" value="preview" class="btn">Pratinjau</button>
            <?php if ($result !== null && $result['errors'] === []): ?>
                <button type="submit" name="action" value="save" class="btn btn-primary"
                        onclick="return confirm('Ganti partitur lama dengan hasil pratinjau ini?');">
                    Simpan <?= count($result['rows']) ?> <?= $isChords ? 'akor' : 'nada' ?>
                </button>
            <?php endif; ?>
        </div>
    </form>

    <aside class="paper import-help">
        <h2>Cara menulis</h2>
        <?php if ($isChords): ?>
            <ul>
                <li>Tulis nama akor per birama, dipisah <code>|</code>.</li>
                <li><code>C</code> = satu birama penuh. <code>F C</code> = masing-masing <?= $bpb / 2 ?> ketuk.</li>
                <li><code>-</code> = lanjutkan akor sebelumnya, misalnya <code>C - - G</code>.</li>
                <li>Tulis akor seperti di buku lagu (Do = <?= e(key_name($transpose)) ?>). Akor dimainkan versi diatonisnya.</li>
                <li>Teks setelah <code># </code> adalah komentar.</li>
            </ul>
        <?php else: ?>
            <ul>
                <?php if ($isDrum): ?>
                    <li>Angka = jumlah jari:
                        <?php foreach ($gestures as $g): ?><code><?= (int) $g['finger_count'] ?></code> <?= e($soundNames[$g['sound_id']] ?? '') ?><?= $g === end($gestures) ? '' : ', ' ?><?php endforeach; ?>.
                    </li>
                <?php else: ?>
                    <li><code>1</code> sampai <code>7</code> = Do sampai Si. <code>0</code> = diam.</li>
                    <li><code>1'</code> = oktaf tinggi, <code>5,</code> = oktaf rendah.</li>
                <?php endif; ?>
                <li>Spasi memisahkan ketukan. <code>.</code> = perpanjang satu ketuk.</li>
                <li><code>[5 6]</code> = dua nada dalam satu ketuk. <code>[. 2]</code> = sambung setengah ketuk, lalu 2 setengah ketuk.</li>
                <li><code>|</code> = garis birama (<?= $bpb ?> ketukan). Birama pertama boleh kurang (birama gantung).</li>
                <li>Teks setelah <code># </code> adalah komentar.</li>
            </ul>
        <?php endif; ?>
    </aside>
</div>

<?php if ($result !== null): ?>
    <?php if ($result['errors'] !== []): ?>
        <div class="alert alert-error" role="alert">
            <p>Ada <?= count($result['errors']) ?> hal yang perlu dibetulkan sebelum bisa disimpan:</p>
            <ul class="import-messages">
                <?php foreach ($result['errors'] as $message): ?>
                    <li><?= e($message) ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>

    <?php if ($result['warnings'] !== []): ?>
        <div class="alert alert-warning" role="status">
            <p>Peringatan (tetap bisa disimpan):</p>
            <ul class="import-messages">
                <?php foreach ($result['warnings'] as $message): ?>
                    <li><?= e($message) ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>

    <?php if ($result['errors'] === []): ?>
        <section class="section-block">
            <h2>Pratinjau</h2>
            <p class="muted">
                <?= count($result['rows']) ?> <?= $isChords ? 'akor' : 'nada' ?>,
                total <?= e(notation_beats_text($result['total_beats'])) ?> ketukan
                (sekitar <?= e(number_format($result['total_beats'] * $beatSeconds, 1, ',', '')) ?> detik pada <?= e($part['bpm']) ?> bpm).
                <?php if ($otherLength > 0 && abs($otherLength - $result['total_beats']) > 1e-9): ?>
                    <strong>Perhatian: part lain di lagu ini panjangnya <?= e(notation_beats_text($otherLength)) ?> ketukan.</strong>
                <?php endif; ?>
            </p>
            <div class="table-wrap">
                <table class="table table-cards table-dense">
                    <thead>
                    <tr>
                        <th>Birama</th>
                        <th>Ketukan</th>
                        <th><?= $isChords ? 'Akor' : 'Not' ?></th>
                        <th>Bunyi</th>
                        <th>Durasi</th>
                        <th>Mulai</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($result['rows'] as $row): ?>
                        <tr>
                            <td data-label="Birama"><?= (int) $row['bar'] ?></td>
                            <td data-label="Ketukan"><?= e(notation_beats_text($row['beat'] + 1)) ?></td>
                            <td data-label="<?= $isChords ? 'Akor' : 'Not' ?>">
                                <strong><?= e($row['label']) ?></strong>
                                <?php if ($isChords && $row['written'] !== $row['label']): ?>
                                    <span class="muted">(ditulis <?= e($row['written']) ?>)</span>
                                <?php endif; ?>
                            </td>
                            <td data-label="Bunyi"><code><?= e($row['code']) ?></code></td>
                            <td data-label="Durasi"><?= e(notation_beats_text($row['duration_beats'])) ?> ketuk</td>
                            <td data-label="Mulai"><?= e(number_format($row['start_beat'] * $beatSeconds, 2, ',', '')) ?> dtk</td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </section>
    <?php endif; ?>
<?php endif; ?>
<?php require __DIR__ . '/../includes/admin_footer.php'; ?>
