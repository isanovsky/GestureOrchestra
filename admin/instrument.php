<?php
// Detail satu instrumen: daftar bunyi dan pemetaan gestur (jumlah jari -> bunyi).

require __DIR__ . '/../includes/bootstrap.php';
require __DIR__ . '/../includes/content.php';
$user = require_role('admin');

$id = (int) ($_GET['id'] ?? 0);
$instrument = fetch_row_or_404('SELECT * FROM instruments WHERE id = ?', [$id], 'Instrumen tidak ditemukan');

// Bunyi beserta berapa kali dipakai di partitur (untuk peringatan sebelum hapus).
$stmt = db()->prepare(
    'SELECT s.*, (SELECT COUNT(*) FROM song_notes n WHERE n.sound_id = s.id) AS note_count
     FROM sounds s
     WHERE s.instrument_id = ?
     ORDER BY s.sort_order, s.id'
);
$stmt->execute([$id]);
$sounds = $stmt->fetchAll();

$stmt = db()->prepare(
    'SELECT g.*, s.name AS sound_name, s.code AS sound_code
     FROM gestures g
     JOIN sounds s ON s.id = g.sound_id
     WHERE g.instrument_id = ?
     ORDER BY g.finger_count'
);
$stmt->execute([$id]);
$gestures = $stmt->fetchAll();

$pageTitle = $instrument['name'];
$activeNav = 'instruments';
require __DIR__ . '/../includes/admin_header.php';
?>
<p class="crumb"><a href="<?= e(url('admin/instruments.php')) ?>">Instrumen</a> / <?= e($instrument['name']) ?></p>
<div class="page-head">
    <h1 class="wavy"><?= color_swatch($instrument['color']) ?> <?= e($instrument['name']) ?></h1>
    <a class="btn" href="<?= e(url('admin/instrument_form.php?id=' . $id)) ?>">Ubah data instrumen</a>
</div>
<p class="muted">
    Tipe <strong><?= e($instrument['type']) ?></strong>, mode main <strong><?= e($instrument['play_mode']) ?></strong>.
    <?= $instrument['type'] === 'drum' ? 'Frekuensi bunyi boleh kosong.' : 'Setiap bunyi wajib punya frekuensi.' ?>
</p>

<!-- ============ Bunyi ============ -->
<section class="section-block">
    <div class="page-head">
        <h2>Bunyi</h2>
        <a class="btn btn-primary btn-small" href="<?= e(url('admin/sound_form.php?instrument_id=' . $id)) ?>">Tambah bunyi</a>
    </div>

    <?php if ($sounds === []): ?>
        <p class="empty">Belum ada bunyi. Tambahkan dulu, baru gestur bisa dipetakan.</p>
    <?php else: ?>
        <div class="table-wrap">
            <table class="table table-cards">
                <thead>
                <tr>
                    <th>Urutan</th>
                    <th>Nama</th>
                    <th>Kode</th>
                    <th>Frekuensi</th>
                    <th>File sampel</th>
                    <th>Dipakai di partitur</th>
                    <th><span class="sr-only">Aksi</span></th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($sounds as $row): ?>
                    <tr>
                        <td data-label="Urutan"><?= e($row['sort_order']) ?></td>
                        <td data-label="Nama"><strong><?= e($row['name']) ?></strong></td>
                        <td data-label="Kode"><code><?= e($row['code']) ?></code></td>
                        <td data-label="Frekuensi"><?= $row['frequency'] === null ? '-' : e($row['frequency']) . ' Hz' ?></td>
                        <td data-label="File sampel"><?= $row['sample_path'] === null ? '-' : '<code>' . e($row['sample_path']) . '</code>' ?></td>
                        <td data-label="Dipakai di partitur"><?= e($row['note_count']) ?> nada</td>
                        <td class="row-actions">
                            <a class="btn btn-small" href="<?= e(url('admin/sound_form.php?id=' . (int) $row['id'])) ?>">Ubah</a>
                            <form method="post" action="<?= e(url('admin/sound_delete.php')) ?>"
                                  onsubmit="return confirm('Hapus bunyi ini? Gestur yang memakainya ikut terhapus.');">
                                <?= csrf_field() ?>
                                <input type="hidden" name="id" value="<?= (int) $row['id'] ?>">
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

<!-- ============ Gestur ============ -->
<section class="section-block">
    <div class="page-head">
        <h2>Pemetaan gestur</h2>
        <?php if ($sounds !== []): ?>
            <a class="btn btn-primary btn-small" href="<?= e(url('admin/gesture_form.php?instrument_id=' . $id)) ?>">Tambah gestur</a>
        <?php endif; ?>
    </div>
    <p class="muted">Jumlah jari = total jari terangkat dari kedua tangan (1 sampai 10). Satu jumlah jari hanya untuk satu bunyi.</p>

    <?php if ($gestures === []): ?>
        <p class="empty">Belum ada gestur. Pemain belum bisa membunyikan instrumen ini dengan kamera.</p>
    <?php else: ?>
        <div class="table-wrap">
            <table class="table table-cards">
                <thead>
                <tr>
                    <th>Jari</th>
                    <th>Bunyi</th>
                    <th>Status</th>
                    <th><span class="sr-only">Aksi</span></th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($gestures as $row): ?>
                    <tr>
                        <td data-label="Jari"><span class="finger-count"><?= e($row['finger_count']) ?></span></td>
                        <td data-label="Bunyi"><?= e($row['sound_name']) ?> <code><?= e($row['sound_code']) ?></code></td>
                        <td data-label="Status">
                            <?php if ((int) $row['is_active'] === 1): ?>
                                <span class="badge badge-on">Aktif</span>
                            <?php else: ?>
                                <span class="badge badge-off">Nonaktif</span>
                            <?php endif; ?>
                        </td>
                        <td class="row-actions">
                            <a class="btn btn-small" href="<?= e(url('admin/gesture_form.php?id=' . (int) $row['id'])) ?>">Ubah</a>
                            <form method="post" action="<?= e(url('admin/gesture_delete.php')) ?>"
                                  onsubmit="return confirm('Hapus pemetaan gestur ini?');">
                                <?= csrf_field() ?>
                                <input type="hidden" name="id" value="<?= (int) $row['id'] ?>">
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
<?php require __DIR__ . '/../includes/admin_footer.php'; ?>
