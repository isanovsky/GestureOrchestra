<?php
// Uji kamera: video, kerangka tangan, dan jumlah jari terdeteksi secara langsung,
// ditambah mode diagnostik dan tes akurasi terpandu (Fase 6 bagian A).
// Tidak butuh database; semua deteksi berjalan di browser dan hasil tes tidak disimpan.

require __DIR__ . '/includes/bootstrap.php';

$pageTitle = 'Uji kamera';
$activeNav = 'camera';
$bodyClass = 'page-play';
$pageScripts = ['assets/js/camera-test.js'];
require __DIR__ . '/includes/layout_header.php';
?>
<h1 class="wavy">Uji kamera</h1>
<p class="lead">Cek dulu sebelum main: apakah kamera melihat tanganmu dan menghitung jarinya dengan benar?</p>

<?php require __DIR__ . '/includes/laptop_notice.php'; ?>

<div class="test-grid">
    <div class="camera-box" id="camera-box">
        <video id="cam" playsinline muted></video>
        <canvas id="cam-overlay"></canvas>
        <p class="position-warning" id="position-warning" role="status" hidden></p>
        <div class="camera-gate" id="camera-gate">
            <button type="button" class="btn btn-primary btn-big" id="btn-camera">Nyalakan kamera</button>
            <p class="status" id="status" role="status">Kamera belum menyala.</p>
        </div>
        <!-- Aba-aba tes akurasi, tampil di atas video -->
        <div class="guide-overlay" id="guide-overlay" hidden aria-live="assertive">
            <p class="guide-target" id="guide-target">0</p>
            <p class="guide-pose" id="guide-pose"></p>
            <p class="guide-phase" id="guide-phase"></p>
            <div class="guide-bar"><span id="guide-bar"></span></div>
        </div>
    </div>

    <aside class="test-panel paper">
        <p class="test-label">Jumlah jari (setelah debounce)</p>
        <p class="big-count" id="stable-total">0</p>
        <p class="muted small">Angka mentah per frame: <strong id="raw-total">0</strong></p>

        <label for="pipeline" class="test-label">Cara hitung</label>
        <select id="pipeline" class="setting-select">
            <option value="standard">Standar: sudut jari, ibu jari dari jaraknya</option>
            <option value="leftPlus5">Tangan kiri +5</option>
            <option value="thumbAngle">Ibu jari dari sudut sendinya</option>
            <option value="legacy">Jarak 2D ke pergelangan (versi awal)</option>
        </select>

        <ul class="hand-list" id="hand-list">
            <li class="muted">Belum ada tangan terlihat.</li>
        </ul>

        <details class="side-section">
            <summary>Diagnostik</summary>
            <p class="small" id="perf">Prosesor: -, 0 fps</p>
            <label for="delegate" class="test-label">Prosesor deteksi</label>
            <select id="delegate" class="setting-select">
                <option value="auto">Otomatis (GPU, cadangan CPU)</option>
                <option value="CPU">CPU saja</option>
            </select>
            <p class="muted small">Ganti untuk membandingkan fps. Sudut tiap jari (180 = lurus) ada di kartu tangan di atas.</p>

            <label for="stable-ms" class="test-label">Lama debounce: <strong id="stable-ms-value">100</strong> ms</label>
            <input type="range" id="stable-ms" min="0" max="400" step="20" value="100">
            <p class="muted small">Makin besar, makin tenang tetapi makin lambat bereaksi. 0 = tanpa debounce.</p>
        </details>
    </aside>
</div>

<section class="paper accuracy-test" id="accuracy-test">
    <h2>Tes akurasi terpandu</h2>
    <p>Halaman akan meminta hitungan 0 sampai 10, lalu turun lagi dari 9 sampai 0. Setiap hitungan: 2 detik
        bersiap, lalu 3 detik tahan; hanya 2 detik terakhir yang dinilai. Semua cara hitung dinilai sekaligus
        dari frame yang sama, jadi cukup satu kali tes (sekitar 105 detik).</p>
    <ul class="small">
        <li>0 sampai 5: tangan kanan saja, tangan kiri diturunkan.</li>
        <li>6 sampai 9: tangan kiri terbuka (5) ditambah tangan kanan.</li>
        <li>10: kedua tangan terbuka.</li>
        <li>Jaga ujung jari di dalam garis putus-putus dan pergelangan di atas garis oranye.</li>
    </ul>
    <label for="palm-facing" class="test-label">Arah tangan selama tes</label>
    <select id="palm-facing" class="setting-select palm-select">
        <option value="palm">Telapak ke kamera</option>
        <option value="back">Punggung tangan ke kamera</option>
    </select>
    <div class="form-actions">
        <button type="button" class="btn btn-primary" id="btn-test" disabled>Mulai tes akurasi</button>
        <button type="button" class="btn btn-ghost" id="btn-test-stop" hidden>Hentikan</button>
    </div>
    <p class="muted small" id="test-status">Nyalakan kamera dulu.</p>

    <div class="test-result" id="test-result" hidden>
        <div class="table-wrap">
        <table class="table accuracy-table">
            <thead>
                <tr>
                    <th scope="col">Hitungan</th>
                    <th scope="col">Standar</th>
                    <th scope="col">Ibu jari sudut</th>
                    <th scope="col">Kiri +5</th>
                    <th scope="col">Jarak 2D</th>
                    <th scope="col">Posisi</th>
                    <th scope="col">Tangan kurang</th>
                    <th scope="col">Label berubah</th>
                </tr>
            </thead>
            <tbody id="test-rows"></tbody>
        </table>
        </div>
        <p class="muted small">Persentase frame yang terbaca benar selama 2 detik terakhir tiap hitungan. "Posisi" =
            frame yang tidak dinilai karena ujung jari keluar dari kamera. "Tangan kurang" = frame yang mendeteksi
            tangan lebih sedikit dari yang diminta. "Label berubah" = frame yang label kiri/kanan MediaPipe-nya
            berbeda dari label yang paling sering untuk tangan di sisi yang sama.</p>
        <div class="form-actions">
            <button type="button" class="btn" id="btn-copy">Salin tabel</button>
            <button type="button" class="btn btn-ghost" id="btn-download">Unduh rekaman (JSON)</button>
        </div>
        <label for="test-text" class="test-label">Teks tabel</label>
        <textarea id="test-text" class="test-text" rows="8" readonly></textarea>
        <p class="muted small">Rekaman berisi titik-titik tangan setiap frame (bukan video), untuk dibandingkan ulang dengan
            <code>node tests/js/evaluate-finger-count.mjs rekaman.json</code>.</p>
    </div>
</section>

<section class="tips">
    <h2>Kalau hitungannya meleset</h2>
    <ul>
        <li>Duduk sekitar satu lengan dari kamera, seluruh telapak tangan masuk layar.</li>
        <li>Hadapkan telapak ke kamera dan buka jari lebar-lebar, termasuk ibu jari.</li>
        <li>Hindari cahaya terang dari belakang (jendela di belakang badan).</li>
        <li>Untuk 6 sampai 10 jari, angkat kedua tangan dan beri jarak di antara keduanya.</li>
        <li>Masih sulit di 6 ke atas? Coba cara "Tangan kiri +5" di pengaturan halaman bermain.</li>
    </ul>
</section>
<?php require __DIR__ . '/includes/layout_footer.php'; ?>
