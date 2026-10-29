<?php
// Markup panggung bermain, dipakai play.php (dan room.php di Fase 4).
// Wajib sebelum include: $instrument (dari fetch_active_instruments()),
// $song (dari fetch_published_songs(), atau null untuk main bebas),
// $tempo (persen tempo latihan, 50 sampai 100), $style ('notes' atau 'chords').
// Opsional untuk mode room: $room dan $roomPlayer (room.php). Tanpa itu = mode solo.
// Semua elemen dengan id di bawah dipakai oleh assets/js/stage-setup.js,
// lalu solo.js (mode solo) atau lobby.js (mode room).
//
// Tata letak di laptop (lihat .stage.is-live di app.css), sama untuk solo dan
// room: seluruh panggung muat satu layar tanpa scroll. Dari atas:
//   1. lajur partitur selebar layar (bila ada lagu),
//   2. kamera + panel samping (contekan, pengaturan), lalu visual instrumen;
//      di mode keyboard, visual instrumen memakai tempat kamera,
//   3. baris kontrol: judul, strip pemain (room), tombol.

$isPitched = $instrument['type'] !== 'drum';
$isRoom = isset($room, $roomPlayer);
$startTranspose = $isRoom ? (int) $room['transpose'] : ($song === null ? 0 : (int) $song['default_transpose']);
// Part yang punya nada tanpa gestur kamera hanya bisa dimainkan penuh lewat keyboard.
$songPart = $song === null ? null : song_part($song, (int) $instrument['id'], $style);
$keyboardOnly = $songPart !== null && $songPart['keyboard_only'];
?>
<section class="stage" id="stage"
         style="--inst-color: <?= e($instrument['color']) ?>"
         data-instrument-slug="<?= e($instrument['slug']) ?>"
         data-song-id="<?= $song === null ? 0 : (int) $song['id'] ?>"
         data-part-style="<?= e($style) ?>"
         data-instrument-type="<?= e($instrument['type']) ?>"
         data-default-transpose="<?= $startTranspose ?>"
         <?php if ($isRoom): ?>data-room-code="<?= e($room['code']) ?>" data-player-id="<?= (int) $roomPlayer['id'] ?>"<?php endif; ?>
         data-free-bpm="<?= (int) round(FREE_MODE_BPM * $tempo / 100) ?>"
         data-tempo="<?= (int) $tempo ?>">

    <div class="stage-head">
        <span id="inst-icon" class="stage-icon"><?= instrument_icon($instrument['type'], $instrument['color']) ?></span>
        <div class="stage-title">
            <h1><?= e($instrument['name']) ?><?= $style === 'chords' ? ' (akor)' : '' ?><?= $song !== null ? ': ' . e($song['title']) : ', main bebas' ?></h1>
            <p class="muted">
                <?php if ($song !== null): ?>
                    <?php $effectiveBpm = $song['bpm'] * $tempo / 100; ?>
                    <?= e(round($effectiveBpm)) ?> bpm<?= $tempo !== 100 ? ' (tempo latihan ' . $tempo . '% dari ' . e($song['bpm']) . ')' : '' ?>,
                    <?= e(format_duration($song['length_seconds'] * 100 / $tempo)) ?>.
                <?php else: ?>
                    Tanpa lagu dan tanpa nilai. Bebas bereksperimen.
                <?php endif; ?>
            </p>
            <?php if ($keyboardOnly): ?>
                <p class="keys-note">Part ini punya nada tanpa gestur kamera (ditandai garis titik di lajur). Mainkan penuh lewat keyboard.</p>
            <?php endif; ?>
        </div>
        <?php if ($isRoom): ?>
            <!-- Strip pemain room: nama, warna instrumen, status. Diisi lobby.js. -->
            <ul class="room-strip" id="room-strip" aria-label="Pemain di room"></ul>
        <?php endif; ?>
        <div class="stage-actions">
            <div class="mode-switch" role="group" aria-label="Cara main" id="mode-switch" hidden>
                <button type="button" class="btn btn-small" data-mode="camera">Kamera</button>
                <button type="button" class="btn btn-small" data-mode="keys">Keyboard / pad</button>
            </div>
            <button type="button" class="btn btn-small" id="btn-fullscreen" hidden>Mode panggung</button>
            <?php if ($isRoom): ?>
                <button type="button" class="btn btn-small btn-ghost" id="btn-leave">Keluar room</button>
            <?php else: ?>
                <a class="btn btn-small btn-ghost" href="<?= e(url('play.php')) ?>">Ganti pilihan</a>
            <?php endif; ?>
        </div>
    </div>

    <!-- Gerbang: browser hanya mengizinkan suara dan kamera setelah diklik. -->
    <div class="start-gate paper" id="start-gate">
        <?php require __DIR__ . '/laptop_notice.php'; ?>
        <button type="button" class="btn btn-primary btn-big" id="btn-start">Siapkan instrumen</button>
        <p class="status" id="status" role="status">Klik tombol di atas untuk menyalakan suara dan memilih cara main.</p>
    </div>

    <div class="stage-body" id="stage-main" hidden>
        <div class="stage-screen">
            <div class="camera-box" id="camera-box">
                <video id="cam" playsinline muted></video>
                <canvas id="cam-overlay"></canvas>
                <p class="camera-status" id="camera-status"></p>
            </div>

            <!-- Mode keyboard: cukup satu kalimat; peta tombol ada di panel samping. -->
            <p class="keys-line" id="keys-box" hidden>
                <?php if ($instrument['type'] === 'drum'): ?>
                    Mode keyboard: tahan tombol <kbd>1</kbd> sampai <kbd>9</kbd> atau tekan bagian drum, lepas = diam.
                <?php else: ?>
                    Mode keyboard: tahan tombol atau tekan <?= $instrument['type'] === 'piano' ? 'tuts' : 'nada' ?> di bawah, lepas = diam. Peta tombol ada di panel samping.
                <?php endif; ?>
            </p>

            <!-- Hasil lagu tampil sebagai kartu di tengah layar, jadi tidak perlu scroll. -->
            <?php if ($song !== null): ?>
                <section class="result paper" id="result" hidden aria-live="polite">
                    <button type="button" class="result-close" id="result-close" aria-label="Tutup hasil" title="Tutup (Esc)">&times;</button>
                    <img class="result-mascot" id="result-mascot" src="<?= e(url('assets/img/mascot-wave.svg')) ?>" alt="" width="110" height="128">
                    <div class="result-body">
                        <p class="label-tag">Hasil</p>
                        <p class="result-score"><span id="result-accuracy">0</span>%</p>
                        <p id="result-detail" class="muted">Menghitung nilai di server...</p>

                        <?php if ($isRoom): ?>
                            <!-- Mode room: nilai semua pemain, diisi lobby.js -->
                            <ol class="room-results" id="room-results"></ol>
                            <p class="muted small" id="room-results-status">Menunggu nilai pemain lain...</p>
                            <div class="form-actions">
                                <a class="btn btn-primary" href="<?= e(url('room.php')) ?>">Room baru</a>
                                <a class="btn btn-ghost" href="<?= e(url('leaderboard.php?song=' . (int) $song['id'] . '&tempo=' . (int) $room['tempo_percent'])) ?>">Lihat papan skor</a>
                            </div>
                        <?php else: ?>
                            <form class="form form-inline" id="save-form" hidden>
                                <label for="nickname">Nickname</label>
                                <input type="text" id="nickname" maxlength="30" autocomplete="nickname" required placeholder="misalnya Rina">
                                <button type="submit" class="btn btn-primary">Simpan ke papan skor</button>
                            </form>
                            <p id="save-message" class="save-message" role="status"></p>

                            <div class="form-actions">
                                <button type="button" class="btn" id="btn-again">Main lagi</button>
                                <a class="btn btn-ghost" href="<?= e(url('leaderboard.php?song=' . (int) $song['id'])) ?>">Lihat papan skor</a>
                            </div>
                        <?php endif; ?>
                    </div>
                </section>
            <?php endif; ?>
        </div>

        <aside class="stage-side" id="stage-side">
            <p class="now-playing" id="now" aria-live="polite">Diam</p>

            <details class="side-section" id="cheat-section" open>
                <summary>Contekan jari</summary>
                <ul class="cheat" id="cheat">
                    <?php foreach ($instrument['gestures'] as $gesture): ?>
                        <li><span class="finger-count"><?= e($gesture['finger_count']) ?></span> <?= e($gesture['sound_name']) ?></li>
                    <?php endforeach; ?>
                </ul>
                <?php if ($isPitched): ?>
                    <ul class="cheat-notes">
                        <li>Untuk mengulang nada yang sama, kepalkan tangan dulu.</li>
                        <li>Akor yang sama di birama berikutnya cukup ditahan.</li>
                    </ul>
                <?php endif; ?>
            </details>

            <?php if ($isPitched): ?>
                <details class="side-section" id="keys-section">
                    <summary>Tombol keyboard</summary>
                    <!-- Diisi stage-setup.js dari keymap.js, hanya tombol yang punya bunyi -->
                    <div class="key-map" id="key-map"></div>
                </details>
            <?php endif; ?>

            <details class="side-section" id="settings-section">
                <summary>Pengaturan</summary>

                <div class="setting">
                    <span class="test-label" id="gesture-mode-title">Cara membaca jari</span>
                    <div class="mode-switch" role="group" aria-labelledby="gesture-mode-title">
                        <button type="button" class="btn btn-small" data-gesture-mode="sum">Jumlah kedua tangan</button>
                        <button type="button" class="btn btn-small" data-gesture-mode="leftPlus5">Tangan kiri +5</button>
                    </div>
                    <p class="setting-note" id="gesture-mode-note"></p>
                </div>

                <?php if ($isPitched): ?>
                    <div class="setting">
                        <span class="test-label" id="transpose-title">Nada dasar</span>
                        <div class="stepper" role="group" aria-labelledby="transpose-title">
                            <button type="button" class="btn btn-small" id="transpose-down" aria-label="Turunkan nada dasar">-</button>
                            <output id="transpose-label">Do = C</output>
                            <button type="button" class="btn btn-small" id="transpose-up" aria-label="Naikkan nada dasar">+</button>
                        </div>
                    </div>
                <?php endif; ?>

                <?php if ($isPitched && $song === null): ?>
                    <div class="setting">
                        <span class="test-label">Gaya main</span>
                        <div class="mode-switch" role="group" aria-label="Gaya main">
                            <button type="button" class="btn btn-small" data-style="notes">Melodi</button>
                            <button type="button" class="btn btn-small" data-style="chords">Akor</button>
                        </div>
                    </div>
                <?php endif; ?>

                <?php if ($instrument['play_mode'] === 'decay'): ?>
                    <div class="setting" id="chord-options" hidden>
                        <label for="bass" class="test-label">Bass otomatis</label>
                        <select id="bass" class="setting-select">
                            <option value="0">Mati</option>
                            <option value="1" selected>Satu oktaf di bawah</option>
                            <option value="2">Dua oktaf di bawah</option>
                        </select>
                        <p class="setting-note">Bass dua oktaf hampir tidak terdengar dari speaker laptop. Pakai headphone atau speaker luar.</p>

                        <label for="rhythm" class="test-label">Ritme akor</label>
                        <select id="rhythm" class="setting-select">
                            <option value="change">Hanya saat ganti akor</option>
                            <option value="half">Ketukan 1 dan 3</option>
                            <option value="beat">Setiap ketukan</option>
                        </select>
                    </div>
                <?php endif; ?>

                <?php if ($instrument['type'] === 'drum' && $song !== null): ?>
                    <label class="check-line">
                        <input type="checkbox" id="auto-hihat" checked>
                        Hi-hat otomatis tiap setengah ketuk (tidak dinilai)
                    </label>
                <?php endif; ?>

                <label for="volume" class="test-label">Volume</label>
                <input type="range" id="volume" min="0" max="100" value="80">

                <label for="reverb" class="test-label">Gema</label>
                <input type="range" id="reverb" min="0" max="100" value="25">
            </details>
        </aside>

        <div class="inst-view inst-view-<?= e($instrument['type']) ?>" id="inst-view">
            <?php if ($instrument['type'] === 'drum'): ?>
                <?php require __DIR__ . '/drum_kit.php'; ?>
            <?php endif; ?>
        </div>

        <?php if ($song !== null): ?>
            <div class="song-area" id="song-area">
                <div class="song-bar">
                    <span class="beat-dot" id="beat" aria-live="off">-</span>
                    <p class="hint" id="hint"><?= $isRoom ? 'Siapkan instrumen, lalu tunggu host memulai.' : 'Tekan Mulai lagu kalau sudah siap.' ?></p>
                    <button type="button" class="btn btn-primary" id="btn-play-song"<?= $isRoom ? ' hidden' : '' ?>><?= $isRoom ? 'Mulai bersama' : 'Mulai lagu' ?></button>
                </div>
                <div class="play-lane" id="lane">
                    <span class="lane-hitline" aria-hidden="true"></span>
                    <div class="lane-track" id="lane-track"></div>
                </div>
            </div>
        <?php endif; ?>
    </div>
</section>
