<?php
// Daftar nilai permainan, bisa disaring per lagu dan instrumen.
// Admin bisa menghapus nilai yang tidak wajar.

require __DIR__ . '/../includes/bootstrap.php';
require __DIR__ . '/../includes/content.php';
$user = require_role('admin');

const PER_PAGE = 50;

$songId = (int) ($_GET['song_id'] ?? 0);
$instrumentId = (int) ($_GET['instrument_id'] ?? 0);
$page = max(1, (int) ($_GET['page'] ?? 1));

// Bangun WHERE dari filter yang dipilih. Nilai tetap lewat parameter (?),
// yang disambung hanya potongan teks SQL yang kita tulis sendiri.
$where = [];
$params = [];
if ($songId > 0) {
    $where[] = 'pf.song_id = ?';
    $params[] = $songId;
}
if ($instrumentId > 0) {
    $where[] = 'pf.instrument_id = ?';
    $params[] = $instrumentId;
}
$whereSql = $where === [] ? '' : 'WHERE ' . implode(' AND ', $where);

$stmt = db()->prepare("SELECT COUNT(*) FROM performances pf $whereSql");
$stmt->execute($params);
$total = (int) $stmt->fetchColumn();
$pageCount = max(1, (int) ceil($total / PER_PAGE));
$page = min($page, $pageCount);

$stmt = db()->prepare(
    "SELECT pf.*, so.title AS song_title, i.name AS instrument_name, i.color
     FROM performances pf
     JOIN songs so ON so.id = pf.song_id
     JOIN instruments i ON i.id = pf.instrument_id
     $whereSql
     ORDER BY pf.played_at DESC, pf.id DESC
     LIMIT ? OFFSET ?"
);
$stmt->execute([...$params, PER_PAGE, ($page - 1) * PER_PAGE]);
$performances = $stmt->fetchAll();

$songs = db()->query('SELECT id, title FROM songs ORDER BY title')->fetchAll();
$instruments = db()->query('SELECT id, name FROM instruments ORDER BY id')->fetchAll();

// Query string filter, dipakai ulang di tautan halaman dan form hapus.
$filterQuery = http_build_query(array_filter(['song_id' => $songId, 'instrument_id' => $instrumentId]));

$pageTitle = 'Nilai permainan';
$activeNav = 'performances';
require __DIR__ . '/../includes/admin_header.php';
?>
<h1 class="wavy">Nilai permainan</h1>
<p class="muted">Nilai dihitung server dari ketepatan nada. Hapus yang tidak wajar supaya papan skor tetap jujur.</p>

<form method="get" action="<?= e(url('admin/performances.php')) ?>" class="form form-inline filter-bar">
    <label for="song_id">Lagu</label>
    <select id="song_id" name="song_id">
        <option value="0">Semua lagu</option>
        <?php foreach ($songs as $s): ?>
            <option value="<?= (int) $s['id'] ?>"<?= (int) $s['id'] === $songId ? ' selected' : '' ?>><?= e($s['title']) ?></option>
        <?php endforeach; ?>
    </select>
    <label for="instrument_id">Instrumen</label>
    <select id="instrument_id" name="instrument_id">
        <option value="0">Semua instrumen</option>
        <?php foreach ($instruments as $i): ?>
            <option value="<?= (int) $i['id'] ?>"<?= (int) $i['id'] === $instrumentId ? ' selected' : '' ?>><?= e($i['name']) ?></option>
        <?php endforeach; ?>
    </select>
    <button type="submit" class="btn">Saring</button>
</form>

<?php if ($performances === []): ?>
    <div class="empty-state paper">
        <img src="<?= e(url('assets/img/mascot-wave.svg')) ?>" alt="" width="90" height="105">
        <p>Belum ada yang main<?= $where === [] ? '' : ' dengan saringan ini' ?>. Nilai akan muncul di sini setelah permainan pertama.</p>
    </div>
<?php else: ?>
    <p class="muted"><?= $total ?> nilai, halaman <?= $page ?> dari <?= $pageCount ?>.</p>
    <div class="table-wrap">
        <table class="table table-cards">
            <thead>
            <tr>
                <th>Waktu</th>
                <th>Nickname</th>
                <th>Lagu</th>
                <th>Instrumen</th>
                <th>Part</th>
                <th>Mode</th>
                <th>Kena</th>
                <th>Akurasi</th>
                <th><span class="sr-only">Aksi</span></th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($performances as $row): ?>
                <tr>
                    <td data-label="Waktu"><?= e(format_datetime($row['played_at'])) ?></td>
                    <td data-label="Nickname"><strong><?= e($row['nickname']) ?></strong></td>
                    <td data-label="Lagu"><?= e($row['song_title']) ?></td>
                    <td data-label="Instrumen"><?= color_swatch($row['color']) ?> <?= e($row['instrument_name']) ?></td>
                    <td data-label="Part"><?= e(PART_STYLES[$row['part_style']] ?? $row['part_style']) ?>, tempo <?= e($row['tempo_percent']) ?>%</td>
                    <td data-label="Mode"><?= e(PERFORMANCE_MODES[$row['mode']] ?? $row['mode']) ?></td>
                    <td data-label="Kena"><?= e($row['hits']) ?> / <?= e($row['total_notes']) ?></td>
                    <td data-label="Akurasi"><strong><?= e(number_format((float) $row['accuracy'], 1, ',', '')) ?>%</strong></td>
                    <td class="row-actions">
                        <form method="post" action="<?= e(url('admin/performance_delete.php')) ?>"
                              onsubmit="return confirm('Hapus nilai ini dari papan skor?');">
                            <?= csrf_field() ?>
                            <input type="hidden" name="id" value="<?= (int) $row['id'] ?>">
                            <input type="hidden" name="back" value="<?= e($filterQuery . ($filterQuery === '' ? '' : '&') . 'page=' . $page) ?>">
                            <button type="submit" class="btn btn-small btn-danger">Hapus</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <?php if ($pageCount > 1): ?>
        <nav class="pager" aria-label="Halaman">
            <?php if ($page > 1): ?>
                <a class="btn btn-small" href="<?= e(url('admin/performances.php?' . $filterQuery . '&page=' . ($page - 1))) ?>">Sebelumnya</a>
            <?php endif; ?>
            <?php if ($page < $pageCount): ?>
                <a class="btn btn-small" href="<?= e(url('admin/performances.php?' . $filterQuery . '&page=' . ($page + 1))) ?>">Berikutnya</a>
            <?php endif; ?>
        </nav>
    <?php endif; ?>
<?php endif; ?>
<?php require __DIR__ . '/../includes/admin_footer.php'; ?>
