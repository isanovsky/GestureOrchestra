<?php
// Daftar lagu, dengan tombol tayang/sembunyikan.

require __DIR__ . '/../includes/bootstrap.php';
require __DIR__ . '/../includes/content.php';
require_once __DIR__ . '/../includes/music.php';
$user = require_role('admin');

$songs = db()->query(
    'SELECT so.*,
        (SELECT COUNT(*) FROM song_parts p WHERE p.song_id = so.id) AS part_count,
        (SELECT COUNT(*) FROM performances pf WHERE pf.song_id = so.id) AS play_count
     FROM songs so
     ORDER BY so.title'
)->fetchAll();

$pageTitle = 'Lagu';
$activeNav = 'songs';
require __DIR__ . '/../includes/admin_header.php';
?>
<div class="page-head">
    <h1 class="wavy">Lagu</h1>
    <a class="btn btn-primary" href="<?= e(url('admin/song_form.php')) ?>">Tambah lagu</a>
</div>

<?php if ($songs === []): ?>
    <div class="empty-state paper">
        <img src="<?= e(url('assets/img/mascot-oops.svg')) ?>" alt="" width="90" height="105">
        <p>Belum ada lagu. Tambahkan satu, lalu tulis partiturnya.</p>
    </div>
<?php else: ?>
    <div class="table-wrap">
        <table class="table table-cards">
            <thead>
            <tr>
                <th>Judul</th>
                <th>Tempo</th>
                <th>Tingkat</th>
                <th>Part</th>
                <th>Dimainkan</th>
                <th>Status</th>
                <th><span class="sr-only">Aksi</span></th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($songs as $row): ?>
                <tr>
                    <td data-label="Judul">
                        <strong><?= e($row['title']) ?></strong>
                        <?php if ($row['composer'] !== null): ?><br><span class="muted"><?= e($row['composer']) ?></span><?php endif; ?>
                    </td>
                    <td data-label="Tempo"><?= e($row['bpm']) ?> bpm, <?= e($row['beats_per_bar']) ?>/4, Do = <?= e(key_name((int) $row['default_transpose'])) ?></td>
                    <td data-label="Tingkat"><?= e(DIFFICULTIES[$row['difficulty']] ?? $row['difficulty']) ?></td>
                    <td data-label="Part"><?= e($row['part_count']) ?> part</td>
                    <td data-label="Dimainkan"><?= e($row['play_count']) ?> kali</td>
                    <td data-label="Status">
                        <form method="post" action="<?= e(url('admin/song_publish.php')) ?>" class="inline-form">
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
                        <a class="btn btn-small btn-primary" href="<?= e(url('admin/song.php?id=' . (int) $row['id'])) ?>">Partitur</a>
                        <a class="btn btn-small" href="<?= e(url('admin/song_form.php?id=' . (int) $row['id'])) ?>">Ubah</a>
                        <form method="post" action="<?= e(url('admin/song_delete.php')) ?>"
                              onsubmit="return confirm('Hapus lagu ini? Semua partitur dan nilai permainannya ikut terhapus.');">
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
