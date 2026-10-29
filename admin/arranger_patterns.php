<?php
// Editor pola satu style arranger: 5 bagian x 3 track (drum, bass, akor).
// Semua bagian disimpan sekaligus dalam satu transaksi, setelah semua pola lolos
// validasi (includes/arranger.php). Bagian dengan panjang kosong tidak dipakai.

require __DIR__ . '/../includes/bootstrap.php';
require __DIR__ . '/../includes/arranger.php';
$user = require_role('admin');

$styleId = (int) ($_GET['style'] ?? 0);
$style = fetch_row_or_404('SELECT * FROM arranger_styles WHERE id = ?', [$styleId], 'Style tidak ditemukan');

// Isi form: dari database, atau dari kiriman bila ada yang salah.
$form = [];
foreach (ARRANGER_SECTIONS as $section => $label) {
    $form[$section] = ['length' => '', 'tracks' => array_fill_keys(array_keys(ARRANGER_TRACKS), '')];
}
$stmt = db()->prepare(
    'SELECT sec.section, sec.length_beats, p.track, p.events
     FROM arranger_sections sec
     LEFT JOIN arranger_patterns p ON p.section_id = sec.id
     WHERE sec.style_id = ?'
);
$stmt->execute([$styleId]);
foreach ($stmt->fetchAll() as $row) {
    $form[$row['section']]['length'] = (string) $row['length_beats'];
    if ($row['track'] !== null) {
        $form[$row['section']]['tracks'][$row['track']] = $row['events'];
    }
}

$errors = [];

if (is_post()) {
    csrf_verify();

    $lengths = is_array($_POST['length'] ?? null) ? $_POST['length'] : [];
    $texts = is_array($_POST['events'] ?? null) ? $_POST['events'] : [];
    foreach (ARRANGER_SECTIONS as $section => $label) {
        $form[$section]['length'] = trim((string) ($lengths[$section] ?? ''));
        foreach (ARRANGER_TRACKS as $track => $trackLabel) {
            $text = $texts[$section][$track] ?? '';
            $form[$section]['tracks'][$track] = is_string($text) ? trim(str_replace(["\r\n", "\r"], "\n", $text)) : '';
        }
    }

    foreach (ARRANGER_SECTIONS as $section => $label) {
        $length = $form[$section]['length'];
        $hasText = implode('', $form[$section]['tracks']) !== '';
        if ($length === '') {
            if ($hasText) {
                $errors[$section]['length'] = 'Isi panjang bagian (ketukan), atau kosongkan semua polanya.';
            } elseif ($section === 'main_a') {
                $errors[$section]['length'] = 'Main A wajib ada: ini pola yang diulang terus.';
            }
            continue;
        }
        if (!ctype_digit($length) || (int) $length < 1 || (int) $length > 32) {
            $errors[$section]['length'] = 'Panjang 1 sampai 32 ketukan.';
            continue;
        }
        foreach (ARRANGER_TRACKS as $track => $trackLabel) {
            $result = arranger_parse_pattern($form[$section]['tracks'][$track], $track, (int) $length);
            if ($result['errors'] !== []) {
                $errors[$section][$track] = $result['errors'];
            }
        }
    }

    if ($errors === []) {
        $pdo = db();
        $pdo->beginTransaction();
        try {
            foreach (ARRANGER_SECTIONS as $section => $label) {
                $length = $form[$section]['length'];
                if ($length === '') {
                    $stmt = $pdo->prepare('DELETE FROM arranger_sections WHERE style_id = ? AND section = ?');
                    $stmt->execute([$styleId, $section]);
                    continue;
                }
                $stmt = $pdo->prepare(
                    'INSERT INTO arranger_sections (style_id, section, length_beats) VALUES (?, ?, ?)
                     ON DUPLICATE KEY UPDATE length_beats = VALUES(length_beats)'
                );
                $stmt->execute([$styleId, $section, (int) $length]);
                $stmt = $pdo->prepare('SELECT id FROM arranger_sections WHERE style_id = ? AND section = ?');
                $stmt->execute([$styleId, $section]);
                $sectionId = (int) $stmt->fetchColumn();

                foreach (ARRANGER_TRACKS as $track => $trackLabel) {
                    $text = $form[$section]['tracks'][$track];
                    if ($text === '') {
                        $stmt = $pdo->prepare('DELETE FROM arranger_patterns WHERE section_id = ? AND track = ?');
                        $stmt->execute([$sectionId, $track]);
                        continue;
                    }
                    $stmt = $pdo->prepare(
                        'INSERT INTO arranger_patterns (section_id, track, events) VALUES (?, ?, ?)
                         ON DUPLICATE KEY UPDATE events = VALUES(events)'
                    );
                    $stmt->execute([$sectionId, $track, $text]);
                }
            }
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
        flash('success', 'Pola style ' . $style['name'] . ' tersimpan.');
        redirect('admin/arranger_patterns.php?style=' . $styleId);
    }
}

$pageTitle = 'Pola ' . $style['name'];
$activeNav = 'arranger';
require __DIR__ . '/../includes/admin_header.php';
?>
<p class="crumb"><a href="<?= e(url('admin/arranger_styles.php')) ?>">Style arranger</a> / <?= e($pageTitle) ?></p>
<h1 class="wavy"><?= e($pageTitle) ?></h1>
<p class="muted"><?= e($style['default_bpm']) ?> bpm, <?= e($style['beats_per_bar']) ?> ketukan per birama,
    suara akor: <?= e(ARRANGER_CHORD_VOICES[$style['chord_voice']] ?? $style['chord_voice']) ?>.</p>

<details class="paper pattern-help">
    <summary>Cara menulis pola</summary>
    <p>Satu kejadian per baris. Tanda <code>#</code> sampai akhir baris adalah komentar.</p>
    <ul>
        <li><strong>Drum</strong>: <code>&lt;ketukan&gt; &lt;KODE&gt; [velocity]</code>, misalnya <code>1 SNARE 0.8</code>.
            Kode: <?= e(implode(', ', ARRANGER_DRUM_CODES)) ?> (KDUT dan KTAK = kendang dut dan tak, TAMB = tamborin, OHAT = hi-hat terbuka).</li>
        <li><strong>Bass</strong>: <code>&lt;ketukan&gt; &lt;nada&gt; [durasi] [velocity]</code>, misalnya <code>2 5- 1 0.8</code>. Satu nada per baris.</li>
        <li><strong>Akor</strong>: sama dengan bass, nada boleh digabung: <code>0 R35 1 0.6</code> (akor penuh), <code>0.5 5</code> (satu nada, untuk arpeggio).</li>
    </ul>
    <p>Ketukan dihitung dari 0 di awal bagian: <code>0</code>, <code>1.5</code>, atau pecahan seperti <code>4/3</code> untuk triplet.
        Nada mengikuti akor yang sedang dipilih pemain: <code>R</code> dasar, <code>3</code> terts, <code>5</code> kuint, <code>8</code> oktaf;
        akhiran <code>-</code> = satu oktaf lebih rendah. Durasi dalam ketukan (bawaan 1), velocity lebih dari 0 sampai 1 (bawaan 1).</p>
    <p>Intro dan Fill lanjut ke Main setelah selesai; Main diulang terus; Ending menghentikan iringan.
        Bagian yang panjangnya dikosongkan tidak dipakai (Main B kosong = memakai Main A).</p>
</details>

<?php if ($errors !== []): ?>
    <p class="alert alert-error" role="alert">Ada pola yang perlu dibetulkan. Lihat tanda merah di bawah. Belum ada yang disimpan.</p>
<?php endif; ?>

<form method="post" action="<?= e(url('admin/arranger_patterns.php?style=' . $styleId)) ?>" class="form pattern-form" novalidate>
    <?= csrf_field() ?>
    <?php foreach (ARRANGER_SECTIONS as $section => $label): ?>
        <fieldset class="pattern-section paper">
            <legend><?= e($label) ?></legend>
            <label for="length-<?= e($section) ?>">Panjang (ketukan)</label>
            <input id="length-<?= e($section) ?>" name="length[<?= e($section) ?>]" type="number" min="1" max="32"
                   class="pattern-length" value="<?= e($form[$section]['length']) ?>">
            <?php if (isset($errors[$section]['length'])): ?><p class="field-error"><?= e($errors[$section]['length']) ?></p><?php endif; ?>
            <div class="pattern-tracks">
                <?php foreach (ARRANGER_TRACKS as $track => $trackLabel): ?>
                    <div>
                        <label for="events-<?= e($section . '-' . $track) ?>"><?= e($trackLabel) ?></label>
                        <textarea id="events-<?= e($section . '-' . $track) ?>" name="events[<?= e($section) ?>][<?= e($track) ?>]"
                                  rows="8" spellcheck="false" class="pattern-text"><?= e($form[$section]['tracks'][$track]) ?></textarea>
                        <?php foreach ($errors[$section][$track] ?? [] as $message): ?>
                            <p class="field-error"><?= e($message) ?></p>
                        <?php endforeach; ?>
                    </div>
                <?php endforeach; ?>
            </div>
        </fieldset>
    <?php endforeach; ?>
    <div class="form-actions">
        <button type="submit" class="btn btn-primary">Simpan semua pola</button>
        <a class="btn btn-ghost" href="<?= e(url('admin/arranger_styles.php')) ?>">Kembali</a>
    </div>
</form>
<?php require __DIR__ . '/../includes/admin_footer.php'; ?>
