// Peta tombol keyboard laptop. Satu-satunya tempat yang menentukan tombol mana
// membunyikan nada apa; contekan, label tuts, dan keyboard-input.js membaca dari sini.
//
// Dipakai event.code (posisi fisik tombol), bukan event.key, supaya peta tetap
// sama di layout keyboard apa pun dan tidak terpengaruh Shift atau Caps Lock.
//
// Piano dan strings (bernada):
//   Q W E R T Y U          Do rendah sampai Si rendah
//   1 2 3 4 5 6 7 8        Do sampai Do tinggi
//   9 0 - = [ ] \          Re tinggi sampai Do sangat tinggi
// Nada ditentukan dari derajat (1 = Do ... 7 = Si) dan geseran oktaf terhadap
// Do instrumen, lalu dicari bunyi dengan KODE yang cocok (misalnya piano Do = C4:
// tombol T = derajat 5 oktaf -1 = G3). Urutan bunyi (sort_order) tidak dipakai.
// Tombol hanya aktif bila instrumen punya bunyi tersebut.
//
// Drum (tidak bernada): tombol 1 sampai 9 = jumlah jari, seperti sebelumnya.

import { DEGREE_LETTERS, parseSoundCode } from './music.js';

export const NOTE_KEYS = [
    { code: 'KeyQ', label: 'Q', degree: 1, octave: -1 },
    { code: 'KeyW', label: 'W', degree: 2, octave: -1 },
    { code: 'KeyE', label: 'E', degree: 3, octave: -1 },
    { code: 'KeyR', label: 'R', degree: 4, octave: -1 },
    { code: 'KeyT', label: 'T', degree: 5, octave: -1 },
    { code: 'KeyY', label: 'Y', degree: 6, octave: -1 },
    { code: 'KeyU', label: 'U', degree: 7, octave: -1 },
    { code: 'Digit1', label: '1', degree: 1, octave: 0 },
    { code: 'Digit2', label: '2', degree: 2, octave: 0 },
    { code: 'Digit3', label: '3', degree: 3, octave: 0 },
    { code: 'Digit4', label: '4', degree: 4, octave: 0 },
    { code: 'Digit5', label: '5', degree: 5, octave: 0 },
    { code: 'Digit6', label: '6', degree: 6, octave: 0 },
    { code: 'Digit7', label: '7', degree: 7, octave: 0 },
    { code: 'Digit8', label: '8', degree: 1, octave: 1 },
    { code: 'Digit9', label: '9', degree: 2, octave: 1 },
    { code: 'Digit0', label: '0', degree: 3, octave: 1 },
    { code: 'Minus', label: '-', degree: 4, octave: 1 },
    { code: 'Equal', label: '=', degree: 5, octave: 1 },
    { code: 'BracketLeft', label: '[', degree: 6, octave: 1 },
    { code: 'BracketRight', label: ']', degree: 7, octave: 1 },
    { code: 'Backslash', label: '\\', degree: 1, octave: 2 },
    // Keyboard layout ISO (Eropa) punya tombol tambahan di samping Shift kiri;
    // diterima juga sebagai Do sangat tinggi. Tidak ditampilkan di contekan.
    { code: 'IntlBackslash', label: '\\', degree: 1, octave: 2, alias: true },
];

export const DRUM_KEYS = Array.from({ length: 9 }, (_, i) => ({ code: `Digit${i + 1}`, label: String(i + 1), count: i + 1 }));

// Kode bunyi untuk sebuah tombol: derajat + oktaf relatif terhadap Do instrumen.
export function codeForKey(key, doOctave) {
    return DEGREE_LETTERS[key.degree] + (doOctave + key.octave);
}

// Peta event.code -> bunyi untuk instrumen bernada. Tombol tanpa bunyi tidak dimasukkan.
export function buildNoteKeyMap(sounds, doOctave) {
    const map = new Map();
    if (doOctave === null) {
        return map;
    }
    const byCode = new Map(sounds.map((sound) => [sound.code, sound]));
    for (const key of NOTE_KEYS) {
        const sound = byCode.get(codeForKey(key, doOctave));
        if (sound) {
            map.set(key.code, sound);
        }
    }
    return map;
}

// Label tombol untuk sebuah bunyi (misalnya 'T' untuk Sol rendah), atau null.
export function keyLabelForSound(sound, doOctave) {
    if (doOctave === null || !sound || !parseSoundCode(sound.code)) {
        return null;
    }
    const key = NOTE_KEYS.find((k) => !k.alias && codeForKey(k, doOctave) === sound.code);
    return key ? key.label : null;
}
