<?php
// Daftar style arranger, dengan tombol tayang/sembunyikan.

require __DIR__ . '/../includes/bootstrap.php';
require __DIR__ . '/../includes/arranger.php';
$user = require_role('admin');

$styles = db()->query(
    "SELECT s.*,
        (SELECT GROUP_CONCAT(sec.section ORDER BY FIELD(sec.section, 'intro', 'main_a', 'main_b', 'fill', 'ending'))
         FROM arranger_sections sec WHERE sec.style_id = s.id) AS section_list
     FROM arranger_styles s
     ORDER BY s.sort_order, s.name"
)->fetchAll();

$pageTitle = 'Style arranger';
$activeNav = 'arranger';
require __DIR__ . '/../includes/admin_header.php';
?>
<div class="page-head">
    <h1 class="wavy">Style arranger</h1>
    <a class="btn btn-primary" href="<?= e(url('admin/arranger_style_form.php')) ?>">Tambah style</a>
</div>
<p class="muted">Style = iringan otomatis di halaman Arranger: pola drum, bass, dan akor untuk setiap bagian
    (Intro, Main A, Main B, Fill, Ending). Style tampil di halaman publik bila sudah tayang dan punya Main A.</p>

<?php if ($styles === []): ?>
    <div class="empty-state paper">
        <img src="<?= e(url('assets/img/mascot-oops.svg')) ?>" alt="" width="90" height="105">
        <p>Belum ada style. Tambahkan satu, lalu tulis polanya.</p>
    </div>
<?php else: ?>
    <div class="table-wrap">
        <table class="table table-cards">
            <thead>
            <tr>
                <th>Nama</th>
                <th>Tempo</th>
                <th>Suara akor</th>
                <th>Bagian</th>
                <th>Status</th>
                <th><span class="sr-only">Aksi</span></th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($styles as $row): ?>
                <?php $sections = $row['section_list'] === null ? [] : explode(',', $row['section_list']); ?>
                <tr>
                    <td data-label="Nama">
                        <strong><?= e($row['name']) ?></strong>
                        <?php if ($row['description'] !== null): ?><br><span class="muted"><?= e($row['description']) ?></span><?php endif; ?>
                    </td>
                    <td data-label="Tempo"><?= e($row['default_bpm']) ?> bpm, <?= e($row['beats_per_bar']) ?>/4</td>
                    <td data-label="Suara akor"><?= e(ARRANGER_CHORD_VOICES[$row['chord_voice']] ?? $row['chord_voice']) ?></td>
                    <td data-label="Bagian">
                        <?= $sections === [] ? '<span class="muted">belum ada</span>' : e(implode(', ', array_map(fn ($s) => ARRANGER_SECTIONS[$s] ?? $s, $sections))) ?>
                    </td>
                    <td data-label="Status">
                        <form method="post" action="<?= e(url('admin/arranger_style_publish.php')) ?>" class="inline-form">
                            <?= csrf_field() ?>
                            <input type="hidden" name="id" value="<?= (int) $row['id'] ?>">
                            <?php if ((int) $row['is_published'] === 1): ?>
                                <span class="badge badge-on">Tayang</span>
                                <button type="submit" name="publish" value="0" class="link-button">Sembunyikan</button>
                            <?php else: ?>
                                <span class="badge badge-off">Draf</span>
                                <button type="submit" name="publish" value="1" class="link-button">Tayangkan</button>
                            <?php endif; ?>
                        </form>
                    </td>
                    <td class="row-actions">
                        <a class="btn btn-small btn-primary" href="<?= e(url('admin/arranger_patterns.php?style=' . (int) $row['id'])) ?>">Pola</a>
                        <a class="btn btn-small" href="<?= e(url('admin/arranger_style_form.php?id=' . (int) $row['id'])) ?>">Ubah</a>
                        <form method="post" action="<?= e(url('admin/arranger_style_delete.php')) ?>"
                              onsubmit="return confirm('Hapus style ini beserta semua polanya?');">
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
