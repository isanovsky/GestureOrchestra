<?php
// Pilihan instrumen dan gaya main untuk form room (dipakai dua kali di room.php).
// Wajib sebelum include: $instruments, $prefix ('create' atau 'join').
?>
<fieldset class="room-pick">
    <legend class="test-label">Instrumen</legend>
    <div class="pick-row">
        <?php foreach ($instruments as $i => $option): ?>
            <label class="pick-card pick-card-small" style="--inst-color: <?= e($option['color']) ?>">
                <input type="radio" name="<?= e($prefix) ?>-instrument" value="<?= (int) $option['id'] ?>"
                       data-type="<?= e($option['type']) ?>"<?= $i === 0 ? ' checked' : '' ?>>
                <?= instrument_icon($option['type'], $option['color'], 'inst-icon inst-icon-small') ?>
                <span><?= e($option['name']) ?></span>
            </label>
        <?php endforeach; ?>
    </div>
</fieldset>
<fieldset class="room-pick">
    <legend class="test-label">Gaya main</legend>
    <div class="pick-row">
        <label class="pick-chip"><input type="radio" name="<?= e($prefix) ?>-style" value="notes" checked> Melodi</label>
        <label class="pick-chip"><input type="radio" name="<?= e($prefix) ?>-style" value="chords"> Akor</label>
    </div>
</fieldset>
