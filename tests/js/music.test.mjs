// Uji assets/js/music.js. Jalankan: node --test tests/js/*.test.mjs

import { test } from 'node:test';
import assert from 'node:assert/strict';
import {
    keyName, codeToDegree, instrumentDoOctave, codeToMidi, midiToFrequency,
    transposeFactor, chordName, diatonicTriad, degreeLetterName,
} from '../../assets/js/music.js';

test('nama nada dasar mengikuti transpose', () => {
    assert.deepEqual([0, 2, -5, 6, -6].map(keyName), ['C', 'D', 'G', 'F#', 'F#']);
});

test('derajat dari kode bunyi relatif terhadap Do instrumen', () => {
    assert.deepEqual(codeToDegree('G3', 4), { degree: 5, octaveShift: -1 });
    assert.deepEqual(codeToDegree('C6', 5), { degree: 1, octaveShift: 1 });
    assert.equal(codeToDegree('KICK', 4), null);
});

test('oktaf Do dari gestur 1 jari, bukan sort_order', () => {
    const sounds = [{ id: 24, code: 'G3' }, { id: 1, code: 'C4' }, { id: 8, code: 'C5' }];
    assert.equal(instrumentDoOctave(sounds, [{ finger_count: 9, sound_id: 24 }, { finger_count: 1, sound_id: 1 }]), 4);
    assert.equal(instrumentDoOctave(sounds, []), 4);
});

test('MIDI dan frekuensi', () => {
    assert.equal(codeToMidi('C4'), 60);
    assert.equal(codeToMidi('A4'), 69);
    assert.equal(Math.round(midiToFrequency(60) * 100) / 100, 261.63);
    assert.equal(transposeFactor(12), 2);
});

test('akor diatonis I sampai vii', () => {
    // Nada dasar C4..B4 = MIDI 60, 62, 64, 65, 67, 69, 71
    const roots = [60, 62, 64, 65, 67, 69, 71];
    const triads = roots.map((midi, i) => diatonicTriad(midi, i + 1));
    assert.deepEqual(triads[0], [60, 64, 67]);   // C E G
    assert.deepEqual(triads[1], [62, 65, 69]);   // D F A  (Dm)
    assert.deepEqual(triads[4], [67, 71, 74]);   // G B D
    assert.deepEqual(triads[6], [71, 74, 77]);   // B D F  (Bdim)
    assert.deepEqual([1, 2, 3, 4, 5, 6, 7].map((d) => chordName(d, 0)), ['C', 'Dm', 'Em', 'F', 'G', 'Am', 'Bdim']);
});

test('nama akor dan nada ikut transpose', () => {
    assert.equal(chordName(5, -5), 'D');      // V di Do = G
    assert.equal(chordName(2, 2), 'Em');      // ii di Do = D
    assert.equal(degreeLetterName(3, 2), 'F#');
});
