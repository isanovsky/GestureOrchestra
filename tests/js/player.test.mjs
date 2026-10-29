// Uji GamePlayer (assets/js/player.js) dengan mesin audio palsu yang mencatat
// setiap panggilan. Jalankan: node --test tests/js/*.test.mjs

import { test } from 'node:test';
import assert from 'node:assert/strict';
import { GamePlayer } from '../../assets/js/player.js';

globalThis.requestAnimationFrame ??= (fn) => setTimeout(fn, 16);
const wait = (ms) => new Promise((resolve) => setTimeout(resolve, ms));
const round = (list) => list.map((f) => Math.round(f * 100) / 100);

function fakeAudio() {
    const calls = { noteOn: [], chordOn: [], strike: 0, noteOff: 0, hihat: 0, click: [] };
    return {
        calls,
        noteOn: (sound) => calls.noteOn.push(sound.code),
        chordOn: (freqs, mode, bass) => calls.chordOn.push({ freqs: round(freqs), mode, bass: round(bass) }),
        strikeChord: () => { calls.strike++; },
        noteOff: () => { calls.noteOff++; },
        hihat: () => { calls.hihat++; },
        click: (time, accent) => calls.click.push(accent),
        setTranspose: () => {},
        audioTimeAt: (perfMs) => perfMs / 1000,
    };
}

// Data seperti api/instrument.php setelah migrasi 003.
const codes = ['G3', 'C4', 'D4', 'E4', 'F4', 'G4', 'A4', 'B4', 'C5'];
const fingers = [9, 1, 2, 3, 4, 5, 6, 7, 8];
function instrument(type, playMode, octaveShift = 0) {
    const sounds = codes.map((code, i) => ({
        id: i + 1,
        name: code,
        code: code[0] + (Number(code[1]) + octaveShift),
        frequency: null,
        sample_path: null,
    }));
    return {
        instrument: { id: 1, type, play_mode: playMode, color: '#E4572E' },
        sounds,
        gestures: sounds.map((sound, i) => ({ finger_count: fingers[i], sound_id: sound.id })),
    };
}
const drumData = {
    instrument: { id: 3, type: 'drum', play_mode: 'hit', color: '#E9A23B' },
    sounds: [{ id: 17, name: 'Kick', code: 'KICK', frequency: null, sample_path: null }],
    gestures: [{ finger_count: 1, sound_id: 17 }],
};

test('piano akor: 4 jari = akor F (F A C) dengan bass F satu oktaf di bawah', () => {
    const audio = fakeAudio();
    const player = new GamePlayer({ instrumentData: instrument('piano', 'decay'), song: null, notes: [], style: 'chords', audio, els: {} });
    player.handleGesture(4, performance.now());
    player.destroy();
    assert.deepEqual(audio.calls.chordOn[0], { freqs: [349.23, 440, 523.25], mode: 'decay', bass: [174.61] });
});

test('piano akor: bass dua oktaf dan bass mati', () => {
    const audio = fakeAudio();
    const player = new GamePlayer({ instrumentData: instrument('piano', 'decay'), song: null, notes: [], style: 'chords', audio, els: {} });
    player.setOption('bass', 2);
    player.handleGesture(1, performance.now());
    player.setOption('bass', 0);
    player.handleGesture(7, performance.now());
    player.destroy();
    assert.deepEqual(audio.calls.chordOn[0].bass, [65.41]);                       // C2
    assert.deepEqual(audio.calls.chordOn[1], { freqs: [493.88, 587.33, 698.46], mode: 'decay', bass: [] });   // Bdim
});

test('strings akor: pad tiga nada di oktaf strings, tanpa bass', () => {
    const audio = fakeAudio();
    const player = new GamePlayer({ instrumentData: instrument('synth', 'sustain', 1), song: null, notes: [], style: 'chords', audio, els: {} });
    player.handleGesture(6, performance.now());   // A5 -> Am
    player.destroy();
    assert.deepEqual(audio.calls.chordOn[0], { freqs: [880, 1046.5, 1318.51], mode: 'sustain', bass: [] });
});

test('label akor dan nada mengikuti transpose', () => {
    const data = instrument('piano', 'decay');
    const player = new GamePlayer({ instrumentData: data, song: null, notes: [], style: 'chords', audio: fakeAudio(), els: {} });
    player.setTranspose(2);   // Do = D
    const labels = [2, 5, 9].map((f) => player.soundLabel(data.sounds[fingers.indexOf(f)]));
    player.setStyle('notes');
    const melody = player.soundLabel(data.sounds[fingers.indexOf(3)]);
    player.destroy();
    assert.deepEqual(labels, ['Em', 'A', 'A']);
    assert.equal(melody, 'E4 (F#)');
});

test('ritme "hanya saat ganti akor": tidak ada pukulan ulang', async () => {
    const audio = fakeAudio();
    const player = new GamePlayer({ instrumentData: instrument('piano', 'decay'), song: null, notes: [], style: 'chords', freeBpm: 240, audio, els: {} });
    player.handleGesture(1, performance.now());
    await wait(800);
    player.destroy();
    assert.equal(audio.calls.strike, 0);
});

test('ritme "setiap ketukan": akor dipukul ulang selama ditahan, berhenti saat dilepas', async () => {
    const audio = fakeAudio();
    // 240 bpm = 250 ms per ketukan, jadi 800 ms = sekitar 3 ketukan.
    const player = new GamePlayer({ instrumentData: instrument('piano', 'decay'), song: null, notes: [], style: 'chords', freeBpm: 240, audio, els: {} });
    player.setOption('rhythm', 'beat');
    player.handleGesture(1, performance.now());
    await wait(800);
    const whileHeld = audio.calls.strike;
    player.handleGesture(0, performance.now());
    await wait(500);
    player.destroy();
    assert.ok(whileHeld >= 2 && whileHeld <= 4, 'pukulan ulang: ' + whileHeld);
    assert.ok(audio.calls.strike - whileHeld <= 1, 'tidak ada pukulan setelah dilepas');
});

test('lagu: hitungan masuk satu birama berbunyi 4 klik, klik pertama beraksen', async () => {
    const audio = fakeAudio();
    const song = { bpm: 240, beats_per_bar: 4, length_beats: 2 };
    const player = new GamePlayer({ instrumentData: instrument('piano', 'decay'), song, notes: [], style: 'notes', audio, els: {} });
    player.startAt(performance.now() + 4 * 250 + 100);
    player.destroy();
    assert.deepEqual(audio.calls.click, [true, false, false, false]);
});

test('drum: hi-hat otomatis tiap setengah ketuk selama lagu', async () => {
    const audio = fakeAudio();
    // 240 bpm, lagu 4 ketukan = 1 detik = 8 hi-hat.
    const song = { bpm: 240, beats_per_bar: 4, length_beats: 4 };
    const player = new GamePlayer({ instrumentData: drumData, song, notes: [], style: 'notes', audio, els: {} });
    player.startAt(performance.now() + 50);
    await wait(1300);
    player.destroy();
    assert.ok(audio.calls.hihat >= 7 && audio.calls.hihat <= 8, 'hi-hat: ' + audio.calls.hihat);
});

test('event: lepas dicatat sekali (sound_id null), pukulan ulang tidak dicatat', async () => {
    const audio = fakeAudio();
    const song = { bpm: 240, beats_per_bar: 4, length_beats: 8 };
    const player = new GamePlayer({ instrumentData: instrument('piano', 'decay'), song, notes: [], style: 'chords', audio, els: {} });
    player.setOption('rhythm', 'beat');
    player.startAt(performance.now());
    await wait(30);
    player.handleGesture(1, performance.now());
    await wait(600);
    player.handleGesture(0, performance.now());
    player.handleGesture(0, performance.now());
    player.handleGesture(4, performance.now());
    player.destroy();
    assert.deepEqual(player.events.map((e) => e.sound_id), [2, null, 5]);
    assert.ok(audio.calls.strike >= 1);
});
