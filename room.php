<?php
// Main bersama (multiplayer).
//
// Tanpa parameter          : form "Buat room" dan "Gabung room" (assets/js/room-forms.js).
// ?code=K7Q2MX&player=12   : lobby + panggung untuk pemain itu (assets/js/lobby.js).
//
// Identitas pemain disimpan sebagai token di sessionStorage browser (per tab),
// bukan di URL. Id pemain di URL hanya menentukan instrumen yang ditampilkan;
// semua aksi tetap memerlukan token.

require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/content.php';
require __DIR__ . '/includes/icons.php';
require __DIR__ . '/includes/rooms.php';

$instruments = fetch_active_instruments();
$songs = fetch_published_songs();
$instrumentsById = array_column($instruments, null, 'id');

$code = normalize_room_code($_GET['code'] ?? null);
$room = null;
$roomPlayer = null;

if ($code !== null) {
    $room = find_room($code);
    if ($room === null) {
        render_error_page(404, 'Room tidak ditemukan', 'Room ini sudah kedaluwarsa atau ditutup host. Buat room baru atau gabung dengan kode lain.');
    }
    $stmt = db()->prepare('SELECT * FROM room_players WHERE id = ? AND room_id = ?');
    $stmt->execute([(int) ($_GET['player'] ?? 0), (int) $room['id']]);
    $roomPlayer = $stmt->fetch() ?: null;
    if ($roomPlayer === null) {
        render_error_page(404, 'Pemain tidak ditemukan', 'Kamu mungkin sudah keluar dari room ini. Gabung lagi dengan kode ' . $code . '.');
    }

    $instrument = $instrumentsById[(int) $roomPlayer['instrument_id']] ?? null;
    $song = null;
    foreach ($songs as $candidate) {
        if ((int) $candidate['id'] === (int) $room['song_id']) {
            $song = $candidate;
        }
    }
    if ($instrument === null || $song === null) {
        render_error_page(409, 'Lagu atau instrumen tidak tersedia', 'Admin baru saja menyembunyikan lagu atau instrumen room ini. Buat room baru.');
    }
    $tempo = (int) $room['tempo_percent'];
    $style = $roomPlayer['part_style'];
}

$pageTitle = $room === null ? 'Main bersama' : 'Room ' . $room['code'];
$activeNav = 'room';
$bodyClass = 'page-play';
$pageScripts = [$room === null ? 'assets/js/room-forms.js' : 'assets/js/lobby.js'];
require __DIR__ . '/includes/layout_header.php';
?>
<?php if ($room === null): ?>
    <h1 class="wavy">Main bersama</h1>
    <p class="lead">Satu orang membuat room, sampai tiga teman bergabung dengan kodenya. Setiap perangkat
        membunyikan instrumennya sendiri, jadi duduklah berdekatan supaya terdengar seperti satu band.</p>

    <?php if ($songs === []): ?>
        <p class="alert alert-error">Belum ada lagu yang tayang, jadi room belum bisa dibuat.</p>
    <?php else: ?>
        <div class="room-forms">
            <form class="paper form room-form" id="create-form" novalidate>
                <h2>Buat room</h2>
                <label for="create-nickname">Nickname</label>
                <input type="text" id="create-nickname" maxlength="30" required placeholder="misalnya Rina">

                <label for="create-song">Lagu</label>
                <select id="create-song">
                    <?php foreach ($songs as $option): ?>
                        <option value="<?= (int) $option['id'] ?>" data-transpose="<?= (int) $option['default_transpose'] ?>"
                                data-parts="<?= e(implode(',', array_map(fn($p) => $p['instrument_id'] . ':' . $p['style'], $option['parts']))) ?>"
                                data-keyboard-parts="<?= e(implode(',', array_map(fn($p) => $p['instrument_id'] . ':' . $p['style'], array_filter($option['parts'], fn($p) => $p['keyboard_only'])))) ?>">
                            <?= e($option['title']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>

                <?php $prefix = 'create'; require __DIR__ . '/includes/room_part_picker.php'; ?>

                <div class="field-row">
                    <div>
                        <label for="create-transpose">Nada dasar</label>
                        <select id="create-transpose">
                            <?php foreach (KEY_OPTIONS as $value => $label): ?>
                                <option value="<?= $value ?>"><?= e($label) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label for="create-tempo">Tempo</label>
                        <select id="create-tempo">
                            <?php foreach (array_reverse(TEMPO_OPTIONS) as $option): ?>
                                <option value="<?= $option ?>"><?= $option ?>%</option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <p class="hint">Nada dasar dan tempo berlaku untuk semua pemain, dan masih bisa diubah di lobby.</p>
                <p class="keys-note" id="create-keys-note" hidden>Part pilihanmu punya nada tanpa gestur kamera. Mainkan penuh lewat keyboard.</p>

                <p class="form-error" id="create-error" role="alert"></p>
                <button type="submit" class="btn btn-primary btn-big">Buat room</button>
            </form>

            <form class="paper form room-form" id="join-form" novalidate>
                <h2>Gabung room</h2>
                <label for="join-code">Kode room</label>
                <input type="text" id="join-code" class="code-input" maxlength="6" required autocomplete="off" placeholder="K7Q2MX">

                <label for="join-nickname">Nickname</label>
                <input type="text" id="join-nickname" maxlength="30" required placeholder="misalnya Ari">

                <?php $prefix = 'join'; require __DIR__ . '/includes/room_part_picker.php'; ?>
                <p class="hint">Instrumen boleh sama dengan pemain lain. Bila lagu di room tidak punya bagian
                    untuk pilihanmu, kamu akan diberi tahu.</p>

                <p class="form-error" id="join-error" role="alert"></p>
                <button type="submit" class="btn btn-primary btn-big">Gabung</button>
            </form>
        </div>
    <?php endif; ?>
<?php else: ?>
    <section class="lobby paper" id="lobby">
        <div class="lobby-code">
            <p class="test-label">Kode room</p>
            <p class="room-code" id="room-code"><?= e($room['code']) ?></p>
            <p class="muted small">Bagikan kode ini. Maksimal <?= (int) $room['max_players'] ?> pemain.</p>
        </div>
        <div class="lobby-main">
            <h1 class="lobby-title"><?= e($song['title']) ?></h1>
            <p class="lobby-settings" id="lobby-settings">
                <span id="lobby-key">Do = <?= e(key_name((int) $room['transpose'])) ?></span>,
                tempo <span id="lobby-tempo"><?= (int) $room['tempo_percent'] ?></span>%
            </p>
            <div class="host-settings" id="host-settings" hidden>
                <label for="room-transpose">Nada dasar</label>
                <select id="room-transpose">
                    <?php foreach (KEY_OPTIONS as $value => $label): ?>
                        <option value="<?= $value ?>"<?= $value === (int) $room['transpose'] ? ' selected' : '' ?>><?= e($label) ?></option>
                    <?php endforeach; ?>
                </select>
                <label for="room-tempo">Tempo</label>
                <select id="room-tempo">
                    <?php foreach (array_reverse(TEMPO_OPTIONS) as $option): ?>
                        <option value="<?= $option ?>"<?= $option === (int) $room['tempo_percent'] ? ' selected' : '' ?>><?= $option ?>%</option>
                    <?php endforeach; ?>
                </select>
            </div>
            <ul class="lobby-players" id="lobby-players" aria-live="polite"></ul>
            <p class="lobby-message" id="lobby-message" role="status">Menghubungkan ke room...</p>
        </div>
    </section>

    <?php require __DIR__ . '/includes/stage.php'; ?>
<?php endif; ?>
<?php require __DIR__ . '/includes/layout_footer.php'; ?>
