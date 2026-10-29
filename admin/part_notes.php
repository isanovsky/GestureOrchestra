<?php
// Editor partitur sederhana untuk satu part (satu instrumen dalam satu lagu).
//
// Setiap nada = satu baris: bunyi apa, mulai di ketukan ke berapa
// (start_beat, dihitung dari 0), dan berapa ketukan panjangnya.
// GET  ?part_id=..             tampilkan tabel + form tambah nada
// GET  ?part_id=..&edit=..     form diisi nada yang sedang diubah
// POST action=save             simpan nada baru / ubahan (note_id kosong = baru)
// POST action=delete           hapus satu nada

require __DIR__ . '/../includes/bootstrap.php';
require __DIR__ . '/../includes/content.php';
require_once __DIR__ . '/../includes/music.php';
$user = require_role('admin');

// Batas mengikuti tipe kolom: start_beat DECIMAL(8,3), duration_beats DECIMAL(6,3).
const MAX_START_BEAT = 9999;
const MAX_DURATION_BEATS = 64;

$partId = (int) ($_GET['part_id'] ?? 0);
$part = fetch_row_or_404(
    'SELECT p.id, p.song_id, p.instrument_id, p.style, so.title, so.bpm, so.beats_per_bar,
            so.default_transpose, i.name AS instrument_name, i.color
     FROM song_parts p
     JOIN songs so ON so.id = p.song_id
     JOIN instruments i ON i.id = p.instrument_id
     WHERE p.id = ?',
    [$partId],
    'Part tidak ditemukan'
);
$thisUrl = 'admin/part_notes.php?part_id=' . $partId;

// Bunyi milik instrumen part ini, sebagai pilihan.
$stmt = db()->prepare('SELECT id, name, code FROM sounds WHERE instrument_id = ? ORDER BY sort_order, id');
$stmt->execute([(int) $part['instrument_id']]);
$sounds = $stmt->fetchAll();
$soundIds = array_map('intval', array_column($sounds, 'id'));

// Label setiap bunyi untuk part ini. Part akor: nama akor diatonis dari nada
// dasarnya (misalnya "Akor Dm"); part melodi: nama bunyi + not angka ("Mi, 3").
// Derajat dihitung dari kode bunyi relatif terhadap Do instrumen, bukan sort_order.
$stmt = db()->prepare('SELECT finger_count, sound_id FROM gestures WHERE instrument_id = ?');
$stmt->execute([(int) $part['instrument_id']]);
$doOctave = instrument_do_octave($sounds, $stmt->fetchAll());
$isChords = $part['style'] === 'chords';
$soundLabels = [];
foreach ($sounds as $sound) {
    $degree = $doOctave === null ? null : code_to_degree($sound['code'], $doOctave);
    if ($degree === null) {
        $soundLabels[(int) $sound['id']] = $sound['name'];
    } elseif ($isChords) {
        $soundLabels[(int) $sound['id']] = 'Akor ' . chord_name($degree['degree'], (int) $part['default_transpose']);
    } else {
        $soundLabels[(int) $sound['id']] = $sound['name'] . ', ' . degree_label($degree['degree'], $degree['octave_shift']);
    }
}

// Ambil semua nada part ini, urut dari ketukan paling awal.
function load_notes(int $partId): array
{
    $stmt = db()->prepare(
        'SELECT n.id, n.sound_id, n.start_beat, n.duration_beats, s.name AS sound_name, s.code AS sound_code
         FROM song_notes n JOIN sounds s ON s.id = n.sound_id
         WHERE n.song_part_id = ?
         ORDER BY n.start_beat, n.id'
    );
    $stmt->execute([$partId]);
    return $stmt->fetchAll();
}

$errors = [];
$form = null;   // isi form setelah POST gagal validasi

if (is_post()) {
    csrf_verify();
    $action = post_str('action');
    $noteId = (int) post_str('note_id');

    if ($action === 'delete') {
        $stmt = db()->prepare('DELETE FROM song_notes WHERE id = ? AND song_part_id = ?');
        $stmt->execute([$noteId, $partId]);
        flash('success', $stmt->rowCount() === 1 ? 'Nada dihapus.' : 'Nada tidak ditemukan.');
        if (unpublish_if_empty((int) $part['song_id'])) {
            flash('error', 'Lagu ini tidak punya nada lagi, jadi otomatis disembunyikan dari halaman publik.');
        }
        redirect($thisUrl);
    }

    if ($action === 'save') {
        $form = [
            'note_id'        => $noteId,
            'sound_id'       => (int) post_str('sound_id'),
            'start_beat'     => post_str('start_beat'),
            'duration_beats' => post_str('duration_beats'),
        ];

        if (!in_array($form['sound_id'], $soundIds, true)) {
            $errors['sound_id'] = 'Pilih bunyi milik instrumen ' . $part['instrument_name'] . '.';
        }

        $start = parse_decimal($form['start_beat'], 3);
        if ($start === null || (float) $start > MAX_START_BEAT) {
            $errors['start_beat'] = 'Ketukan mulai berupa angka 0 sampai ' . MAX_START_BEAT . ', misalnya 0, 4, atau 2.5.';
        }

        $duration = parse_decimal($form['duration_beats'], 3);
        if ($duration === null || (float) $duration <= 0 || (float) $duration > MAX_DURATION_BEATS) {
            $errors['duration_beats'] = 'Durasi lebih dari 0 dan maksimal ' . MAX_DURATION_BEATS . ' ketukan.';
        }

        // Nada di satu part tidak boleh tumpang tindih, karena pemain hanya
        // bisa membentuk satu gestur dalam satu waktu.
        // Dua nada bertumpuk bila: mulai_A < selesai_B dan mulai_B < selesai_A.
        if ($errors === []) {
            $end = (float) $start + (float) $duration;
            $stmt = db()->prepare(
                'SELECT start_beat FROM song_notes
                 WHERE song_part_id = ? AND id <> ?
                   AND start_beat < ? AND start_beat + duration_beats > ?
                 ORDER BY start_beat LIMIT 1'
            );
            $stmt->execute([$partId, $noteId, $end, (float) $start]);
            $clash = $stmt->fetchColumn();
            if ($clash !== false) {
                $errors['start_beat'] = 'Nada ini bertumpuk dengan nada di ketukan ' . format_beat($clash)
                    . '. Geser ketukan mulai atau perpendek durasinya.';
            }
        }

        if ($errors === []) {
            if ($noteId > 0) {
                $stmt = db()->prepare(
                    'UPDATE song_notes SET sound_id = ?, start_beat = ?, duration_beats = ?
                     WHERE id = ? AND song_part_id = ?'
                );
                $stmt->execute([$form['sound_id'], $start, $duration, $noteId, $partId]);
                flash('success', 'Nada di ketukan ' . format_beat($start) . ' diubah.');
            } else {
                $stmt = db()->prepare(
                    'INSERT INTO song_notes (song_part_id, sound_id, start_beat, duration_beats) VALUES (?, ?, ?, ?)'
                );
                $stmt->execute([$partId, $form['sound_id'], $start, $duration]);
                flash('success', 'Nada ditambahkan di ketukan ' . format_beat($start) . '.');
            }
            redirect($thisUrl);
        }
    }
}

$notes = load_notes($partId);
$lastEnd = 0.0;
foreach ($notes as $n) {
    $lastEnd = max($lastEnd, (float) $n['start_beat'] + (float) $n['duration_beats']);
}

// Isi form: hasil POST yang gagal, nada yang sedang diubah, atau nada baru
// yang otomatis mulai tepat setelah nada terakhir.
$editId = (int) ($_GET['edit'] ?? 0);
if ($form === null && $editId > 0) {
    foreach ($notes as $n) {
        if ((int) $n['id'] === $editId) {
            $form = [
                'note_id'        => $editId,
                'sound_id'       => (int) $n['sound_id'],
                'start_beat'     => format_beat($n['start_beat']),
                'duration_beats' => format_beat($n['duration_beats']),
            ];
        }
    }
}
if ($form === null) {
    $form = [
        'note_id'        => 0,
        'sound_id'       => $notes === [] ? ($soundIds[0] ?? 0) : (int) end($notes)['sound_id'],
        'start_beat'     => format_beat($lastEnd),
        'duration_beats' => '1',
    ];
}
$isEditing = $form['note_id'] > 0;

$beatMs = 60000 / (int) $part['bpm'];   // lama satu ketukan dalam milidetik
$bpb = (int) $part['beats_per_bar'];

$styleName = strtolower(PART_STYLES[$part['style']] ?? $part['style']);
$pageTitle = 'Partitur ' . $part['instrument_name'] . ' (' . $styleName . ')';
$activeNav = 'songs';
require __DIR__ . '/../includes/admin_header.php';
?>
<p class="crumb">
    <a href="<?= e(url('admin/songs.php')) ?>">Lagu</a> /
    <a href="<?= e(url('admin/song.php?id=' . (int) $part['song_id'])) ?>"><?= e($part['title']) ?></a> /
    Partitur <?= e($part['instrument_name']) ?> (<?= e($styleName) ?>)
</p>
<div class="page-head">
    <h1 class="wavy"><?= color_swatch($part['color']) ?> Partitur <?= e($part['instrument_name']) ?> (<?= e($styleName) ?>)</h1>
    <a class="btn btn-primary" href="<?= e(url('admin/part_import.php?part_id=' . $partId)) ?>">Impor dari not angka</a>
</div>
<?php if ($isChords): ?>
    <p class="note">Part akor: setiap baris adalah satu akor. Bunyi yang dipilih = nada dasar akor
        (Do = akor I, Re = akor ii, dan seterusnya), dinamai sesuai nada dasar lagu Do = <?= e(key_name((int) $part['default_transpose'])) ?>.</p>
<?php endif; ?>
<p class="muted">
    <?= e($part['title']) ?>, <?= e($part['bpm']) ?> bpm: satu ketukan = <?= e(number_format($beatMs, 0, ',', '.')) ?> ms.
    Ketukan dihitung dari 0. Ketukan pertama birama ke-2 adalah ketukan <?= $bpb ?>.
</p>

<?php if ($sounds === []): ?>
    <p class="alert alert-error">Instrumen ini belum punya bunyi. Tambahkan bunyi dulu di menu Instrumen.</p>
<?php else: ?>

<?php if ($notes !== [] && $lastEnd > 0): ?>
    <!-- Pratinjau lajur: posisi dan lebar setiap balok sebanding dengan ketukan. -->
    <div class="lane" style="--lane-color: <?= e($part['color']) ?>" aria-hidden="true">
        <?php for ($b = 0; $b < $lastEnd; $b += $bpb): ?>
            <span class="lane-bar" style="left: <?= e($b / $lastEnd * 100) ?>%"></span>
        <?php endfor; ?>
        <?php foreach ($notes as $n): ?>
            <span class="lane-note<?= (int) $n['id'] === $form['note_id'] ? ' is-editing' : '' ?>"
                  style="left: <?= e((float) $n['start_beat'] / $lastEnd * 100) ?>%; width: <?= e((float) $n['duration_beats'] / $lastEnd * 100) ?>%"
                  title="<?= e($soundLabels[(int) $n['sound_id']] ?? $n['sound_name']) ?>"><?= e($isChords ? substr($soundLabels[(int) $n['sound_id']] ?? '', 5) : $n['sound_code']) ?></span>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<form method="post" action="<?= e(url($thisUrl)) ?>" class="form note-form paper" novalidate>
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="save">
    <input type="hidden" name="note_id" value="<?= (int) $form['note_id'] ?>">
    <h2><?= $isEditing ? 'Ubah nada' : 'Tambah nada' ?></h2>

    <div class="field-row">
        <div>
            <label for="sound_id">Bunyi</label>
            <select id="sound_id" name="sound_id">
                <?php foreach ($sounds as $s): ?>
                    <option value="<?= (int) $s['id'] ?>"<?= (int) $s['id'] === $form['sound_id'] ? ' selected' : '' ?>><?= e($soundLabels[(int) $s['id']]) ?> (<?= e($s['code']) ?>)</option>
                <?php endforeach; ?>
            </select>
            <?php if (isset($errors['sound_id'])): ?><p class="field-error"><?= e($errors['sound_id']) ?></p><?php endif; ?>
        </div>
        <div>
            <label for="start_beat">Mulai di ketukan</label>
            <input id="start_beat" name="start_beat" type="text" inputmode="decimal" maxlength="10" required value="<?= e($form['start_beat']) ?>">
            <?php if (isset($errors['start_beat'])): ?><p class="field-error"><?= e($errors['start_beat']) ?></p><?php endif; ?>
        </div>
        <div>
            <label for="duration_beats">Durasi (ketukan)</label>
            <input id="duration_beats" name="duration_beats" type="text" inputmode="decimal" maxlength="7" required value="<?= e($form['duration_beats']) ?>">
            <?php if (isset($errors['duration_beats'])): ?><p class="field-error"><?= e($errors['duration_beats']) ?></p><?php endif; ?>
        </div>
    </div>
    <p class="hint">Boleh pecahan: 0.5 = setengah ketukan. Nada tidak boleh bertumpuk.</p>

    <div class="form-actions">
        <button type="submit" class="btn btn-primary"><?= $isEditing ? 'Simpan ubahan' : 'Tambah nada' ?></button>
        <?php if ($isEditing): ?>
            <a class="btn btn-ghost" href="<?= e(url($thisUrl)) ?>">Batal ubah</a>
        <?php endif; ?>
    </div>
</form>

<section class="section-block">
    <h2><?= count($notes) ?> nada</h2>
    <?php if ($notes === []): ?>
        <p class="empty">Partitur masih kosong. Tambahkan nada pertama di ketukan 0.</p>
    <?php else: ?>
        <div class="table-wrap">
            <table class="table table-cards table-dense">
                <thead>
                <tr>
                    <th>Ketukan</th>
                    <th>Birama</th>
                    <th>Durasi</th>
                    <th>Bunyi</th>
                    <th>Waktu</th>
                    <th><span class="sr-only">Aksi</span></th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($notes as $n): ?>
                    <?php
                    $startBeat = (float) $n['start_beat'];
                    // Birama ke-berapa dan ketukan ke-berapa di dalam birama (keduanya mulai dari 1).
                    $barNumber = (int) floor($startBeat / $bpb) + 1;
                    $beatInBar = fmod($startBeat, $bpb) + 1;
                    ?>
                    <tr<?= (int) $n['id'] === $form['note_id'] ? ' class="is-editing"' : '' ?>>
                        <td data-label="Ketukan"><strong><?= e(format_beat($n['start_beat'])) ?></strong></td>
                        <td data-label="Birama"><?= $barNumber ?>, ketukan <?= e(format_beat($beatInBar)) ?></td>
                        <td data-label="Durasi"><?= e(format_beat($n['duration_beats'])) ?></td>
                        <td data-label="Bunyi"><?= e($soundLabels[(int) $n['sound_id']] ?? $n['sound_name']) ?> <code><?= e($n['sound_code']) ?></code></td>
                        <td data-label="Waktu"><?= e(number_format($startBeat * $beatMs / 1000, 2, ',', '')) ?> dtk</td>
                        <td class="row-actions">
                            <a class="btn btn-small" href="<?= e(url($thisUrl . '&edit=' . (int) $n['id'])) ?>">Ubah</a>
                            <form method="post" action="<?= e(url($thisUrl)) ?>">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="note_id" value="<?= (int) $n['id'] ?>">
                                <button type="submit" class="btn btn-small btn-danger">Hapus</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>
<?php endif; ?>
<?php require __DIR__ . '/../includes/admin_footer.php'; ?>
