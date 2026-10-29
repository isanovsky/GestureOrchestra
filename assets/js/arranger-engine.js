// Mode Arranger: logika iringan tanpa suara (murni, diuji di tests/js/arranger.test.mjs).
//
// Konsep bagian (Intro, Main A/B, Fill, Ending) dan "iringan mengikuti akor"
// terinspirasi dari keyboard arranger dan dari Cadenza karya Piskocis (GPL-3).
// Tidak ada kode yang diambil dari Cadenza. Lihat THIRD_PARTY_NOTICES.md.
//
// Cara pakai (lihat arranger.js):
//   const engine = new ArrangerEngine({ style });
//   setiap 25 ms: for (const action of engine.tick(audioNow, 0.1)) { ...jadwalkan bunyi... }
// tick() mengembalikan kejadian untuk jendela waktu [terakhir dijadwalkan, now + lookahead).
// Waktu dalam detik jam audio (AudioContext.currentTime); posisi dalam ketukan.
//
// Aturan perpindahan, seperti arranger sungguhan:
//   - Akor baru berlaku di seperdelapan ketukan berikutnya (CHORD_QUANTUM).
//     Nada panjang yang masih berbunyi dipotong dan dibunyikan ulang dengan akor baru.
//   - Bagian baru (Main A/B, Fill, Ending) berlaku di awal birama berikutnya.
//   - Intro selesai -> Main yang dipilih. Fill selesai -> kembali ke Main.
//   - Ending selesai -> iringan berhenti. Tanpa Ending: berhenti di birama berikutnya.
//
// Kejadian yang dikembalikan tick():
//   { kind: 'drum', beat, code, vel }
//   { kind: 'note', id, track: 'bass'|'chord', beat, dur, vel, midis }
//   { kind: 'cut', id, beat }       potong nada id (dipotong karena akor/bagian berganti)
//   { kind: 'stop', beat }          berhenti seketika (redam semua)

import { parsePattern, chordFor, voiceChord, bassVoicing, eventMidis } from './arranger-pattern.js';

export const CHORD_QUANTUM = 0.5;      // seperdelapan (setengah ketukan)
export const RETRIGGER_MIN = 0.5;      // sisa nada minimal (ketukan) yang dibunyikan ulang
export const SECTION_NAMES = ['intro', 'main_a', 'main_b', 'fill', 'ending'];
export const TRACKS = ['drum', 'bass', 'chord'];
const EPS = 1e-6;

// Ubah data style dari server (pola masih teks) menjadi pola siap pakai.
// Pola yang salah tulis dilewati (admin sudah divalidasi saat menyimpan).
export function prepareStyle(style) {
    const sections = {};
    for (const name of SECTION_NAMES) {
        const raw = style.sections && style.sections[name];
        if (!raw) {
            continue;
        }
        const tracks = {};
        for (const track of TRACKS) {
            tracks[track] = raw.tracks && raw.tracks[track] ? parsePattern(raw.tracks[track], track, raw.length).events : [];
        }
        sections[name] = { length: raw.length, tracks };
    }
    return { ...style, sections };
}

export class ArrangerEngine {
    // style: hasil prepareStyle(). bpm awal = style.bpm.
    constructor({ style, transpose = 0 }) {
        this.style = style;
        this.beatsPerBar = style.beats_per_bar;
        this.bpm = style.bpm;
        this.transpose = transpose;
        this.syncStart = false;

        this.playing = false;
        this.anchorTime = 0;       // detik jam audio saat ketukan anchorBeat
        this.anchorBeat = 0;
        this.section = null;       // { name, start } bagian yang sedang berjalan
        this.mainVariant = 'main_a';
        this.introArmed = false;
        this.queued = null;        // { name, at } bagian berikutnya, berlaku di ketukan at
        this.scheduledUntil = 0;   // ketukan terakhir yang sudah dijadwalkan

        this.chord = chordFor(1, transpose);
        this.pendingChord = null;  // { chord, at }
        this.voicing = voiceChord(this.chord);
        this.bass = bassVoicing(this.chord);
        this.active = [];          // nada bass/akor yang mungkin masih berbunyi
        this.nextId = 1;
    }

    has(name) {
        return Boolean(this.style.sections[name]);
    }

    // Main B tidak ada -> pakai Main A.
    mainName(name) {
        return this.has(name) ? name : 'main_a';
    }

    // ------------------------------------------------------------------
    // Jam
    // ------------------------------------------------------------------
    beatAt(time) {
        return this.anchorBeat + (time - this.anchorTime) * this.bpm / 60;
    }

    timeAt(beat) {
        return this.anchorTime + (beat - this.anchorBeat) * 60 / this.bpm;
    }

    secondsPerBeat() {
        return 60 / this.bpm;
    }

    // Tempo bisa diubah kapan saja: jam di-"jangkar" ulang di posisi sekarang.
    setBpm(bpm, now) {
        if (this.playing) {
            this.anchorBeat = this.beatAt(now);
            this.anchorTime = now;
        }
        this.bpm = bpm;
    }

    // Ketukan paling awal yang masih bisa diubah (yang sebelumnya sudah dijadwalkan).
    earliestBeat(now) {
        return Math.max(this.beatAt(now), this.scheduledUntil);
    }

    // Awal birama berikutnya, dihitung dari awal bagian yang sedang berjalan.
    nextBarBeat(now) {
        const from = this.earliestBeat(now);
        const start = this.section.start;
        const bars = Math.ceil((from - start - EPS) / this.beatsPerBar);
        return start + Math.max(0, bars) * this.beatsPerBar;
    }

    // ------------------------------------------------------------------
    // Kendali dari pemain
    // ------------------------------------------------------------------
    // now = waktu jam audio sekarang; mulai sedikit di depan supaya ketukan pertama utuh.
    start(now) {
        if (this.playing) {
            return;
        }
        this.playing = true;
        this.anchorTime = now + 0.05;
        this.anchorBeat = 0;
        this.scheduledUntil = 0;
        this.active = [];
        this.queued = null;
        if (this.pendingChord) {
            this.applyChord(this.pendingChord.chord);
            this.pendingChord = null;
        }
        const first = this.introArmed && this.has('intro') ? 'intro' : this.mainName(this.mainVariant);
        this.section = { name: first, start: 0 };
        this.introArmed = false;
    }

    // Tombol berhenti: masuk Ending bila ada; kalau sedang Ending, berhenti seketika.
    stop(now) {
        if (!this.playing) {
            this.introArmed = false;
            return;
        }
        if (this.section.name === 'ending' || (this.queued && this.queued.name === 'ending')) {
            this.stopNow(now);
            return;
        }
        this.queued = { name: 'ending', at: this.nextBarBeat(now) };
    }

    stopNow(now) {
        this.stopActions = [{ kind: 'stop', beat: this.beatAt(now) }];
        this.playing = false;
        this.section = null;
        this.queued = null;
        this.active = [];
    }

    // Tombol bagian (A S D F G).
    selectSection(name, now) {
        if (!this.playing) {
            if (name === 'intro') {
                this.introArmed = this.has('intro') && !this.introArmed;
            } else if (name === 'main_a' || name === 'main_b') {
                this.mainVariant = this.mainName(name);
            }
            return;
        }
        if (name === 'main_a' || name === 'main_b') {
            this.mainVariant = this.mainName(name);
            const current = this.section.name;
            // Saat Intro atau Fill, Main yang dipilih dipakai setelah bagian itu selesai.
            if ((current === 'main_a' || current === 'main_b') && current !== this.mainVariant) {
                this.queued = { name: this.mainVariant, at: this.nextBarBeat(now) };
            } else if (this.queued && (this.queued.name === 'main_a' || this.queued.name === 'main_b')) {
                this.queued = null;
            }
            return;
        }
        if (name === 'fill' && this.has('fill') && this.section.name !== 'ending') {
            this.queued = { name: 'fill', at: this.nextBarBeat(now) };
            return;
        }
        if (name === 'ending') {
            this.stop(now);
        }
    }

    // Tombol akor. degree 1..7, flip = Shift. Bila sync start aktif dan iringan
    // berhenti, akor pertama sekaligus memulai iringan.
    setChord(degree, flip, now) {
        const chord = chordFor(degree, this.transpose, flip);
        if (!this.playing) {
            this.applyChord(chord);
            this.pendingChord = null;
            if (this.syncStart) {
                this.start(now);
            }
            return;
        }
        const from = this.earliestBeat(now);
        const at = Math.ceil((from - EPS) / CHORD_QUANTUM) * CHORD_QUANTUM;
        this.pendingChord = { chord, at };
    }

    // Transpose berlaku di akor berikutnya (akor yang sedang berbunyi tidak berubah).
    setTranspose(transpose) {
        this.transpose = transpose;
    }

    applyChord(chord) {
        this.chord = chord;
        this.voicing = voiceChord(chord, this.voicing ? this.voicing.center : 62);
        this.bass = bassVoicing(chord);
    }

    // Keadaan untuk tampilan.
    status(now) {
        const pending = this.pendingChord ? this.pendingChord.chord : null;
        if (!this.playing) {
            return {
                playing: false, section: null, queued: null, mainVariant: this.mainVariant,
                introArmed: this.introArmed, chord: this.chord, pendingChord: pending, beatInBar: null,
            };
        }
        const beat = Math.max(0, this.beatAt(now) - this.section.start);
        return {
            playing: true,
            section: this.section.name,
            queued: this.queued ? this.queued.name : null,
            mainVariant: this.mainVariant,
            introArmed: false,
            chord: this.chord,
            pendingChord: pending,
            beatInBar: Math.floor(beat + EPS) % this.beatsPerBar,
        };
    }

    // ------------------------------------------------------------------
    // Penjadwalan
    // ------------------------------------------------------------------
    tick(now, lookahead) {
        const out = this.stopActions || [];
        this.stopActions = null;
        if (!this.playing) {
            return out;
        }
        const to = this.beatAt(now + lookahead);
        let from = this.scheduledUntil;
        // Batas pengaman: satu tick tidak boleh berputar tanpa akhir.
        for (let guard = 0; guard < 64 && this.playing && from < to - EPS; guard++) {
            this.applyBoundaries(from, out);
            if (!this.playing) {
                break;
            }
            const sectionEnd = this.section.start + this.style.sections[this.section.name].length;
            let end = Math.min(to, sectionEnd);
            if (this.queued && this.queued.at > from + EPS) {
                end = Math.min(end, this.queued.at);
            }
            if (this.pendingChord && this.pendingChord.at > from + EPS) {
                end = Math.min(end, this.pendingChord.at);
            }
            this.emit(from, end, out);
            from = end;
        }
        // Batas yang jatuh tepat di ujung jendela diproses di tick berikutnya.
        this.scheduledUntil = Math.max(this.scheduledUntil, Math.min(from, to));
        return out;
    }

    // Terapkan perpindahan yang jatuh tepat di ketukan beat.
    applyBoundaries(beat, out) {
        const def = this.style.sections[this.section.name];
        if (beat >= this.section.start + def.length - EPS) {
            this.sectionFinished(this.section.start + def.length, out);
            if (!this.playing) {
                return;
            }
        }
        if (this.queued && this.queued.at <= beat + EPS) {
            const { name, at } = this.queued;
            this.queued = null;
            if (name === 'ending' && !this.has('ending')) {
                this.finish(at, out);
                return;
            }
            this.cutActive(at, out);
            this.section = { name, start: at };
        }
        if (this.pendingChord && this.pendingChord.at <= beat + EPS) {
            const { chord, at } = this.pendingChord;
            this.pendingChord = null;
            this.applyChord(chord);
            this.retrigger(at, out);
        }
    }

    sectionFinished(at, out) {
        const name = this.section.name;
        if (name === 'ending') {
            // Nada terakhir dibiarkan meluruh sendiri.
            this.playing = false;
            this.section = null;
            this.queued = null;
            this.active = [];
            return;
        }
        if (name === 'main_a' || name === 'main_b') {
            // Main berulang terus; antrean di batas yang sama diproses sesudahnya.
            this.section = { name, start: at };
            return;
        }
        // Intro dan Fill selesai: lanjut ke Main yang dipilih.
        this.cutActive(at, out);
        this.section = { name: this.mainName(this.mainVariant), start: at };
    }

    finish(at, out) {
        out.push({ kind: 'stop', beat: at });
        this.playing = false;
        this.section = null;
        this.active = [];
    }

    // Bunyikan kejadian bagian yang sedang berjalan dalam [from, end).
    emit(from, end, out) {
        const def = this.style.sections[this.section.name];
        const lo = from - this.section.start;
        const hi = end - this.section.start;
        const due = [];
        for (const track of TRACKS) {
            for (const event of def.tracks[track]) {
                if (event.beat >= lo - EPS && event.beat < hi - EPS) {
                    due.push({ track, event });
                }
            }
        }
        due.sort((a, b) => a.event.beat - b.event.beat);
        for (const { track, event } of due) {
            const beat = this.section.start + event.beat;
            if (track === 'drum') {
                out.push({ kind: 'drum', beat, code: event.code, vel: event.vel });
            } else {
                this.pushNote(track, event, beat, event.dur, out);
            }
        }
        this.active = this.active.filter((note) => note.end > end + EPS);
    }

    pushNote(track, event, beat, dur, out) {
        const id = this.nextId++;
        const midis = eventMidis(event, track === 'bass' ? this.bass : this.voicing);
        out.push({ kind: 'note', id, track, beat, dur, vel: event.vel, midis });
        this.active.push({ id, track, event, end: beat + dur });
    }

    // Akor berganti di ketukan at: nada panjang yang masih berbunyi dipotong
    // dan dibunyikan ulang dengan akor baru untuk sisa durasinya.
    retrigger(at, out) {
        const still = this.active.filter((note) => note.end > at + EPS);
        this.active = [];
        for (const note of still) {
            out.push({ kind: 'cut', id: note.id, beat: at });
            const remaining = note.end - at;
            if (remaining >= RETRIGGER_MIN - EPS) {
                this.pushNote(note.track, note.event, at, remaining, out);
            }
        }
    }

    // Bagian berganti: semua nada panjang dari bagian lama dipotong.
    cutActive(at, out) {
        for (const note of this.active) {
            if (note.end > at + EPS) {
                out.push({ kind: 'cut', id: note.id, beat: at });
            }
        }
        this.active = [];
    }
}
