<?php
// Form tambah instrumen (tanpa ?id) dan ubah instrumen (?id=angka).

require __DIR__ . '/../includes/bootstrap.php';
require __DIR__ . '/../includes/content.php';
$user = require_role('admin');

$id = (int) ($_GET['id'] ?? 0);
$isEdit = $id > 0;

$instrument = [
    'name' => '', 'slug' => '', 'type' => 'piano', 'play_mode' => 'decay',
    'color' => '#E4572E', 'description' => '', 'is_active' => 1,
];

if ($isEdit) {
    $instrument = fetch_row_or_404('SELECT * FROM instruments WHERE id = ?', [$id], 'Instrumen tidak ditemukan');
}

$errors = [];

if (is_post()) {
    csrf_verify();

    $instrument['name'] = post_str('name');
    $instrument['slug'] = strtolower(post_str('slug'));
    $instrument['type'] = post_str('type');
    $instrument['play_mode'] = post_str('play_mode');
    $instrument['color'] = strtoupper(post_str('color'));
    $instrument['description'] = post_str('description');
    $instrument['is_active'] = isset($_POST['is_active']) ? 1 : 0;

    // --- Validasi ---
    if ($instrument['name'] === '' || mb_strlen($instrument['name']) > 50) {
        $errors['name'] = 'Nama wajib diisi, maksimal 50 karakter.';
    }
    // Slug dipakai di URL dan API, misalnya api/instrument.php?slug=piano
    if (!preg_match('/^[a-z0-9-]{2,50}$/', $instrument['slug'])) {
        $errors['slug'] = 'Slug 2 sampai 50 karakter: huruf kecil, angka, atau tanda minus.';
    }
    if (!array_key_exists($instrument['type'], INSTRUMENT_TYPES)) {
        $errors['type'] = 'Pilih tipe instrumen.';
    }
    if (!array_key_exists($instrument['play_mode'], PLAY_MODES)) {
        $errors['play_mode'] = 'Pilih mode main.';
    }
    if (!preg_match('/^#[0-9A-F]{6}$/', $instrument['color'])) {
        $errors['color'] = 'Warna harus berformat #RRGGBB, misalnya #E4572E.';
    }
    if (mb_strlen($instrument['description']) > 255) {
        $errors['description'] = 'Deskripsi maksimal 255 karakter.';
    }

    if (!isset($errors['slug'])) {
        $stmt = db()->prepare('SELECT COUNT(*) FROM instruments WHERE slug = ? AND id <> ?');
        $stmt->execute([$instrument['slug'], $id]);
        if ((int) $stmt->fetchColumn() > 0) {
            $errors['slug'] = 'Slug ini sudah dipakai instrumen lain.';
        }
    }

    // Instrumen bernada wajib punya frekuensi di setiap bunyinya. Bila tipe
    // diganti dari drum ke piano/synth, bunyi tanpa frekuensi harus dilengkapi dulu.
    if ($isEdit && !isset($errors['type']) && $instrument['type'] !== 'drum') {
        $stmt = db()->prepare('SELECT COUNT(*) FROM sounds WHERE instrument_id = ? AND frequency IS NULL');
        $stmt->execute([$id]);
        $missing = (int) $stmt->fetchColumn();
        if ($missing > 0) {
            $errors['type'] = "Ada {$missing} bunyi tanpa frekuensi. Isi frekuensinya dulu sebelum mengganti tipe ke instrumen bernada.";
        }
    }

    // --- Simpan ---
    if ($errors === []) {
        $values = [
            $instrument['name'],
            $instrument['slug'],
            $instrument['type'],
            $instrument['play_mode'],
            $instrument['color'],
            $instrument['description'] === '' ? null : $instrument['description'],
            $instrument['is_active'],
        ];

        if ($isEdit) {
            $stmt = db()->prepare(
                'UPDATE instruments
                 SET name = ?, slug = ?, type = ?, play_mode = ?, color = ?, description = ?, is_active = ?
                 WHERE id = ?'
            );
            $stmt->execute([...$values, $id]);
            flash('success', 'Instrumen ' . $instrument['name'] . ' tersimpan.');
            redirect('admin/instruments.php');
        }

        $stmt = db()->prepare(
            'INSERT INTO instruments (name, slug, type, play_mode, color, description, is_active)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute($values);
        $newId = (int) db()->lastInsertId();
        flash('success', 'Instrumen ' . $instrument['name'] . ' ditambahkan. Sekarang isi bunyi dan gesturnya.');
        redirect('admin/instrument.php?id=' . $newId);
    }
}

$pageTitle = $isEdit ? 'Ubah instrumen' : 'Tambah instrumen';
$activeNav = 'instruments';
$formAction = url('admin/instrument_form.php' . ($isEdit ? '?id=' . $id : ''));
require __DIR__ . '/../includes/admin_header.php';
?>
<p class="crumb"><a href="<?= e(url('admin/instruments.php')) ?>">Instrumen</a> / <?= e($pageTitle) ?></p>
<h1 class="wavy"><?= e($pageTitle) ?></h1>

<?php if ($errors !== []): ?>
    <p class="alert alert-error" role="alert">Ada isian yang perlu dibetulkan. Lihat tanda merah di bawah.</p>
<?php endif; ?>

<form method="post" action="<?= e($formAction) ?>" class="form form-panel paper" novalidate>
    <?= csrf_field() ?>

    <label for="name">Nama instrumen</label>
    <input id="name" name="name" type="text" maxlength="50" required value="<?= e($instrument['name']) ?>">
    <?php if (isset($errors['name'])): ?><p class="field-error"><?= e($errors['name']) ?></p><?php endif; ?>

    <label for="slug">Slug</label>
    <input id="slug" name="slug" type="text" maxlength="50" required value="<?= e($instrument['slug']) ?>">
    <p class="hint">Nama pendek untuk URL, misalnya <code>piano</code> atau <code>synth</code>.</p>
    <?php if (isset($errors['slug'])): ?><p class="field-error"><?= e($errors['slug']) ?></p><?php endif; ?>

    <label for="type">Tipe</label>
    <select id="type" name="type">
        <?php foreach (INSTRUMENT_TYPES as $value => $label): ?>
            <option value="<?= e($value) ?>"<?= $value === $instrument['type'] ? ' selected' : '' ?>><?= e($label) ?></option>
        <?php endforeach; ?>
    </select>
    <p class="hint">Piano dan synth wajib punya frekuensi di setiap bunyi. Drum tidak.</p>
    <?php if (isset($errors['type'])): ?><p class="field-error"><?= e($errors['type']) ?></p><?php endif; ?>

    <label for="play_mode">Mode main</label>
    <select id="play_mode" name="play_mode">
        <?php foreach (PLAY_MODES as $value => $label): ?>
            <option value="<?= e($value) ?>"<?= $value === $instrument['play_mode'] ? ' selected' : '' ?>><?= e($label) ?></option>
        <?php endforeach; ?>
    </select>
    <?php if (isset($errors['play_mode'])): ?><p class="field-error"><?= e($errors['play_mode']) ?></p><?php endif; ?>

    <label for="color">Warna</label>
    <input id="color" name="color" type="color" value="<?= e(strtolower($instrument['color'])) ?>">
    <p class="hint">Dipakai di ikon, tombol, dan lajur permainan instrumen ini.</p>
    <?php if (isset($errors['color'])): ?><p class="field-error"><?= e($errors['color']) ?></p><?php endif; ?>

    <label for="description">Deskripsi singkat</label>
    <textarea id="description" name="description" maxlength="255" rows="2"><?= e($instrument['description']) ?></textarea>
    <?php if (isset($errors['description'])): ?><p class="field-error"><?= e($errors['description']) ?></p><?php endif; ?>

    <label class="check">
        <input type="checkbox" name="is_active" value="1" <?= (int) $instrument['is_active'] === 1 ? 'checked' : '' ?>>
        Aktif (tampil di halaman publik dan bisa dipilih pemain)
    </label>

    <div class="form-actions">
        <button type="submit" class="btn btn-primary">Simpan</button>
        <a class="btn btn-ghost" href="<?= e(url('admin/instruments.php')) ?>">Batal</a>
    </div>
</form>
<?php require __DIR__ . '/../includes/admin_footer.php'; ?>
