<?php
// Form tambah bunyi (?instrument_id=angka) dan ubah bunyi (?id=angka).

require __DIR__ . '/../includes/bootstrap.php';
require __DIR__ . '/../includes/content.php';
$user = require_role('admin');

$id = (int) ($_GET['id'] ?? 0);
$isEdit = $id > 0;

if ($isEdit) {
    $sound = fetch_row_or_404('SELECT * FROM sounds WHERE id = ?', [$id], 'Bunyi tidak ditemukan');
    $instrumentId = (int) $sound['instrument_id'];
} else {
    $instrumentId = (int) ($_GET['instrument_id'] ?? 0);
    $sound = ['name' => '', 'code' => '', 'frequency' => '', 'sample_path' => '', 'sort_order' => 0];
}

$instrument = fetch_row_or_404('SELECT id, name, type FROM instruments WHERE id = ?', [$instrumentId], 'Instrumen tidak ditemukan');
$needsFrequency = $instrument['type'] !== 'drum';

// Bunyi baru otomatis ditaruh paling akhir.
if (!$isEdit) {
    $stmt = db()->prepare('SELECT COALESCE(MAX(sort_order), 0) + 1 FROM sounds WHERE instrument_id = ?');
    $stmt->execute([$instrumentId]);
    $sound['sort_order'] = (int) $stmt->fetchColumn();
}

$errors = [];

if (is_post()) {
    csrf_verify();

    $sound['name'] = post_str('name');
    $sound['code'] = strtoupper(post_str('code'));
    $sound['frequency'] = post_str('frequency');
    $sound['sample_path'] = post_str('sample_path');
    $sound['sort_order'] = post_str('sort_order');

    // --- Validasi ---
    if ($sound['name'] === '' || mb_strlen($sound['name']) > 50) {
        $errors['name'] = 'Nama wajib diisi, maksimal 50 karakter.';
    }
    // Kode dipakai audio-engine.js, misalnya C4 untuk nada atau KICK untuk drum.
    if (!preg_match('/^[A-Z0-9#_-]{1,20}$/', $sound['code'])) {
        $errors['code'] = 'Kode 1 sampai 20 karakter: huruf, angka, #, _, atau -. Contoh: C4, F#4, KICK.';
    }

    $frequency = null;
    if ($sound['frequency'] !== '') {
        $frequency = parse_decimal($sound['frequency'], 2);
        if ($frequency === null || (float) $frequency < 20 || (float) $frequency > 20000) {
            $errors['frequency'] = 'Frekuensi berupa angka 20 sampai 20000 Hz, maksimal 2 angka di belakang koma.';
        }
    } elseif ($needsFrequency) {
        $errors['frequency'] = 'Frekuensi wajib untuk instrumen bernada (piano dan synth).';
    }

    // File sampel opsional. Dibatasi ke folder assets/audio/ supaya tidak bisa
    // menunjuk ke file lain di server atau ke situs luar.
    if ($sound['sample_path'] !== ''
        && !preg_match('#^assets/audio/[A-Za-z0-9_\-/]{1,200}\.(mp3|wav|ogg)$#', $sound['sample_path'])) {
        $errors['sample_path'] = 'Path harus di dalam assets/audio/ dan berakhiran .mp3, .wav, atau .ogg.';
    }

    if (!ctype_digit($sound['sort_order']) || (int) $sound['sort_order'] > 9999) {
        $errors['sort_order'] = 'Urutan berupa angka 0 sampai 9999.';
    }

    if (!isset($errors['code'])) {
        $stmt = db()->prepare('SELECT COUNT(*) FROM sounds WHERE instrument_id = ? AND code = ? AND id <> ?');
        $stmt->execute([$instrumentId, $sound['code'], $id]);
        if ((int) $stmt->fetchColumn() > 0) {
            $errors['code'] = 'Kode ini sudah dipakai bunyi lain di instrumen yang sama.';
        }
    }

    // --- Simpan ---
    if ($errors === []) {
        $values = [
            $sound['name'],
            $sound['code'],
            $frequency,
            $sound['sample_path'] === '' ? null : $sound['sample_path'],
            (int) $sound['sort_order'],
        ];

        if ($isEdit) {
            $stmt = db()->prepare(
                'UPDATE sounds SET name = ?, code = ?, frequency = ?, sample_path = ?, sort_order = ? WHERE id = ?'
            );
            $stmt->execute([...$values, $id]);
            flash('success', 'Bunyi ' . $sound['name'] . ' tersimpan.');
        } else {
            $stmt = db()->prepare(
                'INSERT INTO sounds (name, code, frequency, sample_path, sort_order, instrument_id)
                 VALUES (?, ?, ?, ?, ?, ?)'
            );
            $stmt->execute([...$values, $instrumentId]);
            flash('success', 'Bunyi ' . $sound['name'] . ' ditambahkan.');
        }
        redirect('admin/instrument.php?id=' . $instrumentId);
    }
}

$pageTitle = $isEdit ? 'Ubah bunyi' : 'Tambah bunyi';
$activeNav = 'instruments';
$formAction = url($isEdit ? 'admin/sound_form.php?id=' . $id : 'admin/sound_form.php?instrument_id=' . $instrumentId);
require __DIR__ . '/../includes/admin_header.php';
?>
<p class="crumb">
    <a href="<?= e(url('admin/instruments.php')) ?>">Instrumen</a> /
    <a href="<?= e(url('admin/instrument.php?id=' . $instrumentId)) ?>"><?= e($instrument['name']) ?></a> /
    <?= e($pageTitle) ?>
</p>
<h1 class="wavy"><?= e($pageTitle) ?></h1>

<?php if ($errors !== []): ?>
    <p class="alert alert-error" role="alert">Ada isian yang perlu dibetulkan. Lihat tanda merah di bawah.</p>
<?php endif; ?>

<form method="post" action="<?= e($formAction) ?>" class="form form-panel paper" novalidate>
    <?= csrf_field() ?>

    <label for="name">Nama bunyi</label>
    <input id="name" name="name" type="text" maxlength="50" required value="<?= e($sound['name']) ?>">
    <p class="hint">Yang tampil ke pemain, misalnya Do, Mi, atau Snare.</p>
    <?php if (isset($errors['name'])): ?><p class="field-error"><?= e($errors['name']) ?></p><?php endif; ?>

    <label for="code">Kode</label>
    <input id="code" name="code" type="text" maxlength="20" required value="<?= e($sound['code']) ?>">
    <p class="hint">Nada: C4, D4, F#4. Drum: KICK, SNARE, HIHAT, TOM, CRASH (dipakai untuk memilih suara drum sintesis).</p>
    <?php if (isset($errors['code'])): ?><p class="field-error"><?= e($errors['code']) ?></p><?php endif; ?>

    <label for="frequency">Frekuensi (Hz)<?= $needsFrequency ? '' : ', opsional' ?></label>
    <input id="frequency" name="frequency" type="text" inputmode="decimal" maxlength="9" value="<?= e($sound['frequency']) ?>">
    <p class="hint">Contoh: A4 = 440, C4 = 261.63.</p>
    <?php if (isset($errors['frequency'])): ?><p class="field-error"><?= e($errors['frequency']) ?></p><?php endif; ?>

    <label for="sample_path">File sampel, opsional</label>
    <input id="sample_path" name="sample_path" type="text" maxlength="255" placeholder="assets/audio/nama-file.mp3" value="<?= e($sound['sample_path']) ?>">
    <p class="hint">Bila diisi, file ini diputar menggantikan bunyi sintesis. Unggah filenya ke folder assets/audio/ lewat FileZilla.</p>
    <?php if (isset($errors['sample_path'])): ?><p class="field-error"><?= e($errors['sample_path']) ?></p><?php endif; ?>

    <label for="sort_order">Urutan tampil</label>
    <input id="sort_order" name="sort_order" type="number" min="0" max="9999" value="<?= e($sound['sort_order']) ?>">
    <?php if (isset($errors['sort_order'])): ?><p class="field-error"><?= e($errors['sort_order']) ?></p><?php endif; ?>

    <div class="form-actions">
        <button type="submit" class="btn btn-primary">Simpan</button>
        <a class="btn btn-ghost" href="<?= e(url('admin/instrument.php?id=' . $instrumentId)) ?>">Batal</a>
    </div>
</form>
<?php require __DIR__ . '/../includes/admin_footer.php'; ?>
