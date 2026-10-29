// Teori musik dasar di sisi klien. Isinya sama dengan includes/music.php,
// supaya derajat nada dan nama akor di browser dihitung dengan aturan yang
// sama seperti di server. Semua fungsi murni (diuji di tests/js/music.test.mjs).
//
// Istilah: derajat 1 = Do ... 7 = Si; transpose = nada dasar dalam semitone
// dari C (Do = D berarti 2); MIDI = nomor nada standar (C4 = 60, A4 = 69).

export const DEGREE_LETTERS = [null, 'C', 'D', 'E', 'F', 'G', 'A', 'B'];
export const DEGREE_SEMITONES = [null, 0, 2, 4, 5, 7, 9, 11];
// Akor diatonis: I mayor, ii minor, iii minor, IV mayor, V mayor, vi minor, vii diminished.
export const DIATONIC_QUALITY = [null, '', 'm', 'm', '', '', 'm', 'dim'];
export const PITCH_NAMES = ['C', 'C#', 'D', 'Eb', 'E', 'F', 'F#', 'G', 'Ab', 'A', 'Bb', 'B'];
export const MIN_TRANSPOSE = -6;
export const MAX_TRANSPOSE = 6;

// Nama nada dasar, misalnya keyName(2) = 'D'.
export function keyName(transpose) {
    return PITCH_NAMES[((transpose % 12) + 12) % 12];
}

// 'G3' -> { letter: 'G', sharp: false, octave: 3 }. Kode drum -> null.
export function parseSoundCode(code) {
    const match = /^([A-G])(#?)(-?\d)$/.exec(code || '');
    return match ? { letter: match[1], sharp: match[2] === '#', octave: Number(match[3]) } : null;
}

// Derajat dan geseran oktaf relatif terhadap oktaf Do instrumen.
// Contoh (Do = C4): 'G3' -> { degree: 5, octaveShift: -1 }.
export function codeToDegree(code, doOctave) {
    const parsed = parseSoundCode(code);
    if (!parsed || parsed.sharp) {
        return null;
    }
    return { degree: DEGREE_LETTERS.indexOf(parsed.letter), octaveShift: parsed.octave - doOctave };
}

// Oktaf Do instrumen: bunyi berhuruf C yang dipetakan ke 1 jari, atau C terendah.
export function instrumentDoOctave(sounds, gestures) {
    const codeById = new Map(sounds.map((sound) => [sound.id, sound.code]));
    const one = gestures.find((gesture) => gesture.finger_count === 1);
    const fromOne = one ? parseSoundCode(codeById.get(one.sound_id)) : null;
    if (fromOne && fromOne.letter === 'C' && !fromOne.sharp) {
        return fromOne.octave;
    }
    const octaves = sounds
        .map((sound) => parseSoundCode(sound.code))
        .filter((parsed) => parsed && parsed.letter === 'C' && !parsed.sharp)
        .map((parsed) => parsed.octave);
    return octaves.length ? Math.min(...octaves) : null;
}

// Nomor MIDI dari kode bunyi: 'C4' -> 60, 'G3' -> 55.
export function codeToMidi(code) {
    const parsed = parseSoundCode(code);
    if (!parsed) {
        return null;
    }
    const semitone = DEGREE_SEMITONES[DEGREE_LETTERS.indexOf(parsed.letter)] + (parsed.sharp ? 1 : 0);
    return 12 * (parsed.octave + 1) + semitone;
}

// Frekuensi (Hz) dari nomor MIDI, nada standar A4 = 440 Hz.
export function midiToFrequency(midi) {
    return 440 * 2 ** ((midi - 69) / 12);
}

// Pengali frekuensi untuk transpose: naik 12 semitone = dua kali lipat.
export function transposeFactor(transpose) {
    return 2 ** (transpose / 12);
}

// Nama huruf sebuah derajat di nada dasar tertentu, misalnya derajat 3 di Do = D -> 'F#'.
export function degreeLetterName(degree, transpose) {
    return keyName(DEGREE_SEMITONES[degree] + transpose);
}

// Nama akor diatonis: chordName(2, 0) = 'Dm', chordName(5, -5) = 'D'.
export function chordName(degree, transpose) {
    return degreeLetterName(degree, transpose) + DIATONIC_QUALITY[degree];
}

// Tiga nada akor diatonis (dalam MIDI) yang disusun dari nada dasar:
// nada ke-1, ke-3, dan ke-5 tangga nada mulai dari derajat itu.
// Contoh: rootMidi 62 (D4) derajat 2 -> [62, 65, 69] = D F A (Dm).
export function diatonicTriad(rootMidi, degree) {
    const index = degree - 1;
    return [0, 2, 4].map((step) => {
        const j = index + step;
        const semitone = DEGREE_SEMITONES[(j % 7) + 1] + 12 * Math.floor(j / 7);
        return rootMidi + semitone - DEGREE_SEMITONES[degree];
    });
}
