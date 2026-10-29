<?php
// Daftar instrumen. Dari sini admin masuk ke detail (bunyi dan gestur).

require __DIR__ . '/../includes/bootstrap.php';
require __DIR__ . '/../includes/content.php';
$user = require_role('admin');

// Jumlah bunyi dan gestur dihitung dengan subquery per baris.
$instruments = db()->query(
    "SELECT i.*,
        (SELECT COUNT(*) FROM sounds s WHERE s.instrument_id = i.id)   AS sound_count,
        (SELECT COUNT(*) FROM gestures g WHERE g.instrument_id = i.id) AS gesture_count
     FROM instruments i
     ORDER BY i.id"
)->fetchAll();

$pageTitle = 'Instrumen';
$activeNav = 'instruments';
require __DIR__ . '/../includes/admin_header.php';
?>
<div class="page-head">
    <h1 class="wavy">Instrumen</h1>
    <a class="btn btn-primary" href="<?= e(url('admin/instrument_form.php')) ?>">Tambah instrumen</a>
</div>

<?php if ($instruments === []): ?>
    <div class="empty-state paper">
        <img src="<?= e(url('assets/img/mascot-oops.svg')) ?>" alt="" width="90" height="105">
        <p>Panggung masih kosong. Tambahkan instrumen pertama.</p>
    </div>
<?php else: ?>
    <div class="table-wrap">
        <table class="table table-cards">
            <thead>
            <tr>
                <th>Instrumen</th>
                <th>Slug</th>
                <th>Tipe</th>
                <th>Mode main</th>
                <th>Bunyi / gestur</th>
                <th>Status</th>
                <th><span class="sr-only">Aksi</span></th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($instruments as $row): ?>
                <tr>
                    <td data-label="Instrumen"><?= color_swatch($row['color']) ?> <strong><?= e($row['name']) ?></strong></td>
                    <td data-label="Slug"><code><?= e($row['slug']) ?></code></td>
                    <td data-label="Tipe"><?= e($row['type']) ?></td>
                    <td data-label="Mode main"><?= e($row['play_mode']) ?></td>
                    <td data-label="Bunyi / gestur"><?= e($row['sound_count']) ?> / <?= e($row['gesture_count']) ?></td>
                    <td data-label="Status">
                        <?php if ((int) $row['is_active'] === 1): ?>
                            <span class="badge badge-on">Aktif</span>
                        <?php else: ?>
                            <span class="badge badge-off">Nonaktif</span>
                        <?php endif; ?>
                    </td>
                    <td class="row-actions">
                        <a class="btn btn-small btn-primary" href="<?= e(url('admin/instrument.php?id=' . (int) $row['id'])) ?>">Bunyi dan gestur</a>
                        <a class="btn btn-small" href="<?= e(url('admin/instrument_form.php?id=' . (int) $row['id'])) ?>">Ubah</a>
                        <form method="post" action="<?= e(url('admin/instrument_delete.php')) ?>"
                              onsubmit="return confirm('Hapus instrumen ini beserta semua bunyi dan gesturnya?');">
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
<?php require __DIR__ . '/../includes/admin_footer.php'; ?>
