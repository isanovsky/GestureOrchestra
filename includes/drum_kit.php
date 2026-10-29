<?php
// Ilustrasi set drum (SVG inline, gambar orisinal kelompok) untuk panggung drum.
// Bagian-bagiannya berjajar ke samping supaya tetap besar di bawah video.
// Setiap bagian adalah <g data-code="..."> sesuai kolom sounds.code, supaya
// assets/js/instrument-view.js bisa menyalakannya dan memakainya sebagai pad.
// Wajib sebelum include: $instrument (dengan 'gestures').

// Label "jumlah jari + nama" per kode bunyi, diambil dari pemetaan gestur.
$drumLabels = [];
foreach ($instrument['gestures'] as $gesture) {
    $drumLabels[$gesture['sound_code']] ??= $gesture['finger_count'] . ' ' . $gesture['sound_name'];
}
$drumLabel = fn(string $code): string => e($drumLabels[$code] ?? $code);
$ink = '#3B2A1E';
$line = 'fill="none" stroke="' . $ink . '" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"';
?>
<svg class="drum-kit" viewBox="0 0 960 120" preserveAspectRatio="xMidYMid meet" role="img" aria-label="Set drum">
    <!-- Kick: drum besar -->
    <g data-code="KICK" class="drum-part">
        <circle class="part-fill" cx="70" cy="62" r="44" stroke="<?= $ink ?>" stroke-width="3"/>
        <circle cx="70" cy="62" r="18" fill="#FFFAF1" stroke="<?= $ink ?>" stroke-width="2"/>
        <text x="70" y="116" class="drum-label"><?= $drumLabel('KICK') ?></text>
    </g>
    <!-- Rim click: bingkai drum kecil -->
    <g data-code="RIM" class="drum-part drum-rim">
        <path class="part-fill" d="M168 52 L170 82 Q206 94 242 82 L244 52 Z" stroke="<?= $ink ?>" stroke-width="3"/>
        <ellipse class="part-stroke" cx="206" cy="52" rx="38" ry="10" fill="#FFFAF1" stroke="<?= $ink ?>" stroke-width="6"/>
        <text x="206" y="116" class="drum-label"><?= $drumLabel('RIM') ?></text>
    </g>
    <!-- Hi-hat: dua piringan bertumpuk -->
    <g data-code="HIHAT" class="drum-part">
        <path d="M340 50 L340 98" <?= $line ?>/>
        <ellipse class="part-fill" cx="340" cy="38" rx="44" ry="8" stroke="<?= $ink ?>" stroke-width="3"/>
        <ellipse class="part-fill" cx="340" cy="50" rx="44" ry="8" stroke="<?= $ink ?>" stroke-width="3"/>
        <text x="340" y="116" class="drum-label"><?= $drumLabel('HIHAT') ?></text>
    </g>
    <!-- Snare -->
    <g data-code="SNARE" class="drum-part">
        <path class="part-fill" d="M432 46 L434 84 Q474 98 514 84 L516 46 Z" stroke="<?= $ink ?>" stroke-width="3"/>
        <ellipse cx="474" cy="46" rx="42" ry="11" fill="#FFFAF1" stroke="<?= $ink ?>" stroke-width="2.5"/>
        <path d="M444 62 L452 80 M464 64 L470 86 M484 64 L478 86 M504 62 L496 80" <?= $line ?> stroke-width="2"/>
        <text x="474" y="116" class="drum-label"><?= $drumLabel('SNARE') ?></text>
    </g>
    <!-- Cymbal lembut -->
    <g data-code="CYMBAL" class="drum-part">
        <path d="M612 44 L612 98" <?= $line ?>/>
        <ellipse class="part-fill" cx="612" cy="38" rx="52" ry="10" stroke="<?= $ink ?>" stroke-width="3" transform="rotate(-6 612 38)"/>
        <text x="612" y="116" class="drum-label"><?= $drumLabel('CYMBAL') ?></text>
    </g>
    <!-- Tom -->
    <g data-code="TOM" class="drum-part">
        <path class="part-fill" d="M710 40 L712 82 Q746 96 780 82 L782 40 Z" stroke="<?= $ink ?>" stroke-width="3"/>
        <ellipse cx="746" cy="40" rx="36" ry="10" fill="#FFFAF1" stroke="<?= $ink ?>" stroke-width="2.5"/>
        <text x="746" y="116" class="drum-label"><?= $drumLabel('TOM') ?></text>
    </g>
    <!-- Crash: piringan besar -->
    <g data-code="CRASH" class="drum-part">
        <path d="M880 40 L882 98" <?= $line ?>/>
        <ellipse class="part-fill" cx="880" cy="32" rx="62" ry="12" stroke="<?= $ink ?>" stroke-width="3" transform="rotate(8 880 32)"/>
        <text x="880" y="116" class="drum-label"><?= $drumLabel('CRASH') ?></text>
    </g>
</svg>
