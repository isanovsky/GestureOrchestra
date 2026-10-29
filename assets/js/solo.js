// Halaman mode solo (play.php). Panggung disiapkan oleh stage-setup.js;
// file ini mengurus lagu: mulai, kirim hasil ke server, dan simpan nilai.

import { baseUrl, postJson } from './api.js';
import { Stage, praise, mascotFor } from './stage-setup.js';

const $ = (id) => document.getElementById(id);
const root = $('stage');
const songId = Number(root.dataset.songId);
const partStyle = root.dataset.partStyle;
const tempoPercent = Number(root.dataset.tempo);
let lastEvents = [];      // event lagu terakhir, dikirim ulang saat menyimpan

const stage = new Stage({
    root,
    partStyle,
    tempoPercent,
    transpose: Number(root.dataset.defaultTranspose),
    onFinish: finishSong,
});

// Langkah 1: tombol "Siapkan instrumen".
$('btn-start').addEventListener('click', async () => {
    const button = $('btn-start');
    button.disabled = true;
    try {
        await stage.prepare((text) => {
            $('status').textContent = text;
        });
        stage.goLive();
    } catch (error) {
        $('status').textContent = error.message;
        button.disabled = false;
    }
});

// ------------------------------------------------------------------
// Lagu: mulai, selesai, nilai, simpan
// ------------------------------------------------------------------
if (songId > 0) {
    $('btn-play-song').addEventListener('click', () => {
        $('btn-play-song').hidden = true;
        $('result').hidden = true;
        stage.lockSettings(true);
        stage.songStarted();
        // Hitung mundur satu birama (berbunyi klik) ditambah satu detik, lalu lagu mulai.
        const song = stage.songData.song;
        const beatMs = 60000 / song.bpm;
        stage.player.startAt(performance.now() + song.beats_per_bar * beatMs + 1000);
    });

    // Main lagi dan tombol tutup (x / Esc) sama-sama menutup hasil dan
    // memunculkan lagi tombol Mulai lagu, jadi pemain tidak terjebak.
    const closeResult = () => {
        $('result').hidden = true;
        $('btn-play-song').hidden = false;
        $('hint').textContent = 'Tekan Mulai lagu kalau sudah siap.';
    };
    $('btn-again').addEventListener('click', closeResult);
    $('result-close').addEventListener('click', closeResult);
    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape' && !$('result').hidden) {
            closeResult();
        }
    });

    $('save-form').addEventListener('submit', async (event) => {
        event.preventDefault();
        const nickname = $('nickname').value.trim();
        const button = event.submitter;
        button.disabled = true;
        try {
            const result = await postJson('api/submit_performance.php', buildPayload(nickname));
            $('save-message').textContent = `Tersimpan! ${result.nickname} ada di peringkat ${result.rank}.`;
            $('save-form').hidden = true;
        } catch (error) {
            $('save-message').textContent = error.message;
            button.disabled = false;
        }
    });
}

function buildPayload(nickname) {
    return {
        mode: 'solo',
        song_id: songId,
        instrument_id: stage.instrumentData.instrument.id,
        part_style: partStyle,
        tempo_percent: tempoPercent,
        nickname,
        events: lastEvents,
    };
}

// Dipanggil saat lagu selesai: kirim event ke server untuk dinilai.
async function finishSong(events) {
    lastEvents = events;
    stage.lockSettings(false);
    stage.songEnded();
    $('result').hidden = false;
    $('save-form').hidden = true;
    $('save-message').textContent = '';
    $('result-accuracy').textContent = '...';
    $('result-detail').textContent = 'Menghitung nilai di server...';

    try {
        const result = await postJson('api/submit_performance.php', buildPayload(''));
        const unit = partStyle === 'chords' ? 'akor' : 'nada';
        $('result-accuracy').textContent = String(result.accuracy).replace('.', ',');
        $('result-detail').textContent = `${result.hits} dari ${result.total_notes} ${unit} kena. ${praise(result.accuracy)}`;
        $('result-mascot').src = baseUrl() + '/assets/img/' + mascotFor(result.accuracy);
        $('save-form').hidden = false;
        $('save-form').querySelector('button').disabled = false;
    } catch (error) {
        $('result-accuracy').textContent = '-';
        $('result-detail').textContent = error.message;
    }
    $('btn-play-song').hidden = true;
}
