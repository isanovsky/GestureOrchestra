// Logika permainan yang dipakai bersama oleh mode solo (play.php) dan
// main bersama (room.php).
//
// Alur satu masukan:
//   HandTracker (dan keyboard drum) -> handleGesture(jumlahJari, waktu) -> cari bunyi lewat pemetaan gestur
//   Keyboard piano/strings dan pad di layar -> handleSound(bunyi, waktu) langsung
//   -> mode Melodi: AudioEngine.noteOn   | mode Akor: susun akor diatonis -> chordOn
//   -> bila lagu sedang berjalan, catat event { t_ms, sound_id } (lepas = null)
// Di akhir lagu, daftar event dikirim ke server untuk dinilai. Penandaan
// "kena" di layar hanya hiasan; nilai resmi tetap dihitung server.
//
// Selain itu ada penjadwal kecil (setiap 25 ms) yang mengikuti jam lagu untuk:
// - ritme otomatis akor piano (pukulan ulang, TIDAK dikirim sebagai event),
// - hi-hat otomatis pemain drum (pengiring, tidak dinilai),
// - klik hitungan masuk satu birama sebelum lagu mulai.

import {
    codeToDegree, codeToMidi, midiToFrequency, diatonicTriad, chordName,
    degreeLetterName, instrumentDoOctave, parseSoundCode,
} from './music.js';
import { keyLabelForSound } from './keymap.js';

// Sama dengan SCORE_TOLERANCE_MS di includes/scoring.php.
export const TOLERANCE_MS = 250;
// Event masih dicatat sampai 2 detik setelah lagu selesai (sama dengan server).
const LATE_GRACE_MS = 2000;
const PX_PER_BEAT = 90;
// Penjadwal: diperiksa tiap 25 ms, menjadwalkan bunyi 60 ms ke depan.
const SCHEDULER_MS = 25;
const LOOKAHEAD_MS = 60;
// Pukulan ulang ritme tidak dibunyikan bila terlalu dekat dengan pukulan pertama.
const RESTRIKE_GAP_MS = 150;

// Susun peta dari data api/instrument.php:
//   byCount     : jumlah jari -> bunyi
//   countBySound: id bunyi -> jumlah jari (untuk petunjuk "angkat 3 jari")
export function buildGestureMap(instrumentData) {
    const soundsById = new Map(instrumentData.sounds.map((sound) => [sound.id, sound]));
    const byCount = new Map();
    const countBySound = new Map();
    for (const gesture of instrumentData.gestures) {
        const sound = soundsById.get(gesture.sound_id);
        if (sound) {
            byCount.set(gesture.finger_count, sound);
            if (!countBySound.has(sound.id)) {
                countBySound.set(sound.id, gesture.finger_count);
            }
        }
    }
    return { soundsById, byCount, countBySound };
}

export class GamePlayer {
    // instrumentData : hasil api/instrument.php
    // song           : objek song dari api/song.php (bpm sudah bpm efektif), atau null untuk main bebas
    // notes          : partitur part yang dimainkan, [] bila bebas
    // style          : 'notes' (melodi) atau 'chords' (akor)
    // freeBpm        : tempo metronom di mode bebas (untuk ritme akor)
    // audio          : AudioEngine yang sudah di-unlock
    // els            : { lane, track, hint, beat, icon, now } elemen tampilan (boleh null)
    // view           : InstrumentView (keyboard piano / deretan nada / set drum), boleh null
    // onFinish(events): dipanggil sekali saat lagu selesai
    constructor({ instrumentData, song, notes, style, freeBpm, audio, els, view, onFinish }) {
        this.instrument = instrumentData.instrument;
        this.isDrum = this.instrument.type === 'drum';
        this.map = buildGestureMap(instrumentData);
        this.doOctave = instrumentDoOctave(instrumentData.sounds, instrumentData.gestures);
        this.song = song;
        this.notes = (notes || []).map((note) => ({ ...note, hit: false, missed: false, el: null, labelEl: null }));
        this.style = this.isDrum ? 'notes' : (style || 'notes');
        this.audio = audio;
        this.els = els;
        this.view = view || null;
        this.onFinish = onFinish || (() => {});

        this.transpose = 0;
        this.options = {
            bass: 1,              // 0 mati, 1 satu oktaf di bawah, 2 dua oktaf di bawah
            rhythm: 'change',     // 'change' hanya saat ganti akor, 'half' ketukan 1 dan 3, 'beat' setiap ketukan
            autoHihat: this.isDrum,
        };

        this.state = 'idle';     // idle -> countdown -> playing -> done
        this.events = [];
        this.songStart = 0;      // performance.now() saat ketukan 0
        this.lastBeatShown = null;
        this.heldChord = null;   // akor yang sedang ditahan (untuk ritme otomatis)
        this.frame = this.frame.bind(this);

        if (song) {
            this.beatMs = 60000 / song.bpm;
            this.lengthMs = song.length_beats * this.beatMs;
        }

        // Jam mode bebas: metronom tak terlihat yang mulai saat panggung siap.
        this.freeOrigin = performance.now();
        this.freeBeatMs = 60000 / (freeBpm || 90);
        this.clockKey = null;
        this.nextHalf = 0;
        this.timer = setInterval(() => this.schedule(), SCHEDULER_MS);
    }

    destroy() {
        clearInterval(this.timer);
        this.state = 'idle';
        this.audio.noteOff();
    }

    // ------------------------------------------------------------------
    // Pengaturan dari panel
    // ------------------------------------------------------------------
    setTranspose(semitones) {
        this.transpose = semitones;
        this.audio.setTranspose(semitones);
        this.relabelLane();
        this.updateHint(true);
    }

    // Tempo berubah sebelum lagu dimulai (mode room: host mengganti tempo di lobby).
    setSongBpm(bpm) {
        if (!this.song || this.state === 'countdown' || this.state === 'playing') {
            return;
        }
        this.song.bpm = bpm;
        this.beatMs = 60000 / bpm;
        this.lengthMs = this.song.length_beats * this.beatMs;
    }

    setStyle(style) {
        if (!this.isDrum && !this.song) {
            this.style = style;
            this.release();
        }
    }

    setOption(name, value) {
        this.options[name] = value;
    }

    // Nama bunyi sesuai mode dan transpose:
    // akor -> 'Em'; melodi -> 'Mi' (pendek) atau 'Mi (E)' (lengkap).
    soundLabel(sound, short = false) {
        const degree = this.doOctave === null ? null : codeToDegree(sound.code, this.doOctave);
        if (!degree || this.isDrum) {
            return sound.name;
        }
        if (this.style === 'chords') {
            return chordName(degree.degree, this.transpose);
        }
        return short ? sound.name : `${sound.name} (${degreeLetterName(degree.degree, this.transpose)})`;
    }

    // ------------------------------------------------------------------
    // Gestur
    // ------------------------------------------------------------------
    // Dipanggil setiap jumlah jari (stabil) berubah: kamera, atau keyboard drum.
    handleGesture(count, atMs) {
        const sound = count === 0 ? null : this.map.byCount.get(count);
        if (count !== 0 && !sound) {
            this.release();
            this.showNow(count + ' jari: belum ada bunyinya');
            this.recordEvent(null, atMs);
            return;
        }
        this.playSound(sound, atMs, count === 0 ? '' : count + ' jari');
    }

    // Bunyi dipilih langsung (keyboard piano/strings atau pad di layar), termasuk
    // nada yang tidak punya gestur kamera. sound null = lepas.
    handleSound(sound, atMs, keyLabel = null) {
        this.playSound(sound, atMs, keyLabel ? 'Tombol ' + keyLabel : '');
    }

    playSound(sound, atMs, prefix) {
        if (!sound) {
            this.release();
            this.showNow('Diam');
        } else {
            if (this.style === 'chords') {
                this.playChord(sound);
            } else {
                this.heldChord = null;
                this.audio.noteOn(sound, this.instrument.play_mode);
                this.lightSound(sound);
            }
            this.showNow((prefix ? prefix + ': ' : '') + this.soundLabel(sound));
            this.bounceIcon();
        }
        this.recordEvent(sound, atMs);
    }

    // Catat event selama lagu berjalan. Lepas (atau jumlah jari tanpa bunyi)
    // dicatat sebagai event dengan sound_id null. Server memakainya untuk
    // menilai part akor; part melodi mengabaikannya.
    recordEvent(sound, atMs) {
        if (this.state !== 'playing') {
            return;
        }
        const tMs = Math.round(atMs - this.songStart);
        const soundId = sound ? sound.id : null;
        const last = this.events[this.events.length - 1];
        const repeatRelease = soundId === null && (!last || last.sound_id === null);
        if (tMs >= 0 && tMs <= this.lengthMs + LATE_GRACE_MS && !repeatRelease) {
            this.events.push({ t_ms: tMs, sound_id: soundId });
            if (sound && this.style === 'notes') {
                this.markHit(sound.id, tMs);
            }
        }
    }

    // Tombol keyboard untuk sebuah bunyi (piano/strings), atau null.
    keyLabel(sound) {
        return this.isDrum ? null : keyLabelForSound(sound, this.doOctave);
    }

    release() {
        this.heldChord = null;
        this.audio.noteOff();
        this.view && this.view.release();
    }

    // Nyalakan tuts/bagian visual untuk satu bunyi melodi.
    lightSound(sound) {
        if (!this.view) {
            return;
        }
        if (this.isDrum) {
            this.view.hitDrum(sound.code);
            return;
        }
        const midi = codeToMidi(sound.code);
        const hold = this.instrument.play_mode === 'sustain';
        if (hold) {
            this.view.release();   // legato: nada lama padam, nada baru menyala
        }
        if (midi !== null) {
            this.view.light([midi + this.transpose], hold);
        }
    }

    // MIDI yang benar-benar berbunyi untuk akor yang ditahan (termasuk bass).
    chordMidis(chord) {
        return [...chord.midis, ...chord.bassMidis].map((midi) => midi + this.transpose);
    }

    // Susun akor diatonis dari nada dasar bunyi gestur, lalu bunyikan.
    // Jenis akor ditentukan dari huruf nada dasar di kode bunyi, bukan sort_order.
    playChord(sound) {
        const degree = this.doOctave === null ? null : codeToDegree(sound.code, this.doOctave);
        const rootMidi = codeToMidi(sound.code);
        if (!degree || rootMidi === null) {
            this.audio.noteOn(sound, this.instrument.play_mode);
            return;
        }
        const midis = diatonicTriad(rootMidi, degree.degree);
        // Bass otomatis hanya untuk piano: nada dasar 1 atau 2 oktaf di bawah.
        const bassMidis = this.instrument.play_mode === 'decay' && this.options.bass > 0
            ? [rootMidi - 12 * this.options.bass]
            : [];
        const freqs = midis.map(midiToFrequency);
        const bass = bassMidis.map(midiToFrequency);
        this.audio.chordOn(freqs, this.instrument.play_mode, bass);
        // root = huruf nada dasar, sama seperti cara server menilai akor.
        this.heldChord = { freqs, bass, midis, bassMidis, since: performance.now(), root: parseSoundCode(sound.code).letter };

        if (this.view) {
            const hold = this.instrument.play_mode === 'sustain';
            if (hold) {
                this.view.release();
            }
            this.view.light(this.chordMidis(this.heldChord), hold);
        }
    }

    // ------------------------------------------------------------------
    // Lagu
    // ------------------------------------------------------------------
    // Mulai lagu tepat pada songStartMs (waktu performance.now()). Sebelum itu
    // tampil hitung mundur dan berbunyi klik satu birama. Di mode room, waktu
    // ini berasal dari jam server.
    startAt(songStartMs) {
        if (!this.song) {
            return;
        }
        this.songStart = songStartMs;
        this.events = [];
        this.notes.forEach((note) => {
            note.t_ms = note.start_beat * this.beatMs;
            note.hit = false;
            note.missed = false;
        });
        this.buildLane();
        this.state = 'countdown';

        // Hitungan masuk: satu klik per ketukan pada birama sebelum lagu.
        const bpb = this.song.beats_per_bar;
        for (let i = 0; i < bpb; i++) {
            const at = songStartMs - (bpb - i) * this.beatMs;
            if (at > performance.now()) {
                this.audio.click(this.audio.audioTimeAt(at), i === 0);
            }
        }
        requestAnimationFrame(this.frame);
    }

    stop() {
        this.state = 'idle';
        this.release();
    }

    // ------------------------------------------------------------------
    // Penjadwal ritme (setiap setengah ketukan)
    // ------------------------------------------------------------------
    // Jam yang dipakai: jam lagu bila lagu sedang berjalan, selain itu jam bebas.
    clock() {
        if (this.song && (this.state === 'countdown' || this.state === 'playing')) {
            return { origin: this.songStart, beatMs: this.beatMs, key: 'song-' + this.songStart };
        }
        return { origin: this.freeOrigin, beatMs: this.freeBeatMs, key: 'free' };
    }

    schedule() {
        const now = performance.now();
        const clock = this.clock();
        const half = clock.beatMs / 2;
        if (clock.key !== this.clockKey) {
            this.clockKey = clock.key;
            this.nextHalf = Math.ceil((now - clock.origin) / half);
        }
        while (clock.origin + this.nextHalf * half < now + LOOKAHEAD_MS) {
            const index = this.nextHalf++;
            const at = clock.origin + index * half;
            if (at >= now - 10) {
                this.onGrid(index, at);
            }
        }
    }

    // Dipanggil untuk setiap titik setengah ketukan. index genap = ketukan penuh.
    onGrid(index, at) {
        const isBeat = index % 2 === 0;
        const beat = Math.floor(index / 2);
        const bpb = this.song ? this.song.beats_per_bar : 4;
        const inBar = ((beat % bpb) + bpb) % bpb;

        // Ritme akor piano: pukul ulang akor yang sedang ditahan.
        const chord = this.heldChord;
        if (chord && isBeat && this.style === 'chords' && this.instrument.play_mode === 'decay'
            && this.options.rhythm !== 'change' && at - chord.since > RESTRIKE_GAP_MS) {
            const onBeat = this.options.rhythm === 'beat'
                || inBar === 0 || (bpb >= 4 && inBar === 2);
            if (onBeat) {
                this.audio.strikeChord(chord.freqs, chord.bass, this.audio.audioTimeAt(at), 0.8);
                this.flashLater(at, () => this.view.light(this.chordMidis(chord), false));
            }
        }

        // Hi-hat otomatis untuk pemain drum, hanya selama lagu.
        if (this.isDrum && this.options.autoHihat && this.song
            && (this.state === 'countdown' || this.state === 'playing')
            && at >= this.songStart && at < this.songStart + this.lengthMs) {
            this.audio.hihat(this.audio.audioTimeAt(at));
            this.flashLater(at, () => this.view.hitDrum('HIHAT'));
        }
    }

    // Visual untuk bunyi terjadwal dinyalakan tepat saat bunyinya terdengar.
    flashLater(atMs, show) {
        if (this.view) {
            setTimeout(show, Math.max(0, atMs - performance.now()));
        }
    }

    // ------------------------------------------------------------------
    // Loop tampilan, sekitar 60 kali per detik
    // ------------------------------------------------------------------
    frame() {
        if (this.state !== 'countdown' && this.state !== 'playing') {
            return;
        }
        const elapsed = performance.now() - this.songStart;

        if (elapsed < 0) {
            // Hitung mundur dalam ketukan bila tinggal satu birama, selain itu dalam detik.
            const beatsLeft = Math.ceil(-elapsed / this.beatMs);
            const text = beatsLeft <= this.song.beats_per_bar ? String(beatsLeft) : Math.ceil(-elapsed / 1000) + ' dtk';
            this.showBeat(text, 'countdown-' + beatsLeft);
        } else {
            this.state = 'playing';
            const beat = Math.floor(elapsed / this.beatMs);
            const bar = Math.floor(beat / this.song.beats_per_bar) + 1;
            const beatInBar = (beat % this.song.beats_per_bar) + 1;
            this.showBeat(beatInBar === 1 ? 'Birama ' + bar : String(beatInBar), 'beat-' + beat);
        }

        // Geser lajur: ketukan saat ini selalu berada di garis main.
        if (this.els.track) {
            const currentBeat = elapsed / this.beatMs;
            this.els.track.style.transform = `translateX(${this.hitX - currentBeat * PX_PER_BEAT}px)`;
        }

        // Akor: entri yang sedang berjalan ditandai kena bila akor yang benar
        // sedang ditahan (hanya tampilan; server menilai dari lama ditahan).
        if (this.style === 'chords' && this.heldChord) {
            for (const note of this.notes) {
                const end = note.t_ms + note.duration_beats * this.beatMs;
                if (!note.hit && elapsed >= note.t_ms && elapsed < end) {
                    const code = (this.map.soundsById.get(note.sound_id) || {}).code;
                    const parsed = parseSoundCode(code);
                    if (parsed && parsed.letter === this.heldChord.root) {
                        note.hit = true;
                        note.el && note.el.classList.add('is-hit');
                    }
                }
            }
        }

        // Nada yang sudah lewat jendela toleransi tanpa dimainkan = meleset.
        // Akor dinilai dari lama ditahan, jadi penanda "meleset" baru setelah akornya selesai.
        for (const note of this.notes) {
            const deadline = this.style === 'chords'
                ? note.t_ms + note.duration_beats * this.beatMs
                : note.t_ms + TOLERANCE_MS;
            if (!note.hit && !note.missed && elapsed > deadline) {
                note.missed = true;
                note.el && note.el.classList.add('is-missed');
            }
        }
        this.updateHint();

        // Lagu selesai satu ketukan setelah nada terakhir.
        if (elapsed >= this.lengthMs + this.beatMs) {
            this.state = 'done';
            this.release();
            this.showBeat('Selesai!', 'done');
            this.view && this.view.markNext(null);
            if (this.els.hint) {
                this.els.hint.textContent = 'Lagu selesai. Nilaimu sedang dihitung server...';
            }
            this.onFinish(this.events.slice());
            return;
        }
        requestAnimationFrame(this.frame);
    }

    buildLane() {
        const { lane, track } = this.els;
        if (!lane || !track) {
            return;
        }
        track.replaceChildren();
        lane.style.setProperty('--lane-color', this.instrument.color);
        this.hitX = lane.clientWidth * 0.2;

        // Garis ketukan; garis birama lebih tebal.
        for (let b = 0; b <= Math.ceil(this.song.length_beats); b++) {
            const line = document.createElement('span');
            line.className = b % this.song.beats_per_bar === 0 ? 'lane-grid is-bar' : 'lane-grid';
            line.style.left = b * PX_PER_BEAT + 'px';
            track.appendChild(line);
        }

        for (const note of this.notes) {
            const count = this.map.countBySound.get(note.sound_id);
            const el = document.createElement('span');
            el.className = 'lane-block';
            el.style.left = note.start_beat * PX_PER_BEAT + 'px';
            el.style.width = Math.max(note.duration_beats * PX_PER_BEAT - 6, 28) + 'px';

            // Angka = jumlah jari. Nada tanpa gestur kamera menampilkan tombol keyboardnya.
            const fingers = document.createElement('strong');
            const sound = this.map.soundsById.get(note.sound_id);
            const key = sound ? this.keyLabel(sound) : null;
            if (count === undefined) {
                fingers.textContent = key || '?';
                el.classList.add('is-keyboard-only');
                el.title = key ? 'Tombol ' + key + ' (hanya keyboard)' : '';
            } else {
                fingers.textContent = String(count);
            }
            const name = document.createElement('small');
            el.append(fingers, name);

            track.appendChild(el);
            note.el = el;
            note.labelEl = name;
        }
        this.relabelLane();
    }

    // Nama di balok lajur ikut berubah saat transpose diganti.
    relabelLane() {
        for (const note of this.notes) {
            const sound = this.map.soundsById.get(note.sound_id);
            if (note.labelEl) {
                note.labelEl.textContent = sound ? this.soundLabel(sound, true) : '';
            }
        }
    }

    // Tandai nada terdekat yang cocok sebagai kena (hanya tampilan).
    markHit(soundId, tMs) {
        let best = null;
        for (const note of this.notes) {
            const diff = Math.abs(note.t_ms - tMs);
            if (!note.hit && note.sound_id === soundId && diff <= TOLERANCE_MS && (best === null || diff < Math.abs(best.t_ms - tMs))) {
                best = note;
            }
        }
        if (best) {
            best.hit = true;
            best.missed = false;
            best.el && best.el.classList.add('is-hit');
            best.el && best.el.classList.remove('is-missed');
        }
    }

    // Petunjuk nada berikutnya, misalnya "Berikutnya: angkat 3 jari, bunyilah Mi (E)".
    updateHint(force = false) {
        if (!this.els.hint || (!force && this.state !== 'countdown' && this.state !== 'playing')) {
            return;
        }
        const next = this.notes.find((note) => !note.hit && !note.missed);
        let text = 'Tinggal menunggu lagu selesai...';
        if (next) {
            const sound = this.map.soundsById.get(next.sound_id);
            const count = this.map.countBySound.get(next.sound_id);
            const name = sound ? this.soundLabel(sound) : 'bunyi #' + next.sound_id;
            const verb = this.style === 'chords' ? 'akor' : 'bunyilah';
            const key = sound ? this.keyLabel(sound) : null;
            if (count !== undefined) {
                text = `Berikutnya: angkat ${count} jari${key ? ' (tombol ' + key + ')' : ''}, ${verb} ${name}`;
            } else if (key) {
                text = `Berikutnya: tombol ${key}, ${verb} ${name} (hanya keyboard)`;
            } else {
                text = `Berikutnya: ${name} (belum punya gestur)`;
            }
        }
        if (force || this.els.hint.textContent !== text) {
            this.els.hint.textContent = text;
            this.markNext(next);
        }
    }

    // Tanda tipis di visual untuk nada/akor berikutnya, seperti aplikasi belajar piano.
    markNext(next) {
        if (!this.view) {
            return;
        }
        const sound = next ? this.map.soundsById.get(next.sound_id) : null;
        if (!sound) {
            this.view.markNext(null);
        } else if (this.isDrum) {
            this.view.markNext(sound.code);
        } else if (this.style === 'chords') {
            const degree = this.doOctave === null ? null : codeToDegree(sound.code, this.doOctave);
            const root = codeToMidi(sound.code);
            this.view.markNext(degree && root !== null
                ? diatonicTriad(root, degree.degree).map((midi) => midi + this.transpose)
                : null);
        } else {
            const midi = codeToMidi(sound.code);
            this.view.markNext(midi === null ? null : [midi + this.transpose]);
        }
    }

    // Metronom visual: elemen berdenyut setiap kali ketukan berganti.
    showBeat(text, key) {
        const el = this.els.beat;
        if (!el || key === this.lastBeatShown) {
            return;
        }
        this.lastBeatShown = key;
        el.textContent = text;
        el.classList.remove('is-pulse');
        void el.offsetWidth;   // paksa browser mengulang animasi
        el.classList.add('is-pulse');
    }

    showNow(text) {
        if (this.els.now) {
            this.els.now.textContent = text;
        }
    }

    // Ikon instrumen melompat kecil setiap nada dibunyikan.
    bounceIcon() {
        const icon = this.els.icon;
        if (!icon) {
            return;
        }
        icon.classList.remove('is-bouncing');
        void icon.offsetWidth;
        icon.classList.add('is-bouncing');
    }
}
