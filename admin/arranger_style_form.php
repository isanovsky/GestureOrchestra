<?php
// Form tambah style (tanpa ?id) dan ubah data style (?id=angka).
// Pola diisi terpisah di admin/arranger_patterns.php.

require __DIR__ . '/../includes/bootstrap.php';
require __DIR__ . '/../includes/arranger.php';
$user = require_role('admin');

$id = (int) ($_GET['id'] ?? 0);
$isEdit = $id > 0;

$style = [
    'name' => '', 'slug' => '', 'description' => '', 'default_bpm' => 100,
    'beats_per_bar' => 4, 'chord_voice' => 'piano', 'sort_order' => 0,
];

if ($isEdit) {
    $style = fetch_row_or_404('SELECT * FROM arranger_styles WHERE id = ?', [$id], 'Style tidak ditemukan');
}

$errors = [];

if (is_post()) {
    csrf_verify();

    foreach (['name', 'slug', 'description', 'default_bpm', 'beats_per_bar', 'chord_voice', 'sort_order'] as $field) {
        $style[$field] = post_str($field);
    }
    // Slug kosong: dibuat dari nama ("Pop Rock" -> "pop-rock").
    if ($style['slug'] === '') {
        $style['slug'] = trim(preg_replace('/[^a-z0-9]+/', '-', strtolower($style['name'])), '-');
    }

    if ($style['name'] === '' || mb_strlen($style['name']) > 80) {
        $errors['name'] = 'Nama wajib diisi, maksimal 80 karakter.';
    }
    if (!preg_match('/^[a-z0-9-]{2,60}$/', $style['slug'])) {
        $errors['slug'] = 'Slug 2 sampai 60 karakter: huruf kecil, angka, dan tanda minus.';
    } else {
        $stmt = db()->prepare('SELECT COUNT(*) FROM arranger_styles WHERE slug = ? AND id <> ?');
        $stmt->execute([$style['slug'], $id]);
        if ((int) $stmt->fetchColumn() > 0) {
            $errors['slug'] = 'Slug ini sudah dipakai style lain.';
        }
    }
    if (mb_strlen($style['description']) > 255) {
        $errors['description'] = 'Deskripsi maksimal 255 karakter.';
    }
    // Rentang mengikuti CHECK di database.
    if (!ctype_digit($style['default_bpm']) || (int) $style['default_bpm'] < 40 || (int) $style['default_bpm'] > 200) {
        $errors['default_bpm'] = 'Tempo harus 40 sampai 200 ketukan per menit.';
    }
    if (!ctype_digit($style['beats_per_bar']) || (int) $style['beats_per_bar'] < 2 || (int) $style['beats_per_bar'] > 12) {
        $errors['beats_per_bar'] = 'Ketukan per birama 2 sampai 12.';
    }
    if (!array_key_exists($style['chord_voice'], ARRANGER_CHORD_VOICES)) {
        $errors['chord_voice'] = 'Pilih suara akor.';
    }
    if (!preg_match('/^-?\d{1,4}$/', $style['sort_order'])) {
        $errors['sort_order'] = 'Urutan harus bilangan bulat.';
    }

    if ($errors === []) {
        $values = [
            $style['name'],
            $style['slug'],
            $style['description'] === '' ? null : $style['description'],
            (int) $style['default_bpm'],
            (int) $style['beats_per_bar'],
            $style['chord_voice'],
            (int) $style['sort_order'],
        ];

        if ($isEdit) {
            $stmt = db()->prepare(
                'UPDATE arranger_styles SET name = ?, slug = ?, description = ?, default_bpm = ?, beats_per_bar = ?, chord_voice = ?, sort_order = ?
                 WHERE id = ?'
            );
            $stmt->execute([...$values, $id]);
            flash('success', 'Data style tersimpan.');
            redirect('admin/arranger_styles.php');
        }

        // Style baru selalu mulai sebagai draf.
        $stmt = db()->prepare(
            'INSERT INTO arranger_styles (name, slug, description, default_bpm, beats_per_bar, chord_voice, sort_order, is_published)
             VALUES (?, ?, ?, ?, ?, ?, ?, 0)'
        );
        $stmt->execute($values);
        $newId = (int) db()->lastInsertId();
        flash('success', 'Style ditambahkan sebagai draf. Sekarang tulis polanya, minimal Main A.');
        redirect('admin/arranger_patterns.php?style=' . $newId);
    }
}

$pageTitle = $isEdit ? 'Ubah style' : 'Tambah style';
$activeNav = 'arranger';
$formAction = url('admin/arranger_style_form.php' . ($isEdit ? '?id=' . $id : ''));
require __DIR__ . '/../includes/admin_header.php';
?>
<p class="crumb"><a href="<?= e(url('admin/arranger_styles.php')) ?>">Style arranger</a> / <?= e($pageTitle) ?></p>
<h1 class="wavy"><?= e($pageTitle) ?></h1>

<?php if ($errors !== []): ?>
    <p class="alert alert-error" role="alert">Ada isian yang perlu dibetulkan. Lihat tanda merah di bawah.</p>
<?php endif; ?>

<form method="post" action="<?= e($formAction) ?>" class="form form-panel paper" novalidate>
    <?= csrf_field() ?>

    <label for="name">Nama</label>
    <input id="name" name="name" type="text" maxlength="80" required value="<?= e($style['name']) ?>">
    <?php if (isset($errors['name'])): ?><p class="field-error"><?= e($errors['name']) ?></p><?php endif; ?>

    <label for="slug">Slug, opsional</label>
    <input id="slug" name="slug" type="text" maxlength="60" value="<?= e($style['slug']) ?>">
    <p class="hint">Kosongkan untuk dibuat otomatis dari nama.</p>
    <?php if (isset($errors['slug'])): ?><p class="field-error"><?= e($errors['slug']) ?></p><?php endif; ?>

    <label for="description">Deskripsi, opsional</label>
    <input id="description" name="description" type="text" maxlength="255" value="<?= e($style['description']) ?>">
    <?php if (isset($errors['description'])): ?><p class="field-error"><?= e($errors['description']) ?></p><?php endif; ?>

    <div class="field-row">
        <div>
            <label for="default_bpm">Tempo awal (bpm)</label>
            <input id="default_bpm" name="default_bpm" type="number" min="40" max="200" required value="<?= e($style['default_bpm']) ?>">
            <?php if (isset($errors['default_bpm'])): ?><p class="field-error"><?= e($errors['default_bpm']) ?></p><?php endif; ?>
        </div>
        <div>
            <label for="beats_per_bar">Ketukan per birama</label>
            <input id="beats_per_bar" name="beats_per_bar" type="number" min="2" max="12" required value="<?= e($style['beats_per_bar']) ?>">
            <?php if (isset($errors['beats_per_bar'])): ?><p class="field-error"><?= e($errors['beats_per_bar']) ?></p><?php endif; ?>
        </div>
    </div>
    <p class="hint">Pergantian bagian (Main, Fill, Ending) menunggu awal birama berikutnya.</p>

    <label for="chord_voice">Suara akor</label>
    <select id="chord_voice" name="chord_voice">
        <?php foreach (ARRANGER_CHORD_VOICES as $value => $label): ?>
            <option value="<?= e($value) ?>"<?= $value === $style['chord_voice'] ? ' selected' : '' ?>><?= e($label) ?></option>
        <?php endforeach; ?>
    </select>
    <?php if (isset($errors['chord_voice'])): ?><p class="field-error"><?= e($errors['chord_voice']) ?></p><?php endif; ?>

    <label for="sort_order">Urutan di pilihan</label>
    <input id="sort_order" name="sort_order" type="number" value="<?= e($style['sort_order']) ?>">
    <?php if (isset($errors['sort_order'])): ?><p class="field-error"><?= e($errors['sort_order']) ?></p><?php endif; ?>

    <div class="form-actions">
        <button type="submit" class="btn btn-primary">Simpan</button>
        <a class="btn btn-ghost" href="<?= e(url('admin/arranger_styles.php')) ?>">Batal</a>
    </div>
</form>
<?php require __DIR__ . '/../includes/admin_footer.php'; ?>
