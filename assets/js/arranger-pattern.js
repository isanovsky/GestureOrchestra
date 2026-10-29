// Mode Arranger: pembaca pola dan teori akor. Semua fungsi murni (diuji di
// tests/js/arranger.test.mjs).
//
// Aturan pola sama persis dengan includes/arranger.php (validasi saat admin
// menyimpan). tests/fixtures/arranger-patterns.json dijalankan di kedua sisi.
//
// Format: satu kejadian per baris, # sampai akhir baris = komentar.
//   drum : <ketukan> <KODE> [velocity]
//   bass : <ketukan> <nada> [durasi] [velocity]
//   akor : <ketukan> <nada> [durasi] [velocity]
// Nada: gabungan R (dasar), 3 (terts), 5 (kuint), 8 (oktaf), boleh diakhiri '-'.

import { DEGREE_SEMITONES, DIATONIC_QUALITY, keyName } from './music.js';

export const DRUM_CODES = ['KICK', 'SNARE', 'HIHAT', 'OHAT', 'RIM', 'TOM', 'CRASH', 'CYMBAL', 'KDUT', 'KTAK', 'TAMB'];
export const MAX_EVENTS = 128;
export const MAX_DURATION = 32;

// "2", "1.5", "4/3" -> angka; selain itu null.
export function parseNumber(token) {
    if (/^\d{1,3}(\.\d{1,4})?$/.test(token)) {
        return Number(token);
    }
    const match = /^(\d{1,3})\/(\d{1,2})$/.exec(token);
    if (match && Number(match[2]) > 0) {
        return Number(match[1]) / Number(match[2]);
    }
    return null;
}

function parseVelocity(token, lineNo, errors) {
    if (token === undefined) {
        return 1;
    }
    if (!/^(0(\.\d{1,3})?|1(\.0{1,3})?)$/.test(token) || Number(token) <= 0) {
        errors.push(`Baris ${lineNo}: velocity "${token}" harus lebih dari 0 sampai 1 (contoh: 0.6).`);
        return null;
    }
    return Number(token);
}

// Hasil: { events, errors }. Kejadian diurutkan menurut ketukan.
export function parsePattern(text, track, lengthBeats) {
    const events = [];
    const errors = [];
    const lines = String(text || '').split(/\r\n|\r|\n/);

    lines.forEach((raw, index) => {
        const lineNo = index + 1;
        const line = raw.replace(/#.*$/, '').trim();
        if (line === '') {
            return;
        }
        const parts = line.split(/\s+/);

        const beat = parseNumber(parts[0]);
        if (beat === null) {
            errors.push(`Baris ${lineNo}: ketukan "${parts[0]}" bukan angka (contoh: 0, 1.5, 4/3).`);
            return;
        }
        if (beat >= lengthBeats) {
            errors.push(`Baris ${lineNo}: ketukan ${parts[0]} di luar bagian (panjang ${lengthBeats} ketukan, jadi 0 sampai di bawah ${lengthBeats}).`);
            return;
        }
        if (parts[1] === undefined) {
            errors.push(`Baris ${lineNo}: isi kejadian belum ditulis.`);
            return;
        }

        if (track === 'drum') {
            if (parts.length > 3) {
                errors.push(`Baris ${lineNo}: drum ditulis <ketukan> <KODE> [velocity].`);
                return;
            }
            const code = parts[1].toUpperCase();
            if (!DRUM_CODES.includes(code)) {
                errors.push(`Baris ${lineNo}: kode drum "${parts[1]}" tidak dikenal. Pilihan: ${DRUM_CODES.join(', ')}.`);
                return;
            }
            const vel = parseVelocity(parts[2], lineNo, errors);
            if (vel !== null) {
                events.push({ beat, code, vel });
            }
            return;
        }

        if (parts.length > 4) {
            errors.push(`Baris ${lineNo}: ditulis <ketukan> <nada> [durasi] [velocity].`);
            return;
        }
        const pattern = track === 'bass' ? /^([R358])(-?)$/ : /^([R358]{1,4})(-?)$/;
        const match = pattern.exec(parts[1].toUpperCase());
        if (!match || new Set(match[1]).size !== match[1].length) {
            errors.push(track === 'bass'
                ? `Baris ${lineNo}: nada bass "${parts[1]}" harus satu dari R, 3, 5, 8 (boleh diakhiri -).`
                : `Baris ${lineNo}: nada akor "${parts[1]}" harus gabungan R, 3, 5, 8 tanpa pengulangan (boleh diakhiri -).`);
            return;
        }
        let dur = 1;
        if (parts[2] !== undefined) {
            dur = parseNumber(parts[2]);
            if (dur === null || dur <= 0 || dur > MAX_DURATION) {
                errors.push(`Baris ${lineNo}: durasi "${parts[2]}" harus angka lebih dari 0 sampai ${MAX_DURATION}.`);
                return;
            }
        }
        const vel = parseVelocity(parts[3], lineNo, errors);
        if (vel !== null) {
            events.push({ beat, tones: match[1], low: match[2] === '-', dur, vel });
        }
    });

    if (events.length > MAX_EVENTS) {
        errors.push(`Terlalu banyak kejadian (${events.length}), maksimal ${MAX_EVENTS} per pola.`);
    }
    events.sort((a, b) => a.beat - b.beat);
    return { events, errors };
}

// ----------------------------------------------------------------------
// Akor
// ----------------------------------------------------------------------
const INTERVALS = { '': [0, 4, 7], m: [0, 3, 7], dim: [0, 3, 6] };
// Shift membalik kualitas: mayor <-> minor, diminished -> minor (Bdim -> Bm).
const FLIPPED = { '': 'm', m: '', dim: 'm' };

// Akor derajat 1..7 di nada dasar transpose. flip = Shift ditahan.
// Hasil: { degree, root (kelas nada 0..11), quality, name, intervals }.
export function chordFor(degree, transpose, flip = false) {
    const diatonic = DIATONIC_QUALITY[degree];
    const quality = flip ? FLIPPED[diatonic] : diatonic;
    const root = (((DEGREE_SEMITONES[degree] + transpose) % 12) + 12) % 12;
    return { degree, flip, root, quality, name: keyName(root) + quality, intervals: INTERVALS[quality] };
}

// Susunan akor (MIDI) di sekitar C4: tiap nada akor dilipat ke jendela satu
// oktaf [low, low + 12). low dipilih supaya rata-rata nada paling dekat dengan
// susunan sebelumnya (prevCenter), jadi perpindahan akor terdengar halus
// (pembalikan otomatis). Hasil: { R, 3, 5, center } dalam MIDI.
export const VOICING_LOW_MIN = 52;   // E3
export const VOICING_LOW_MAX = 60;   // C4
export function voiceChord(chord, prevCenter = 62) {
    let best = null;
    for (let low = VOICING_LOW_MIN; low <= VOICING_LOW_MAX; low++) {
        const notes = chord.intervals.map((interval) => {
            const pc = (chord.root + interval) % 12;
            return low + ((pc - low) % 12 + 12) % 12;
        });
        const center = notes.reduce((sum, n) => sum + n, 0) / notes.length;
        const distance = Math.abs(center - prevCenter);
        if (best === null || distance < best.distance - 1e-9) {
            best = { distance, R: notes[0], 3: notes[1], 5: notes[2], center };
        }
    }
    return { R: best.R, 3: best[3], 5: best[5], center: best.center };
}

// Bass: nada dasar di oktaf C2..B2 (MIDI 36..47), terts/kuint di atasnya.
export function bassVoicing(chord) {
    const R = 36 + chord.root;
    return { R, 3: R + chord.intervals[1], 5: R + chord.intervals[2] };
}

// Nada MIDI untuk satu kejadian bass/akor. 8 = dasar + 12; low = semua turun 12.
export function eventMidis(event, voicing) {
    const shift = event.low ? -12 : 0;
    return [...event.tones].map((tone) => (tone === '8' ? voicing.R + 12 : voicing[tone]) + shift);
}
