<?php
// Mode Arranger: pemain memilih akor dengan tangan kiri, browser memainkan
// iringan (drum, bass, akor) yang mengikuti akor tersebut, dan pemain memainkan
// melodi di atasnya. Input keyboard laptop; tombol di layar juga bisa diklik.
// Tanpa nilai dan tanpa papan skor. Logika ada di assets/js/arranger*.js.

require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/content.php';
require __DIR__ . '/includes/arranger.php';
require_once __DIR__ . '/includes/music.php';

$styles = fetch_published_arranger_styles();
$melodyInstruments = array_values(array_filter(
    fetch_active_instruments(),
    fn (array $instrument): bool => $instrument['type'] !== 'drum'
));

$chordKeys = ['Z', 'X', 'C', 'V', 'B', 'N', 'M'];
$sectionKeys = ['intro' => 'A', 'main_a' => 'S', 'main_b' => 'D', 'fill' => 'F', 'ending' => 'G'];

$pageTitle = 'Arranger';
$activeNav = 'arranger';
$bodyClass = 'page-arranger';
if ($styles !== [] && $melodyInstruments !== []) {
    $pageScripts = ['assets/js/arranger.js'];
}
require __DIR__ . '/includes/layout_header.php';
?>
<section class="arranger" id="arranger">
    <div class="arr-head">
        <h1 class="wavy">Arranger</h1>
        <p class="muted">Tangan kiri memilih akor, iringan mengikuti. Tangan kanan memainkan melodi.</p>
    </div>

    <?php if ($styles === [] || $melodyInstruments === []): ?>
        <div class="empty-state paper">
            <img src="<?= e(url('assets/img/mascot-oops.svg')) ?>" alt="" width="90" height="105">
            <p>Belum ada style iringan yang tayang. Admin bisa menambahkannya di dashboard.</p>
        </div>
    <?php else: ?>
        <script type="application/json" id="arranger-data"><?= json_encode(
            ['styles' => $styles, 'instruments' => array_map(fn ($i) => ['slug' => $i['slug'], 'name' => $i['name']], $melodyInstruments)],
            JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE
        ) ?></script>

        <div class="start-gate paper" id="start-gate">
            <p class="laptop-notice" role="note">
                <strong>Paling enak dengan keyboard laptop.</strong>
                Di ponsel atau tablet, tombol akor, bagian, dan tuts di layar tetap bisa ditekan.
            </p>
            <button type="button" class="btn btn-primary btn-big" id="btn-start">Nyalakan arranger</button>
            <p class="status" id="status" role="status">Klik tombol di atas untuk menyalakan suara.</p>
        </div>

        <div class="arr-main" id="arr-main" hidden>
            <div class="arr-controls paper">
                <label class="arr-field">
                    <span class="test-label">Style</span>
                    <select id="arr-style">
                        <?php foreach ($styles as $style): ?>
                            <option value="<?= (int) $style['id'] ?>"><?= e($style['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <div class="arr-field">
                    <span class="test-label" id="tempo-title">Tempo <kbd>&uarr;</kbd><kbd>&darr;</kbd></span>
                    <div class="stepper" role="group" aria-labelledby="tempo-title">
                        <button type="button" class="btn btn-small" id="tempo-down" aria-label="Pelankan tempo">-</button>
                        <output id="tempo-label">100 bpm</output>
                        <button type="button" class="btn btn-small" id="tempo-up" aria-label="Cepatkan tempo">+</button>
                    </div>
                </div>
                <div class="arr-field">
                    <span class="test-label" id="transpose-title">Nada dasar <kbd>&larr;</kbd><kbd>&rarr;</kbd></span>
                    <div class="stepper" role="group" aria-labelledby="transpose-title">
                        <button type="button" class="btn btn-small" id="transpose-down" aria-label="Turunkan nada dasar">-</button>
                        <output id="transpose-label">Do = C</output>
                        <button type="button" class="btn btn-small" id="transpose-up" aria-label="Naikkan nada dasar">+</button>
                    </div>
                </div>
                <label class="arr-field">
                    <span class="test-label">Melodi</span>
                    <select id="arr-melody">
                        <?php foreach ($melodyInstruments as $instrument): ?>
                            <option value="<?= e($instrument['slug']) ?>"><?= e($instrument['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <div class="arr-extra">
                    <label class="arr-check">
                        <input type="checkbox" id="arr-sync">
                        Mulai bersama akor pertama
                    </label>
                    <details class="arr-mixer">
                        <summary>Volume</summary>
                        <div class="arr-mixer-box paper">
                            <?php foreach (['master' => 'Semua', 'drum' => 'Drum', 'bass' => 'Bass', 'chord' => 'Akor'] as $key => $label): ?>
                                <label class="arr-slider">
                                    <span><?= e($label) ?></span>
                                    <input type="range" min="0" max="100" value="<?= $key === 'master' ? 80 : 75 ?>" data-volume="<?= e($key) ?>">
                                </label>
                            <?php endforeach; ?>
                        </div>
                    </details>
                </div>
            </div>

            <div class="arr-panel paper">
                <div class="arr-sections" role="group" aria-label="Bagian iringan">
                    <?php foreach (ARRANGER_SECTIONS as $key => $label): ?>
                        <button type="button" class="arr-btn" data-section="<?= e($key) ?>">
                            <?= e($label) ?> <span class="key-cap"><?= e($sectionKeys[$key]) ?></span>
                        </button>
                    <?php endforeach; ?>
                    <button type="button" class="arr-btn arr-play" id="arr-play">
                        <span id="arr-play-label">Mulai</span> <span class="key-cap">Spasi</span>
                    </button>
                </div>

                <div class="arr-display" aria-live="polite">
                    <div class="arr-chord">
                        <span class="test-label">Akor</span>
                        <strong id="arr-chord-name">C</strong>
                        <span class="arr-next" id="arr-chord-next"></span>
                    </div>
                    <div class="arr-state">
                        <span class="test-label">Bagian</span>
                        <strong id="arr-section-name">Berhenti</strong>
                        <span class="arr-next" id="arr-section-next"></span>
                    </div>
                    <div class="arr-beats" id="arr-beats" aria-hidden="true"></div>
                </div>

                <div class="arr-chords" role="group" aria-label="Akor">
                    <?php foreach ($chordKeys as $index => $key): ?>
                        <button type="button" class="arr-btn arr-chord-btn" data-degree="<?= $index + 1 ?>">
                            <strong class="chord-label"><?= e(chord_name($index + 1, 0)) ?></strong>
                            <span class="key-cap"><?= e($key) ?></span>
                        </button>
                    <?php endforeach; ?>
                    <button type="button" class="arr-btn arr-flip" id="arr-flip" aria-pressed="false">
                        Mayor/minor <span class="key-cap">Shift</span>
                    </button>
                </div>
            </div>

            <div class="inst-view arr-inst is-interactive" id="inst-view"></div>
            <p class="arr-help muted small">
                Akor: <kbd>Z</kbd> sampai <kbd>M</kbd> (tahan <kbd>Shift</kbd> untuk membalik mayor/minor), berlaku di seperdelapan ketukan berikutnya.
                Bagian: <kbd>A</kbd> Intro, <kbd>S</kbd> Main A, <kbd>D</kbd> Main B, <kbd>F</kbd> Fill, <kbd>G</kbd> Ending, berlaku di birama berikutnya.
                Melodi: <kbd>Q</kbd>-<kbd>U</kbd>, <kbd>1</kbd>-<kbd>8</kbd>, <kbd>9</kbd>-<kbd>\</kbd>.
            </p>
        </div>
    <?php endif; ?>
</section>
<?php require __DIR__ . '/includes/layout_footer.php'; ?>
