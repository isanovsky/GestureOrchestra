<?php
// Form tambah lagu (tanpa ?id) dan ubah data lagu (?id=angka).
// Partitur diisi terpisah di admin/song.php.

require __DIR__ . '/../includes/bootstrap.php';
require __DIR__ . '/../includes/content.php';
require_once __DIR__ . '/../includes/music.php';
$user = require_role('admin');

$id = (int) ($_GET['id'] ?? 0);
$isEdit = $id > 0;

$song = [
    'title' => '', 'composer' => '', 'bpm' => 90, 'beats_per_bar' => 4,
    'default_transpose' => 0, 'difficulty' => 'easy', 'description' => '',
];

if ($isEdit) {
    $song = fetch_row_or_404('SELECT * FROM songs WHERE id = ?', [$id], 'Lagu tidak ditemukan');
}

$errors = [];

if (is_post()) {
    csrf_verify();

    $song['title'] = post_str('title');
    $song['composer'] = post_str('composer');
    $song['bpm'] = post_str('bpm');
    $song['beats_per_bar'] = post_str('beats_per_bar');
    $song['default_transpose'] = post_str('default_transpose');
    $song['difficulty'] = post_str('difficulty');
    $song['description'] = post_str('description');

    if ($song['title'] === '' || mb_strlen($song['title']) > 150) {
        $errors['title'] = 'Judul wajib diisi, maksimal 150 karakter.';
    }
    if (mb_strlen($song['composer']) > 150) {
        $errors['composer'] = 'Nama pencipta maksimal 150 karakter.';
    }
    // Rentang bpm mengikuti CHECK di database (40 sampai 200).
    if (!ctype_digit($song['bpm']) || (int) $song['bpm'] < 40 || (int) $song['bpm'] > 200) {
        $errors['bpm'] = 'Tempo harus 40 sampai 200 ketukan per menit.';
    }
    if (!ctype_digit($song['beats_per_bar']) || (int) $song['beats_per_bar'] < 2 || (int) $song['beats_per_bar'] > 12) {
        $errors['beats_per_bar'] = 'Ketukan per birama 2 sampai 12.';
    }
    // Nada dasar: hanya nilai yang ada di pilihan (-5 sampai 6, lihat KEY_OPTIONS).
    if (!preg_match('/^-?\d{1,2}$/', $song['default_transpose']) || !array_key_exists((int) $song['default_transpose'], KEY_OPTIONS)) {
        $errors['default_transpose'] = 'Pilih nada dasar lagu.';
    }
    if (!array_key_exists($song['difficulty'], DIFFICULTIES)) {
        $errors['difficulty'] = 'Pilih tingkat kesulitan.';
    }
    if (mb_strlen($song['description']) > 2000) {
        $errors['description'] = 'Deskripsi maksimal 2000 karakter.';
    }

    if ($errors === []) {
        $values = [
            $song['title'],
            $song['composer'] === '' ? null : $song['composer'],
            (int) $song['bpm'],
            (int) $song['beats_per_bar'],
            (int) $song['default_transpose'],
            $song['difficulty'],
            $song['description'] === '' ? null : $song['description'],
        ];

        if ($isEdit) {
            $stmt = db()->prepare(
                'UPDATE songs SET title = ?, composer = ?, bpm = ?, beats_per_bar = ?, default_transpose = ?, difficulty = ?, description = ?
                 WHERE id = ?'
            );
            $stmt->execute([...$values, $id]);
            flash('success', 'Data lagu tersimpan.');
            redirect('admin/songs.php');
        }

        // Lagu baru selalu mulai sebagai draf (is_published = 0).
        $stmt = db()->prepare(
            'INSERT INTO songs (title, composer, bpm, beats_per_bar, default_transpose, difficulty, description, is_published, created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, 0, ?)'
        );
        $stmt->execute([...$values, (int) $user['id']]);
        $newId = (int) db()->lastInsertId();
        flash('success', 'Lagu ditambahkan sebagai draf. Sekarang tambahkan part dan tulis partiturnya.');
        redirect('admin/song.php?id=' . $newId);
    }
}

$pageTitle = $isEdit ? 'Ubah lagu' : 'Tambah lagu';
$activeNav = 'songs';
$formAction = url('admin/song_form.php' . ($isEdit ? '?id=' . $id : ''));
require __DIR__ . '/../includes/admin_header.php';
?>
<p class="crumb"><a href="<?= e(url('admin/songs.php')) ?>">Lagu</a> / <?= e($pageTitle) ?></p>
<h1 class="wavy"><?= e($pageTitle) ?></h1>

<?php if ($errors !== []): ?>
    <p class="alert alert-error" role="alert">Ada isian yang perlu dibetulkan. Lihat tanda merah di bawah.</p>
<?php endif; ?>

<form method="post" action="<?= e($formAction) ?>" class="form form-panel paper" novalidate>
    <?= csrf_field() ?>

    <label for="title">Judul</label>
    <input id="title" name="title" type="text" maxlength="150" required value="<?= e($song['title']) ?>">
    <?php if (isset($errors['title'])): ?><p class="field-error"><?= e($errors['title']) ?></p><?php endif; ?>

    <label for="composer">Pencipta, opsional</label>
    <input id="composer" name="composer" type="text" maxlength="150" value="<?= e($song['composer']) ?>">
    <?php if (isset($errors['composer'])): ?><p class="field-error"><?= e($errors['composer']) ?></p><?php endif; ?>

    <div class="field-row">
        <div>
            <label for="bpm">Tempo (bpm)</label>
            <input id="bpm" name="bpm" type="number" min="40" max="200" required value="<?= e($song['bpm']) ?>">
            <?php if (isset($errors['bpm'])): ?><p class="field-error"><?= e($errors['bpm']) ?></p><?php endif; ?>
        </div>
        <div>
            <label for="beats_per_bar">Ketukan per birama</label>
            <input id="beats_per_bar" name="beats_per_bar" type="number" min="2" max="12" required value="<?= e($song['beats_per_bar']) ?>">
            <?php if (isset($errors['beats_per_bar'])): ?><p class="field-error"><?= e($errors['beats_per_bar']) ?></p><?php endif; ?>
        </div>
    </div>
    <p class="hint">Tempo pelan (70 sampai 90) lebih ramah untuk gestur tangan.</p>

    <label for="default_transpose">Nada dasar</label>
    <select id="default_transpose" name="default_transpose">
        <?php foreach (KEY_OPTIONS as $value => $label): ?>
            <option value="<?= $value ?>"<?= (string) $value === (string) $song['default_transpose'] ? ' selected' : '' ?>><?= e($label) ?></option>
        <?php endforeach; ?>
    </select>
    <p class="hint">Partitur tetap ditulis dalam Do (not angka). Pilih sesuai tanda "Do = ..." di buku lagu.
        Nilai ini juga dipakai importer untuk mengubah nama akor menjadi derajat.</p>
    <?php if (isset($errors['default_transpose'])): ?><p class="field-error"><?= e($errors['default_transpose']) ?></p><?php endif; ?>

    <label for="difficulty">Tingkat kesulitan</label>
    <select id="difficulty" name="difficulty">
        <?php foreach (DIFFICULTIES as $value => $label): ?>
            <option value="<?= e($value) ?>"<?= $value === $song['difficulty'] ? ' selected' : '' ?>><?= e($label) ?></option>
        <?php endforeach; ?>
    </select>
    <?php if (isset($errors['difficulty'])): ?><p class="field-error"><?= e($errors['difficulty']) ?></p><?php endif; ?>

    <label for="description">Deskripsi, opsional</label>
    <textarea id="description" name="description" maxlength="2000" rows="3"><?= e($song['description']) ?></textarea>
    <?php if (isset($errors['description'])): ?><p class="field-error"><?= e($errors['description']) ?></p><?php endif; ?>

    <div class="form-actions">
        <button type="submit" class="btn btn-primary">Simpan</button>
        <a class="btn btn-ghost" href="<?= e(url('admin/songs.php')) ?>">Batal</a>
    </div>
</form>
<?php require __DIR__ . '/../includes/admin_footer.php'; ?>
