<?php
// Detail lagu: status tayang dan daftar part.
// Satu instrumen boleh punya dua part: melodi ('notes') dan akor ('chords').
// Partitur tiap part diedit di admin/part_notes.php (satu per satu) atau
// admin/part_import.php (tempel teks not angka).

require __DIR__ . '/../includes/bootstrap.php';
require __DIR__ . '/../includes/content.php';
require_once __DIR__ . '/../includes/music.php';
$user = require_role('admin');

$id = (int) ($_GET['id'] ?? 0);
$song = fetch_row_or_404('SELECT * FROM songs WHERE id = ?', [$id], 'Lagu tidak ditemukan');

// Part yang sudah ada, dengan jumlah nada, panjangnya (ketukan terakhir), dan
// jumlah nada tanpa gestur kamera (part itu hanya bisa dimainkan penuh lewat keyboard).
$stmt = db()->prepare(
    'SELECT p.id, p.instrument_id, p.style, i.name AS instrument_name, i.color, i.is_active,
        COUNT(n.id) AS note_count,
        COALESCE(MAX(n.start_beat + n.duration_beats), 0) AS length_beats,
        (SELECT COUNT(*) FROM song_notes k
         WHERE k.song_part_id = p.id
           AND NOT EXISTS (SELECT 1 FROM gestures g
                           WHERE g.instrument_id = p.instrument_id AND g.sound_id = k.sound_id AND g.is_active = 1)
        ) AS keyboard_only_notes
     FROM song_parts p
     JOIN instruments i ON i.id = p.instrument_id
     LEFT JOIN song_notes n ON n.song_part_id = p.id
     WHERE p.song_id = ?
     GROUP BY p.id, p.instrument_id, p.style, i.name, i.color, i.is_active
     ORDER BY i.id, p.style DESC'
);
$stmt->execute([$id]);
$parts = $stmt->fetchAll();

// Pilihan "tambah part": setiap pasangan instrumen + gaya yang belum ada.
// Drum hanya punya gaya melodi (pukulan), karena drum tidak bisa memainkan akor.
$existing = [];
foreach ($parts as $part) {
    $existing[$part['instrument_id'] . ':' . $part['style']] = true;
}
$freeParts = [];
foreach (db()->query('SELECT id, name, type FROM instruments ORDER BY id')->fetchAll() as $inst) {
    foreach (PART_STYLES as $style => $styleLabel) {
        if (($style === 'chords' && $inst['type'] === 'drum') || isset($existing[$inst['id'] . ':' . $style])) {
            continue;
        }
        $freeParts[] = ['value' => $inst['id'] . ':' . $style, 'label' => $inst['name'] . ' (' . strtolower($styleLabel) . ')'];
    }
}

// Panjang lagu = part terpanjang. Detik = ketukan x (60 / bpm).
$songBeats = $parts === [] ? 0 : max(array_map(fn($p) => (float) $p['length_beats'], $parts));
$songSeconds = $songBeats * 60 / (int) $song['bpm'];

$pageTitle = $song['title'];
$activeNav = 'songs';
require __DIR__ . '/../includes/admin_header.php';
?>
<p class="crumb"><a href="<?= e(url('admin/songs.php')) ?>">Lagu</a> / <?= e($song['title']) ?></p>
<div class="page-head">
    <h1 class="wavy"><?= e($song['title']) ?></h1>
    <a class="btn" href="<?= e(url('admin/song_form.php?id=' . $id)) ?>">Ubah data lagu</a>
</div>

<div class="song-facts">
    <span class="label-tag"><?= e($song['bpm']) ?> bpm</span>
    <span><?= e($song['beats_per_bar']) ?> ketukan per birama</span>
    <span>Do = <?= e(key_name((int) $song['default_transpose'])) ?></span>
    <span><?= e(DIFFICULTIES[$song['difficulty']] ?? $song['difficulty']) ?></span>
    <span>Panjang <?= e(format_beat($songBeats)) ?> ketukan (sekitar <?= e(number_format($songSeconds, 1, ',', '')) ?> detik)</span>
</div>

<form method="post" action="<?= e(url('admin/song_publish.php')) ?>" class="publish-box">
    <?= csrf_field() ?>
    <input type="hidden" name="id" value="<?= $id ?>">
    <input type="hidden" name="back" value="song">
    <?php if ((int) $song['is_published'] === 1): ?>
        <span class="badge badge-on">Tayang</span> Lagu ini tampil di halaman publik dan bisa dipilih pemain.
        <button type="submit" name="publish" value="0" class="btn btn-small">Sembunyikan</button>
    <?php else: ?>
        <span class="badge badge-off">Draf</span> Lagu ini belum terlihat oleh pemain.
        <button type="submit" name="publish" value="1" class="btn btn-small btn-primary">Tayangkan</button>
    <?php endif; ?>
</form>

<section class="section-block">
    <h2>Part per instrumen</h2>

    <?php if ($parts === []): ?>
        <p class="empty">Belum ada part. Pilih instrumen di bawah untuk mulai menulis partitur.</p>
    <?php else: ?>
        <div class="table-wrap">
            <table class="table table-cards">
                <thead>
                <tr>
                    <th>Instrumen</th>
                    <th>Gaya</th>
                    <th>Nada</th>
                    <th>Panjang</th>
                    <th><span class="sr-only">Aksi</span></th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($parts as $part): ?>
                    <tr>
                        <td data-label="Instrumen">
                            <?= color_swatch($part['color']) ?> <strong><?= e($part['instrument_name']) ?></strong>
                            <?php if ((int) $part['is_active'] !== 1): ?><span class="badge badge-off">Instrumen nonaktif</span><?php endif; ?>
                        </td>
                        <td data-label="Gaya"><?= e(PART_STYLES[$part['style']] ?? $part['style']) ?></td>
                        <td data-label="Nada">
                            <?= e($part['note_count']) ?>
                            <?php if ((int) $part['keyboard_only_notes'] > 0): ?>
                                <span class="badge badge-keys" title="Nada tanpa gestur kamera"><?= (int) $part['keyboard_only_notes'] ?> hanya keyboard</span>
                            <?php endif; ?>
                        </td>
                        <td data-label="Panjang"><?= e(format_beat($part['length_beats'])) ?> ketukan</td>
                        <td class="row-actions">
                            <a class="btn btn-small btn-primary" href="<?= e(url('admin/part_import.php?part_id=' . (int) $part['id'])) ?>">Impor not angka</a>
                            <a class="btn btn-small" href="<?= e(url('admin/part_notes.php?part_id=' . (int) $part['id'])) ?>">Edit per nada</a>
                            <form method="post" action="<?= e(url('admin/part_delete.php')) ?>"
                                  onsubmit="return confirm('Hapus part ini beserta semua nadanya?');">
                                <?= csrf_field() ?>
                                <input type="hidden" name="id" value="<?= (int) $part['id'] ?>">
                                <button type="submit" class="btn btn-small btn-danger">Hapus part</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>

    <?php if ($freeParts !== []): ?>
        <form method="post" action="<?= e(url('admin/part_add.php')) ?>" class="form form-inline">
            <?= csrf_field() ?>
            <input type="hidden" name="song_id" value="<?= $id ?>">
            <label for="part">Tambah part</label>
            <select id="part" name="part">
                <?php foreach ($freeParts as $option): ?>
                    <option value="<?= e($option['value']) ?>"><?= e($option['label']) ?></option>
                <?php endforeach; ?>
            </select>
            <button type="submit" class="btn btn-primary">Tambah part</button>
        </form>
    <?php else: ?>
        <p class="muted">Semua instrumen sudah punya part melodi dan akor di lagu ini.</p>
    <?php endif; ?>
</section>
<?php require __DIR__ . '/../includes/admin_footer.php'; ?>
