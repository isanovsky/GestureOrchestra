// Uji peta tombol keyboard (assets/js/keymap.js). Jalankan: node --test tests/js/

import { test } from 'node:test';
import assert from 'node:assert/strict';
import { NOTE_KEYS, buildNoteKeyMap, keyLabelForSound } from '../../assets/js/keymap.js';

const LETTERS = ['C', 'D', 'E', 'F', 'G', 'A', 'B'];
// Bunyi C(oktaf) sampai C(oktaf + 3), seperti migrasi 005. id sengaja acak
// (bukan urutan nada) untuk memastikan peta tidak memakai urutan bunyi.
function soundsFrom(startOctave) {
    const sounds = [];
    for (let octave = startOctave; octave < startOctave + 3; octave++) {
        LETTERS.forEach((letter) => sounds.push({ code: letter + octave }));
    }
    sounds.push({ code: 'C' + (startOctave + 3) });
    return sounds.map((sound, i) => ({ ...sound, id: 1000 - i * 7 })).reverse();
}

const codeOf = (map, keyCode) => (map.get(keyCode) || {}).code;

test('piano (Do = C4): Q = C3, T = G3, 1 = C4, 8 = C5, 9 = D5, \\ = C6', () => {
    const map = buildNoteKeyMap(soundsFrom(3), 4);
    assert.equal(codeOf(map, 'KeyQ'), 'C3');
    assert.equal(codeOf(map, 'KeyT'), 'G3');
    assert.equal(codeOf(map, 'KeyU'), 'B3');
    assert.equal(codeOf(map, 'Digit1'), 'C4');
    assert.equal(codeOf(map, 'Digit8'), 'C5');
    assert.equal(codeOf(map, 'Digit9'), 'D5');
    assert.equal(codeOf(map, 'Digit0'), 'E5');
    assert.equal(codeOf(map, 'Minus'), 'F5');
    assert.equal(codeOf(map, 'Equal'), 'G5');
    assert.equal(codeOf(map, 'BracketLeft'), 'A5');
    assert.equal(codeOf(map, 'BracketRight'), 'B5');
    assert.equal(codeOf(map, 'Backslash'), 'C6');
    assert.equal(codeOf(map, 'IntlBackslash'), 'C6', 'keyboard ISO');
    assert.equal(map.size, NOTE_KEYS.length);
});

test('strings (Do = C5): Q = C4, \\ = C7', () => {
    const map = buildNoteKeyMap(soundsFrom(4), 5);
    assert.equal(codeOf(map, 'KeyQ'), 'C4');
    assert.equal(codeOf(map, 'Digit1'), 'C5');
    assert.equal(codeOf(map, 'Backslash'), 'C7');
});

test('tombol tanpa bunyi tidak aktif (instrumen sebelum migrasi 005)', () => {
    const old = ['G3', 'C4', 'D4', 'E4', 'F4', 'G4', 'A4', 'B4', 'C5'].map((code, id) => ({ id, code }));
    const map = buildNoteKeyMap(old, 4);
    assert.equal(codeOf(map, 'KeyT'), 'G3');
    assert.equal(map.has('KeyQ'), false);
    assert.equal(map.has('Digit9'), false);
    assert.equal(map.size, 9);
});

test('label tombol untuk sebuah bunyi', () => {
    assert.equal(keyLabelForSound({ code: 'G3' }, 4), 'T');
    assert.equal(keyLabelForSound({ code: 'D5' }, 4), '9');
    assert.equal(keyLabelForSound({ code: 'C6' }, 4), '\\');
    assert.equal(keyLabelForSound({ code: 'KICK' }, 4), null);
    assert.equal(keyLabelForSound({ code: 'C4' }, null), null);
});
