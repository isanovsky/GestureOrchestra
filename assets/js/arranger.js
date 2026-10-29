// Halaman Arranger (arranger.php): menghubungkan tombol keyboard dan layar ke
// ArrangerEngine (logika iringan) dan AudioEngine (suara).
//
// Penjadwalan memakai pola look-ahead scheduler Web Audio: timer setiap
// SCHEDULER_MS menjadwalkan bunyi LOOKAHEAD_S ke depan memakai
// AudioContext.currentTime, jadi tempo tidak goyah walau timer JavaScript telat.
//
// Jalur suara: setiap track (drum, bass, akor) punya gain sendiri -> group -> bus
// AudioEngine (ikut gema dan volume utama). Saat berhenti seketika, group lama
// diredam lalu dilepas, jadi semua nada yang sudah terjadwal ikut diam.

import { getJson, baseUrl } from './api.js';
import { AudioEngine } from './audio-engine.js';
import { ArrangerEngine, prepareStyle } from './arranger-engine.js';
import { chordFor } from './arranger-pattern.js';
import { KeyboardInput } from './keyboard-input.js';
import { buildNoteKeyMap } from './keymap.js';
import { InstrumentView } from './instrument-view.js';
import { codeToMidi, midiToFrequency, instrumentDoOctave, keyName } from './music.js';

const SCHEDULER_MS = 25;
const LOOKAHEAD_S = 0.1;
const MIN_BPM = 40;
const MAX_BPM = 200;
const BPM_STEP = 5;
const MIN_TRANSPOSE = -5;
const MAX_TRANSPOSE = 6;
const TRACKS = ['drum', 'bass', 'chord'];

const CHORD_KEYS = ['KeyZ', 'KeyX', 'KeyC', 'KeyV', 'KeyB', 'KeyN', 'KeyM'];
const SECTION_KEYS = { KeyA: 'intro', KeyS: 'main_a', KeyD: 'main_b', KeyF: 'fill', KeyG: 'ending' };
const SECTION_LABELS = { intro: 'Intro', main_a: 'Main A', main_b: 'Main B', fill: 'Fill', ending: 'Ending' };

// Level dasar tiap suara akor (dikali velocity, dibagi akar jumlah nada).
const CHORD_LEVEL = { piano: 0.3, pad: 0.11, pluck: 0.2 };
const BASS_LEVEL = 0.45;
const DRUM_LEVEL = 0.9;

const $ = (id) => document.getElementById(id);
const data = JSON.parse($('arranger-data').textContent);

const audio = new AudioEngine({ baseUrl: baseUrl() });
let engine = null;
let style = null;
let transpose = 0;
let flipLatch = false;
let melody = null;          // { data, playMode, view }
let keyboard = null;
let group = null;           // { node, tracks: { drum, bass, chord } }
const volumes = { master: 0.8, drum: 0.75, bass: 0.75, chord: 0.75 };
const notes = new Map();    // id kejadian -> { gain, end }
let lastStatus = '';

// ----------------------------------------------------------------------
// Jalur suara
// ----------------------------------------------------------------------
function createGroup() {
    const node = audio.ctx.createGain();
    node.connect(audio.bus);
    const tracks = {};
    for (const track of TRACKS) {
        tracks[track] = audio.ctx.createGain();
        tracks[track].gain.value = volumes[track];
        tracks[track].connect(node);
    }
    return { node, tracks };
}

// Berhenti seketika: redam group lama (termasuk nada yang sudah terjadwal).
function silenceGroup() {
    const old = group;
    const now = audio.ctx.currentTime;
    old.node.gain.cancelScheduledValues(now);
    old.node.gain.setValueAtTime(old.node.gain.value, now);
    old.node.gain.setTargetAtTime(0, now, 0.04);
    setTimeout(() => old.node.disconnect(), 600);
    notes.clear();
    group = createGroup();
}

// ----------------------------------------------------------------------
// Penjadwal
// ----------------------------------------------------------------------
function schedule() {
    if (!engine) {
        return;
    }
    const now = audio.ctx.currentTime;
    for (const action of engine.tick(now, LOOKAHEAD_S)) {
        if (action.kind === 'stop') {
            silenceGroup();
            continue;
        }
        const time = Math.max(now, engine.timeAt(action.beat));
        if (action.kind === 'drum') {
            const hit = audio.ctx.createGain();
            hit.gain.value = DRUM_LEVEL * action.vel;
            hit.connect(group.tracks.drum);
            audio.playDrum(action.code, time, hit);
        } else if (action.kind === 'note') {
            playNote(action, time);
        } else if (action.kind === 'cut') {
            const note = notes.get(action.id);
            if (note) {
                note.gain.gain.setTargetAtTime(0, time, 0.015);
                notes.delete(action.id);
            }
        }
    }
    // Buang catatan nada yang sudah lama selesai.
    for (const [id, note] of notes) {
        if (note.end < now - 1) {
            notes.delete(id);
        }
    }
}

// Satu kejadian bass/akor: semua nadanya lewat satu gain supaya bisa dipotong bersama.
function playNote(action, time) {
    const seconds = action.dur * engine.secondsPerBeat();
    const gain = audio.ctx.createGain();
    gain.connect(group.tracks[action.track]);
    notes.set(action.id, { gain, end: time + seconds });
    const freqs = action.midis.map(midiToFrequency);
    if (action.track === 'bass') {
        freqs.forEach((freq) => audio.playBass(freq, time, seconds, BASS_LEVEL * action.vel, gain));
        return;
    }
    const voice = style.chord_voice;
    const level = CHORD_LEVEL[voice] * action.vel / Math.sqrt(freqs.length);
    for (const freq of freqs) {
        if (voice === 'pad') {
            audio.playPad(freq, time, seconds, level, gain);
        } else if (voice === 'pluck') {
            audio.playPluck(freq, time, level, gain);
        } else {
            audio.playPiano(freq, time, level, gain);
        }
    }
}

// ----------------------------------------------------------------------
// Style, tempo, nada dasar
// ----------------------------------------------------------------------
function selectStyle(id) {
    const raw = data.styles.find((s) => String(s.id) === String(id)) || data.styles[0];
    const previous = engine;
    if (previous && previous.playing) {
        previous.stopNow(audio.ctx.currentTime);
        silenceGroup();
    }
    style = prepareStyle(raw);
    engine = new ArrangerEngine({ style, transpose });
    engine.syncStart = $('arr-sync').checked;
    if (previous) {
        engine.applyChord(chordFor(previous.chord.degree, transpose, previous.chord.flip));
        engine.mainVariant = engine.mainName(previous.mainVariant);
    }
    renderBeats();
    document.querySelectorAll('[data-section]').forEach((btn) => {
        btn.disabled = !engine.has(btn.dataset.section) && btn.dataset.section !== 'main_b';
    });
    setBpm(style.bpm);
}

function setBpm(bpm) {
    const value = Math.min(MAX_BPM, Math.max(MIN_BPM, bpm));
    engine.setBpm(value, audio.ctx.currentTime);
    $('tempo-label').textContent = value + ' bpm';
}

function setTranspose(value) {
    transpose = Math.min(MAX_TRANSPOSE, Math.max(MIN_TRANSPOSE, value));
    $('transpose-label').textContent = 'Do = ' + keyName(transpose);
    engine.setTranspose(transpose);
    audio.setTranspose(transpose);
    if (melody) {
        melody.view.setTranspose(transpose);
    }
    document.querySelectorAll('.arr-chord-btn').forEach((btn) => {
        btn.querySelector('.chord-label').textContent = chordFor(Number(btn.dataset.degree), transpose, flipLatch).name;
    });
}

function setFlipLatch(on) {
    flipLatch = on;
    $('arr-flip').classList.toggle('is-active', on);
    $('arr-flip').setAttribute('aria-pressed', on ? 'true' : 'false');
    setTranspose(transpose);    // perbarui nama akor di tombol
}

// ----------------------------------------------------------------------
// Kendali
// ----------------------------------------------------------------------
function pressChord(degree, shift) {
    engine.setChord(degree, shift !== flipLatch, audio.ctx.currentTime);
    const btn = document.querySelector(`.arr-chord-btn[data-degree="${degree}"]`);
    flash(btn);
}

function toggleStart() {
    const now = audio.ctx.currentTime;
    if (engine.playing) {
        engine.stop(now);
    } else {
        engine.start(now);
    }
}

function flash(el) {
    if (!el) {
        return;
    }
    el.classList.remove('is-hit');
    void el.offsetWidth;
    el.classList.add('is-hit');
}

function onKeyDown(event) {
    if (!engine || event.ctrlKey || event.metaKey || event.altKey) {
        return;
    }
    if (event.target.closest && event.target.closest('input, textarea, select')) {
        return;
    }
    const degree = CHORD_KEYS.indexOf(event.code) + 1;
    if (degree > 0) {
        event.preventDefault();
        if (!event.repeat) {
            pressChord(degree, event.shiftKey);
        }
        return;
    }
    if (SECTION_KEYS[event.code]) {
        event.preventDefault();
        if (!event.repeat) {
            engine.selectSection(SECTION_KEYS[event.code], audio.ctx.currentTime);
            flash(document.querySelector(`[data-section="${SECTION_KEYS[event.code]}"]`));
        }
        return;
    }
    switch (event.code) {
        case 'Space':
            event.preventDefault();
            if (!event.repeat) {
                toggleStart();
            }
            break;
        case 'ArrowUp':
            event.preventDefault();
            setBpm(engine.bpm + BPM_STEP);
            break;
        case 'ArrowDown':
            event.preventDefault();
            setBpm(engine.bpm - BPM_STEP);
            break;
        case 'ArrowRight':
            event.preventDefault();
            setTranspose(transpose + 1);
            break;
        case 'ArrowLeft':
            event.preventDefault();
            setTranspose(transpose - 1);
            break;
        default:
    }
}

// ----------------------------------------------------------------------
// Melodi
// ----------------------------------------------------------------------
async function loadMelody(slug) {
    const instrumentData = await getJson('api/instrument.php?slug=' + encodeURIComponent(slug));
    await audio.loadSamples(instrumentData.sounds);
    audio.noteOff();
    const container = $('inst-view');
    container.className = `inst-view inst-view-${instrumentData.instrument.type} arr-inst`;
    const view = new InstrumentView({
        container,
        instrumentData,
        onPress: (sound) => playMelody(sound),
        onRelease: () => playMelody(null),
    });
    view.setInteractive(true);
    view.transpose = transpose;
    view.render();
    melody = { data: instrumentData, playMode: instrumentData.instrument.play_mode, view };
    keyboard.setNoteKeys(buildNoteKeyMap(instrumentData.sounds, instrumentDoOctave(instrumentData.sounds, instrumentData.gestures)));
}

function playMelody(sound) {
    if (!melody) {
        return;
    }
    if (sound === null) {
        audio.noteOff();
        melody.view.release();
        return;
    }
    audio.noteOn(sound, melody.playMode);
    const midi = codeToMidi(sound.code);
    if (midi !== null) {
        melody.view.release();
        melody.view.light([midi + transpose], melody.playMode === 'sustain');
    }
}

// ----------------------------------------------------------------------
// Tampilan
// ----------------------------------------------------------------------
function renderBeats() {
    const box = $('arr-beats');
    box.replaceChildren();
    for (let i = 0; i < style.beats_per_bar; i++) {
        const dot = document.createElement('span');
        dot.className = 'arr-beat';
        box.appendChild(dot);
    }
}

function frame() {
    requestAnimationFrame(frame);
    if (!engine) {
        return;
    }
    const s = engine.status(audio.ctx.currentTime);
    const key = JSON.stringify([s.playing, s.section, s.queued, s.mainVariant, s.introArmed, s.chord.name,
        s.pendingChord && s.pendingChord.name, s.beatInBar]);
    if (key === lastStatus) {
        return;
    }
    lastStatus = key;

    $('arr-chord-name').textContent = s.chord.name;
    $('arr-chord-next').textContent = s.pendingChord ? 'lalu ' + s.pendingChord.name : '';
    $('arr-section-name').textContent = s.playing ? SECTION_LABELS[s.section] : 'Berhenti';
    let next = '';
    if (s.queued) {
        next = 'lalu ' + SECTION_LABELS[s.queued];
    } else if (!s.playing) {
        next = (s.introArmed ? 'mulai dari Intro, lalu ' : 'mulai dari ') + SECTION_LABELS[s.mainVariant];
    }
    $('arr-section-next').textContent = next;
    $('arr-play-label').textContent = s.playing ? (s.section === 'ending' || s.queued === 'ending' ? 'Stop' : 'Berhenti') : 'Mulai';
    $('arr-play').classList.toggle('is-active', s.playing);

    document.querySelectorAll('[data-section]').forEach((btn) => {
        const name = btn.dataset.section;
        btn.classList.toggle('is-active', s.playing && s.section === name);
        btn.classList.toggle('is-queued', s.queued === name || (!s.playing && s.introArmed && name === 'intro'));
        btn.classList.toggle('is-selected', s.mainVariant === name);
    });
    document.querySelectorAll('.arr-chord-btn').forEach((btn) => {
        btn.classList.toggle('is-active', s.chord.degree === Number(btn.dataset.degree));
    });
    [...$('arr-beats').children].forEach((dot, i) => {
        dot.classList.toggle('is-on', s.playing && s.beatInBar === i);
        dot.classList.toggle('is-down', s.playing && s.beatInBar === i && i === 0);
    });
}

// ----------------------------------------------------------------------
// Mulai
// ----------------------------------------------------------------------
// Tombol layar tidak mengambil fokus, supaya Spasi tidak ikut "mengklik" tombol terakhir.
function bindButton(el, handler) {
    el.addEventListener('mousedown', (event) => event.preventDefault());
    el.addEventListener('click', handler);
}

$('btn-start').addEventListener('click', async () => {
    const button = $('btn-start');
    button.disabled = true;
    try {
        $('status').textContent = 'Menyalakan suara...';
        await audio.unlock();
        audio.setVolume(volumes.master);
        group = createGroup();
        keyboard = new KeyboardInput({ onGestureChange: () => {}, onSound: (sound) => playMelody(sound) });
        keyboard.setEnabled(true);

        selectStyle($('arr-style').value);
        setTranspose(0);
        $('status').textContent = 'Mengambil data instrumen melodi...';
        await loadMelody($('arr-melody').value);

        $('start-gate').hidden = true;
        $('arr-main').hidden = false;
        window.addEventListener('keydown', onKeyDown);
        setInterval(schedule, SCHEDULER_MS);
        requestAnimationFrame(frame);
    } catch (error) {
        $('status').textContent = error.message;
        button.disabled = false;
    }
});

// Pilihan diubah lewat mouse: lepas fokus supaya tombol keyboard kembali ke arranger.
$('arr-style').addEventListener('change', (event) => {
    selectStyle(event.target.value);
    event.target.blur();
});
$('arr-melody').addEventListener('change', async (event) => {
    event.target.blur();
    try {
        await loadMelody(event.target.value);
    } catch (error) {
        $('arr-section-next').textContent = error.message;
    }
});
$('arr-sync').addEventListener('change', (event) => {
    engine.syncStart = event.target.checked;
    event.target.blur();
});
document.querySelectorAll('[data-volume]').forEach((input) => {
    input.addEventListener('input', () => {
        const key = input.dataset.volume;
        volumes[key] = Number(input.value) / 100;
        if (key === 'master') {
            audio.setVolume(volumes.master);
        } else if (group) {
            audio.rampParam(group.tracks[key].gain, volumes[key]);
        }
    });
    input.addEventListener('change', () => input.blur());
});

bindButton($('tempo-down'), () => setBpm(engine.bpm - BPM_STEP));
bindButton($('tempo-up'), () => setBpm(engine.bpm + BPM_STEP));
bindButton($('transpose-down'), () => setTranspose(transpose - 1));
bindButton($('transpose-up'), () => setTranspose(transpose + 1));
bindButton($('arr-play'), toggleStart);
bindButton($('arr-flip'), () => setFlipLatch(!flipLatch));
document.querySelectorAll('[data-section]').forEach((btn) => {
    bindButton(btn, () => engine.selectSection(btn.dataset.section, audio.ctx.currentTime));
});
document.querySelectorAll('.arr-chord-btn').forEach((btn) => {
    bindButton(btn, (event) => pressChord(Number(btn.dataset.degree), event.shiftKey));
});

// Halaman ditinggal: hentikan semua bunyi.
window.addEventListener('pagehide', () => {
    if (audio.ctx) {
        audio.stopAll();
    }
});
