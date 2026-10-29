<?php
// Form tambah gestur (?instrument_id=angka) dan ubah gestur (?id=angka).
// Satu gestur = "angkat N jari, bunyikan bunyi X" untuk satu instrumen.

require __DIR__ . '/../includes/bootstrap.php';
require __DIR__ . '/../includes/content.php';
$user = require_role('admin');

$id = (int) ($_GET['id'] ?? 0);
$isEdit = $id > 0;

if ($isEdit) {
    $gesture = fetch_row_or_404('SELECT * FROM gestures WHERE id = ?', [$id], 'Gestur tidak ditemukan');
    $instrumentId = (int) $gesture['instrument_id'];
} else {
    $instrumentId = (int) ($_GET['instrument_id'] ?? 0);
    $gesture = ['finger_count' => '', 'sound_id' => '', 'is_active' => 1];
}

$instrument = fetch_row_or_404('SELECT id, name FROM instruments WHERE id = ?', [$instrumentId], 'Instrumen tidak ditemukan');

// Pilihan bunyi: hanya milik instrumen ini.
$stmt = db()->prepare('SELECT id, name, code FROM sounds WHERE instrument_id = ? ORDER BY sort_order, id');
$stmt->execute([$instrumentId]);
$sounds = $stmt->fetchAll();

// Jumlah jari yang sudah dipakai gestur lain, untuk ditandai di pilihan.
$stmt = db()->prepare(
    'SELECT g.finger_count, s.name
     FROM gestures g JOIN sounds s ON s.id = g.sound_id
     WHERE g.instrument_id = ? AND g.id <> ?'
);
$stmt->execute([$instrumentId, $id]);
$usedFingers = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);   // [jumlah_jari => nama_bunyi]

$errors = [];

if (is_post()) {
    csrf_verify();

    $gesture['finger_count'] = post_str('finger_count');
    $gesture['sound_id'] = post_str('sound_id');
    $gesture['is_active'] = isset($_POST['is_active']) ? 1 : 0;

    $fingerCount = ctype_digit($gesture['finger_count']) ? (int) $gesture['finger_count'] : 0;
    if ($fingerCount < 1 || $fingerCount > 10) {
        $errors['finger_count'] = 'Jumlah jari harus 1 sampai 10.';
    } elseif (isset($usedFingers[$fingerCount])) {
        $errors['finger_count'] = "{$fingerCount} jari sudah dipakai untuk bunyi {$usedFingers[$fingerCount]}. "
            . 'Ubah pemetaan itu dulu, atau pilih jumlah jari lain.';
    }

    $soundId = (int) $gesture['sound_id'];
    if (!in_array($soundId, array_map('intval', array_column($sounds, 'id')), true)) {
        $errors['sound_id'] = 'Pilih bunyi milik instrumen ini.';
    }

    if ($errors === []) {
        try {
            if ($isEdit) {
                $stmt = db()->prepare('UPDATE gestures SET finger_count = ?, sound_id = ?, is_active = ? WHERE id = ?');
                $stmt->execute([$fingerCount, $soundId, $gesture['is_active'], $id]);
            } else {
                $stmt = db()->prepare(
                    'INSERT INTO gestures (instrument_id, finger_count, sound_id, is_active) VALUES (?, ?, ?, ?)'
                );
                $stmt->execute([$instrumentId, $fingerCount, $soundId, $gesture['is_active']]);
            }
            flash('success', "Gestur {$fingerCount} jari tersimpan.");
            redirect('admin/instrument.php?id=' . $instrumentId);
        } catch (PDOException $e) {
            // Cadangan bila dua admin menyimpan jumlah jari yang sama di saat bersamaan.
            if (db_error_number($e) !== 1062) {
                throw $e;
            }
            $errors['finger_count'] = "{$fingerCount} jari baru saja dipakai gestur lain. Pilih jumlah jari lain.";
        }
    }
}

$pageTitle = $isEdit ? 'Ubah gestur' : 'Tambah gestur';
$activeNav = 'instruments';
$formAction = url($isEdit ? 'admin/gesture_form.php?id=' . $id : 'admin/gesture_form.php?instrument_id=' . $instrumentId);
require __DIR__ . '/../includes/admin_header.php';
?>
<p class="crumb">
    <a href="<?= e(url('admin/instruments.php')) ?>">Instrumen</a> /
    <a href="<?= e(url('admin/instrument.php?id=' . $instrumentId)) ?>"><?= e($instrument['name']) ?></a> /
    <?= e($pageTitle) ?>
</p>
<h1 class="wavy"><?= e($pageTitle) ?></h1>

<?php if ($sounds === []): ?>
    <p class="alert alert-error">Instrumen ini belum punya bunyi. Tambahkan bunyi dulu.</p>
<?php else: ?>
    <?php if ($errors !== []): ?>
        <p class="alert alert-error" role="alert">Ada isian yang perlu dibetulkan. Lihat tanda merah di bawah.</p>
    <?php endif; ?>

    <form method="post" action="<?= e($formAction) ?>" class="form form-panel paper" novalidate>
        <?= csrf_field() ?>

        <label for="finger_count">Jumlah jari terangkat</label>
        <select id="finger_count" name="finger_count">
            <option value="">Pilih...</option>
            <?php for ($n = 1; $n <= 10; $n++): ?>
                <option value="<?= $n ?>"<?= (string) $n === (string) $gesture['finger_count'] ? ' selected' : '' ?>>
                    <?= $n ?> jari<?= isset($usedFingers[$n]) ? ' (sudah dipakai: ' . e($usedFingers[$n]) . ')' : '' ?>
                </option>
            <?php endfor; ?>
        </select>
        <p class="hint">Total dari kedua tangan. 6 sampai 10 jari berarti dua tangan terangkat.</p>
        <?php if (isset($errors['finger_count'])): ?><p class="field-error"><?= e($errors['finger_count']) ?></p><?php endif; ?>

        <label for="sound_id">Bunyi</label>
        <select id="sound_id" name="sound_id">
            <option value="">Pilih...</option>
            <?php foreach ($sounds as $s): ?>
                <option value="<?= (int) $s['id'] ?>"<?= (string) $s['id'] === (string) $gesture['sound_id'] ? ' selected' : '' ?>>
                    <?= e($s['name']) ?> (<?= e($s['code']) ?>)
                </option>
            <?php endforeach; ?>
        </select>
        <?php if (isset($errors['sound_id'])): ?><p class="field-error"><?= e($errors['sound_id']) ?></p><?php endif; ?>

        <label class="check">
            <input type="checkbox" name="is_active" value="1" <?= (int) $gesture['is_active'] === 1 ? 'checked' : '' ?>>
            Aktif
        </label>

        <div class="form-actions">
            <button type="submit" class="btn btn-primary">Simpan</button>
            <a class="btn btn-ghost" href="<?= e(url('admin/instrument.php?id=' . $instrumentId)) ?>">Batal</a>
        </div>
    </form>
<?php endif; ?>
<?php require __DIR__ . '/../includes/admin_footer.php'; ?>
