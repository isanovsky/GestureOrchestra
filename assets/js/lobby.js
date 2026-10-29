// Lobby dan permainan bersama (room.php?code=...&player=...).
//
// Alur:
// 1. Lobby: polling api/room_status.php setiap 2 detik (daftar pemain, siap,
//    terhubung, pengaturan host). Setiap polling juga menandai "saya masih ada".
// 2. Pemain menekan "Siapkan instrumen" -> suara dan kamera menyala -> otomatis siap.
// 3. Host menekan "Mulai bersama" -> server mengisi start_at_ms (waktu server + 5 detik).
// 4. Begitu start_at_ms terlihat, polling BERHENTI. Jam disinkronkan dengan
//    server, lalu lagu mulai tepat pada start_at_ms menurut jam server.
// 5. Lagu selesai -> event dikirim SEKALI -> nilai semua pemain diambil setiap
//    2 detik sampai lengkap atau batas waktu.

import { baseUrl, postJson } from './api.js';
import { Stage, praise, mascotFor } from './stage-setup.js';
import { syncClock, serverToPerformance } from './clock-sync.js';

const $ = (id) => document.getElementById(id);
const POLL_MS = 2000;
const RESULT_POLL_LIMIT = 40;   // paling lama sekitar 80 detik menunggu hasil

const root = $('stage');
const code = root.dataset.roomCode;
const playerId = Number(root.dataset.playerId);
const session = JSON.parse(sessionStorage.getItem('go_room_' + code) || 'null');

let clockOffset = 0;
let pollTimer = null;
let started = false;
let prepared = false;
let lastRoom = null;

const stage = new Stage({
    root,
    partStyle: root.dataset.partStyle,
    tempoPercent: Number(root.dataset.tempo),
    transpose: Number(root.dataset.defaultTranspose),
    transposeLocked: true,
    onFinish: finishSong,
});

const isHost = () => session && session.hostToken !== null;

function setMessage(text) {
    $('lobby-message').textContent = text;
}

// Data yang dikirim di setiap request room.
function auth(extra = {}) {
    return { code, token: session.token, ...extra };
}

// ------------------------------------------------------------------
// Mulai halaman
// ------------------------------------------------------------------
if (!session || session.playerId !== playerId) {
    // Token ada di tab tempat pemain bergabung. Tab lain tidak bisa mengaku sebagai pemain ini.
    setMessage('Sesi pemain ini tidak ada di tab ini. Gabung ulang dari halaman Main bersama.');
    $('start-gate').hidden = true;
} else {
    if (isHost()) {
        $('host-settings').hidden = false;
    }
    // Setelah halaman dimuat ulang, instrumen harus disiapkan lagi, jadi status siap direset.
    postJson('api/room_ready.php', auth({ ready: false })).catch(() => {});
    syncClock().then((sync) => {
        clockOffset = sync.offset;
    }).catch(() => {});
    poll();
}

// ------------------------------------------------------------------
// Polling lobby
// ------------------------------------------------------------------
async function poll() {
    if (started) {
        return;
    }
    try {
        const data = await postJson('api/room_status.php', auth());
        applyRoom(data.room);
    } catch (error) {
        if (/tidak ditemukan|kedaluwarsa|bukan pemain/i.test(error.message)) {
            setMessage(error.message + ' Kembali ke halaman Main bersama untuk membuat atau bergabung ke room.');
            $('start-gate').hidden = true;
            return;   // room ditutup: berhenti polling
        }
        setMessage('Koneksi tersendat, mencoba lagi...');
    }
    if (!started) {
        pollTimer = setTimeout(poll, POLL_MS);
    }
}

function stopPolling() {
    clearTimeout(pollTimer);
    pollTimer = null;
}

function applyRoom(room) {
    lastRoom = room;
    renderPlayers(room.players);
    renderStrip(room.players);

    // Pengaturan host berlaku untuk semua pemain.
    $('lobby-key').textContent = 'Do = ' + room.key_name;
    $('lobby-tempo').textContent = room.tempo_percent;
    if (prepared) {
        if (stage.transpose !== room.transpose) {
            stage.applyTranspose(room.transpose);
        }
        if (stage.tempoPercent !== room.tempo_percent) {
            stage.setTempo(room.tempo_percent);
        }
    } else {
        stage.transpose = room.transpose;
        stage.tempoPercent = room.tempo_percent;
    }

    if (room.start_at_ms !== null) {
        beginSong(room);
        return;
    }

    const others = room.players.filter((p) => !p.is_me && p.connected);
    const waitingFor = room.players.filter((p) => p.connected && !p.is_ready).map((p) => p.nickname);
    if (!prepared) {
        setMessage('Tekan "Siapkan instrumen" di bawah. Setelah itu kamu otomatis siap.');
    } else if (isHost()) {
        const canStart = waitingFor.length === 0;
        $('btn-play-song').hidden = false;
        $('btn-play-song').disabled = !canStart;
        const text = others.length === 0
            ? 'Belum ada teman yang bergabung. Kamu tetap bisa mulai sendirian.'
            : (canStart ? 'Semua siap. Tekan "Mulai bersama".' : 'Menunggu: ' + waitingFor.join(', ') + '.');
        setMessage(text);
        $('hint').textContent = text;
    } else {
        setMessage('Kamu sudah siap. Menunggu host memulai...');
        $('hint').textContent = 'Menunggu host memulai...';
    }
}

// Strip tipis di baris kontrol panggung: warna instrumen, nama, status.
// Saat lagu berjalan polling berhenti, jadi strip menampilkan status terakhir.
function renderStrip(players) {
    const strip = $('room-strip');
    strip.replaceChildren();
    for (const player of players) {
        const item = document.createElement('li');
        item.className = 'room-strip-item' + (player.connected ? '' : ' is-away') + (player.is_me ? ' is-me' : '');
        item.style.setProperty('--inst-color', player.instrument.color);
        const status = !player.connected ? 'terputus' : (player.is_ready ? 'siap' : 'belum siap');
        item.title = `${player.nickname}, ${player.instrument.name}${player.part_style === 'chords' ? ' akor' : ''}, ${status}`;
        const dot = document.createElement('span');
        dot.className = 'swatch';
        const name = document.createElement('span');
        name.textContent = player.nickname + (player.is_me ? ' (kamu)' : '');
        const state = document.createElement('small');
        state.textContent = status;
        item.append(dot, name, state);
        strip.appendChild(item);
    }
}

function renderPlayers(players) {
    const list = $('lobby-players');
    list.replaceChildren();
    for (const player of players) {
        const item = document.createElement('li');
        item.className = 'lobby-player' + (player.connected ? '' : ' is-away');
        item.style.setProperty('--inst-color', player.instrument.color);

        const swatch = document.createElement('span');
        swatch.className = 'swatch';
        const name = document.createElement('strong');
        name.textContent = player.nickname + (player.is_me ? ' (kamu)' : '');
        const part = document.createElement('span');
        part.className = 'muted';
        part.textContent = player.instrument.name + (player.part_style === 'chords' ? ' akor' : '');
        const badges = document.createElement('span');
        badges.className = 'lobby-badges';
        for (const [show, text, cls] of [
            [player.is_host, 'Host', 'badge'],
            [!player.connected, 'Terputus', 'badge badge-off'],
            [player.connected && player.is_ready, 'Siap', 'badge badge-on'],
            [player.connected && !player.is_ready, 'Belum siap', 'badge badge-off'],
        ]) {
            if (show) {
                const badge = document.createElement('span');
                badge.className = cls;
                badge.textContent = text;
                badges.appendChild(badge);
            }
        }
        item.append(swatch, name, part, badges);
        list.appendChild(item);
    }
}

// ------------------------------------------------------------------
// Siapkan instrumen = siap
// ------------------------------------------------------------------
$('btn-start').addEventListener('click', async () => {
    const button = $('btn-start');
    button.disabled = true;
    try {
        await stage.prepare((text) => {
            $('status').textContent = text;
        });
        prepared = true;
        stage.lockSettings(false);
        await postJson('api/room_ready.php', auth({ ready: true }));
        if (lastRoom) {
            applyRoom(lastRoom);
        }
    } catch (error) {
        $('status').textContent = error.message;
        button.disabled = false;
    }
});

// Host mengubah nada dasar atau tempo untuk semua pemain.
for (const id of ['room-transpose', 'room-tempo']) {
    $(id).addEventListener('change', async () => {
        try {
            const data = await postJson('api/room_settings.php', auth({
                host_token: session.hostToken,
                transpose: Number($('room-transpose').value),
                tempo_percent: Number($('room-tempo').value),
            }));
            applyRoom(data.room);
        } catch (error) {
            setMessage(error.message);
        }
    });
}

// Host memulai lagu untuk semua pemain.
$('btn-play-song').addEventListener('click', async () => {
    $('btn-play-song').disabled = true;
    try {
        const data = await postJson('api/room_start.php', auth({ host_token: session.hostToken }));
        applyRoom(data.room);
    } catch (error) {
        setMessage(error.message);
        $('hint').textContent = error.message;
        $('btn-play-song').disabled = false;
    }
});

$('btn-leave').addEventListener('click', async () => {
    // Setelah lagu dimulai, room tetap ada untuk pemain lain (lihat api/room_leave.php).
    const question = isHost() && !started
        ? 'Kamu host. Kalau keluar, room ditutup untuk semua pemain. Lanjut?'
        : 'Keluar dari room ini?';
    if (!confirm(question)) {
        return;
    }
    try {
        await postJson('api/room_leave.php', auth());
    } catch (error) {
        // Room mungkin sudah hilang; tetap keluar.
    }
    sessionStorage.removeItem('go_room_' + code);
    location.href = baseUrl() + '/room.php';
});

// ------------------------------------------------------------------
// Mulai bersama pada waktu server
// ------------------------------------------------------------------
async function beginSong(room) {
    if (started) {
        return;
    }
    started = true;
    stopPolling();

    if (!prepared) {
        setMessage('Lagu sudah dimulai tanpa instrumenmu. Siapkan instrumen lebih cepat di lagu berikutnya.');
        return;
    }

    $('btn-play-song').hidden = true;
    stage.lockSettings(true);
    stage.songStarted();
    setMessage('Lagu dimulai!');

    // Sinkronkan ulang jam selama hitung mundur 5 detik supaya lebih tepat.
    try {
        clockOffset = (await syncClock(3)).offset;
    } catch (error) {
        // Pakai offset dari awal halaman.
    }
    const startPerf = serverToPerformance(room.start_at_ms, clockOffset);
    stage.goLive();
    stage.player.startAt(startPerf);
}

// ------------------------------------------------------------------
// Selesai: kirim sekali, lalu tunggu nilai semua pemain
// ------------------------------------------------------------------
// Tombol tutup (x / Esc) hanya menyembunyikan hasil; nilai tetap tersimpan di server.
function closeResult() {
    const result = $('result');
    if (result) {
        result.hidden = true;
    }
}
if ($('result-close')) {
    $('result-close').addEventListener('click', closeResult);
    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape') {
            closeResult();
        }
    });
}

async function finishSong(events) {
    stage.songEnded();
    $('result').hidden = false;
    $('result-accuracy').textContent = '...';
    $('result-detail').textContent = 'Mengirim permainanmu ke server...';

    try {
        const mine = await postJson('api/submit_performance.php', auth({ mode: 'room', events }));
        const unit = root.dataset.partStyle === 'chords' ? 'akor' : 'nada';
        $('result-accuracy').textContent = String(mine.accuracy).replace('.', ',');
        $('result-detail').textContent = `${mine.hits} dari ${mine.total_notes} ${unit} kena. ${praise(mine.accuracy)}`;
        $('result-mascot').src = baseUrl() + '/assets/img/' + mascotFor(mine.accuracy);
    } catch (error) {
        $('result-accuracy').textContent = '-';
        $('result-detail').textContent = error.message;
    }
    pollResults(0);
}

async function pollResults(attempt) {
    try {
        const data = await postJson('api/room_results.php', auth());
        renderResults(data.results, data.done);
        if (data.done) {
            $('room-results-status').textContent = 'Semua nilai sudah masuk.';
            $('hint').textContent = 'Nilai semua pemain ada di kartu hasil.';
            return;
        }
        $('room-results-status').textContent = 'Menunggu nilai pemain lain...';
    } catch (error) {
        $('room-results-status').textContent = error.message;
    }
    if (attempt < RESULT_POLL_LIMIT) {
        setTimeout(() => pollResults(attempt + 1), POLL_MS);
    }
}

function renderResults(results, done) {
    const list = $('room-results');
    list.replaceChildren();
    for (const row of results) {
        const item = document.createElement('li');
        item.className = row.is_me ? 'is-me' : '';
        item.style.setProperty('--inst-color', row.color);
        const name = document.createElement('span');
        name.textContent = `${row.nickname} (${row.instrument}${row.part_style === 'chords' ? ' akor' : ''})`;
        const score = document.createElement('strong');
        if (row.submitted) {
            score.textContent = String(row.accuracy).replace('.', ',') + '%';
        } else {
            // Setelah batas waktu, pemain yang tidak mengirim (tab ditutup, koneksi putus) tidak ditunggu lagi.
            score.textContent = done ? 'tidak mengirim' : 'menunggu...';
        }
        item.append(name, score);
        list.appendChild(item);
    }
}
