// Tiga instrumen dengan Web Audio API langsung (tanpa library).
//
// Rantai suara:
//   setiap bunyi instrumen -> bus -> (kering) ------------------> master -> compressor -> speaker
//                                -> (basah) convolver -> wet --^
//   klik metronom ------------------------------------------> master (tanpa gema)
// Pola master gain dengan perubahan volume yang dihaluskan (ramp) dan
// "hentikan semua bunyi" diadaptasi dari src/audioEngine.ts di
// "Gesture Synth Weld" karya rainbow686 (MIT). Lihat THIRD_PARTY_NOTICES.md.
//
// Perilaku per play_mode (kolom instruments.play_mode):
//   decay   : piano. Bunyi sekali lalu meluruh sendiri.
//   sustain : strings. Legato: berbunyi selama gestur ditahan; pindah nada
//             tanpa melewati 0 berarti nada bergeser halus (glide).
//   hit     : drum. Pukulan pendek, dipilih dari kolom sounds.code.
//
// Transpose: semua frekuensi bernada dikalikan 2^(transpose/12).
// Drum tidak ikut ditranspose.

import { transposeFactor } from './music.js';

// Strings: pengaturan suara (detik dan cent).
const STRINGS = {
    attack: 0.15,         // naik pelan supaya mengalun
    releaseTau: 0.15,     // setTargetAtTime: sekitar 3 x tau = 0,45 detik meredup
    glide: 0.07,          // geser nada legato
    filterHz: 2500,       // lowpass supaya nada tinggi tidak tajam
    detuneCents: 7,       // dua gergaji dibedakan tipis (efek chorus)
    vibratoHz: 5,
    vibratoCents: 6,
    vibratoDelay: 0.3,    // vibrato baru masuk setelah nada berbunyi 300 ms
    // Oktaf sangat tinggi (di atas C6, sampai C7) terdengar menusuk karena
    // telinga paling peka di sekitar 2-4 kHz. Di atas tameAboveHz volume
    // diturunkan bertahap: C7 (2093 Hz) sekitar 65% dari volume biasa.
    tameAboveHz: 1046.5,
    tamePower: 0.6,
};

// Volume strings untuk sekumpulan frekuensi (sudah ditranspose).
function stringsLevel(freqs) {
    const highest = Math.max(...freqs);
    const tame = highest > STRINGS.tameAboveHz ? (STRINGS.tameAboveHz / highest) ** STRINGS.tamePower : 1;
    return (0.2 / Math.sqrt(freqs.length)) * tame;
}

export class AudioEngine {
    // baseUrl dipakai untuk memuat file sampel (sounds.sample_path).
    constructor({ baseUrl = '' } = {}) {
        this.baseUrl = baseUrl;
        this.ctx = null;
        this.master = null;
        this.bus = null;
        this.wet = null;
        this.volume = 0.8;
        this.reverb = 0.25;
        this.transpose = 0;
        this.voice = null;           // nada/akor sustain yang sedang berbunyi
        this.samples = new Map();    // sample_path -> AudioBuffer
        this.noiseBuffer = null;
    }

    // Browser hanya mengizinkan suara setelah pengguna berinteraksi,
    // jadi fungsi ini wajib dipanggil dari handler klik (misalnya tombol Mulai).
    async unlock() {
        if (this.ctx === null) {
            this.ctx = new (window.AudioContext || window.webkitAudioContext)();

            // Compressor mencegah suara pecah bila banyak bunyi menumpuk.
            const compressor = this.ctx.createDynamicsCompressor();
            compressor.threshold.value = -12;
            compressor.ratio.value = 6;
            compressor.connect(this.ctx.destination);

            this.master = this.ctx.createGain();
            this.master.gain.value = this.volume;
            this.master.connect(compressor);

            // Bus instrumen: jalur kering langsung ke master, jalur basah lewat gema.
            this.bus = this.ctx.createGain();
            this.bus.connect(this.master);
            const convolver = this.ctx.createConvolver();
            convolver.buffer = this.createImpulseResponse(2.2, 3);
            this.wet = this.ctx.createGain();
            this.wet.gain.value = this.reverb;
            this.bus.connect(convolver).connect(this.wet).connect(this.master);

            this.noiseBuffer = this.createNoiseBuffer();
        }
        if (this.ctx.state === 'suspended') {
            await this.ctx.resume();
        }
    }

    // Volume 0 sampai 1, berubah halus dalam 50 ms supaya tidak ada bunyi "klik".
    setVolume(value) {
        this.volume = Math.min(1, Math.max(0, value));
        this.rampParam(this.master && this.master.gain, this.volume);
    }

    // Jumlah gema 0 sampai 1.
    setReverb(value) {
        this.reverb = Math.min(1, Math.max(0, value));
        this.rampParam(this.wet && this.wet.gain, this.reverb);
    }

    setTranspose(semitones) {
        this.transpose = semitones;
    }

    rampParam(param, value) {
        if (!param) {
            return;
        }
        const now = this.ctx.currentTime;
        param.cancelScheduledValues(now);
        param.setValueAtTime(param.value, now);
        param.linearRampToValueAtTime(value, now + 0.05);
    }

    // Ubah waktu performance.now() (ms) menjadi waktu jam audio (detik).
    // Dipakai untuk menjadwalkan klik, ritme akor, dan hi-hat tepat waktu.
    audioTimeAt(perfMs) {
        return Math.max(this.ctx.currentTime, this.ctx.currentTime + (perfMs - performance.now()) / 1000);
    }

    // Muat file sampel untuk bunyi yang punya sample_path. Bila gagal, bunyi itu
    // tetap memakai sintesis, jadi permainan tidak terganggu.
    async loadSamples(sounds) {
        const jobs = sounds
            .filter((sound) => sound.sample_path && !this.samples.has(sound.sample_path))
            .map(async (sound) => {
                try {
                    const response = await fetch(this.baseUrl + '/' + sound.sample_path);
                    if (!response.ok) {
                        throw new Error('HTTP ' + response.status);
                    }
                    const buffer = await this.ctx.decodeAudioData(await response.arrayBuffer());
                    this.samples.set(sound.sample_path, buffer);
                } catch (error) {
                    console.warn('Sampel gagal dimuat, memakai sintesis:', sound.sample_path, error);
                }
            });
        await Promise.all(jobs);
    }

    // ------------------------------------------------------------------
    // Melodi: satu bunyi dari database
    // ------------------------------------------------------------------
    noteOn(sound, playMode) {
        if (this.ctx === null) {
            return;
        }
        const time = this.ctx.currentTime;
        const sample = sound.sample_path ? this.samples.get(sound.sample_path) : null;

        if (playMode === 'hit') {
            // Drum tidak bernada, jadi tidak ditranspose.
            if (sample) {
                this.playSample(sample, time, 1, false);
            } else {
                this.playDrum(sound.code, time);
            }
            return;
        }

        const factor = transposeFactor(this.transpose);
        if (sample) {
            // Sampel ditranspose lewat kecepatan putar.
            if (playMode === 'sustain') {
                this.noteOff();
                this.voice = this.playSample(sample, time, factor, true);
            } else {
                this.playSample(sample, time, factor, false);
            }
            return;
        }

        if (playMode === 'sustain') {
            this.sustain([sound.frequency], time);
        } else {
            this.playPiano(sound.frequency * factor, time, 0.45);
        }
    }

    // ------------------------------------------------------------------
    // Akor: beberapa frekuensi sekaligus (belum ditranspose)
    // ------------------------------------------------------------------
    // freqs     : nada akor
    // bassFreqs : nada bass (hanya dipakai piano)
    chordOn(freqs, playMode, bassFreqs = []) {
        if (this.ctx === null) {
            return;
        }
        if (playMode === 'sustain') {
            this.sustain(freqs, this.ctx.currentTime);
        } else {
            this.strikeChord(freqs, bassFreqs, this.ctx.currentTime);
        }
    }

    // Pukulan akor piano. $time = waktu jam audio (untuk ritme terjadwal).
    strikeChord(freqs, bassFreqs, time, velocity = 1) {
        const factor = transposeFactor(this.transpose);
        const level = 0.32 * velocity / Math.sqrt(freqs.length);
        freqs.forEach((freq) => this.playPiano(freq * factor, time, level));
        bassFreqs.forEach((freq) => this.playPiano(freq * factor, time, 0.42 * velocity));
    }

    // Lepas nada sustain (gestur turun ke 0). Piano dan drum meluruh sendiri.
    noteOff() {
        if (this.voice === null) {
            return;
        }
        const time = this.ctx.currentTime;
        const { gain, sources } = this.voice;
        gain.gain.cancelScheduledValues(time);
        gain.gain.setValueAtTime(gain.gain.value, time);
        gain.gain.setTargetAtTime(0, time, STRINGS.releaseTau);
        sources.forEach((source) => source.stop(time + 1));
        this.voice = null;
    }

    // Hentikan semua bunyi seketika (misalnya saat halaman ditinggal).
    stopAll() {
        this.noteOff();
        if (this.master) {
            const now = this.ctx.currentTime;
            this.master.gain.cancelScheduledValues(now);
            this.master.gain.setValueAtTime(0, now);
            this.master.gain.linearRampToValueAtTime(this.volume, now + 0.3);
        }
    }

    // ------------------------------------------------------------------
    // Strings legato
    // ------------------------------------------------------------------
    // Bila suara masih berbunyi dengan jumlah nada yang sama, nada digeser
    // halus (glide) tanpa mengulang envelope. Bila tidak, suara baru dibuat.
    sustain(freqs, time) {
        const factor = transposeFactor(this.transpose);
        const targets = freqs.map((freq) => freq * factor);

        if (this.voice && this.voice.oscs && this.voice.oscs.length === targets.length) {
            this.voice.oscs.forEach((pair, i) => pair.forEach((osc) => {
                osc.frequency.cancelScheduledValues(time);
                osc.frequency.setValueAtTime(osc.frequency.value, time);
                osc.frequency.linearRampToValueAtTime(targets[i], time + STRINGS.glide);
            }));
            // Volume ikut menyesuaikan tinggi nada baru (oktaf sangat tinggi lebih pelan).
            const gain = this.voice.gain.gain;
            gain.cancelScheduledValues(time);
            gain.setValueAtTime(gain.value, time);
            gain.linearRampToValueAtTime(stringsLevel(targets), time + STRINGS.glide);
            return;
        }

        this.noteOff();
        const gain = this.ctx.createGain();
        gain.gain.setValueAtTime(0, time);
        gain.gain.linearRampToValueAtTime(stringsLevel(targets), time + STRINGS.attack);

        const filter = this.ctx.createBiquadFilter();
        filter.type = 'lowpass';
        filter.frequency.value = STRINGS.filterHz;
        filter.Q.value = 0.7;
        filter.connect(gain).connect(this.bus);

        // Vibrato: LFO 5 Hz menggoyang detune beberapa cent, masuk perlahan.
        const lfo = this.ctx.createOscillator();
        lfo.frequency.value = STRINGS.vibratoHz;
        const depth = this.ctx.createGain();
        depth.gain.setValueAtTime(0, time);
        depth.gain.setValueAtTime(0, time + STRINGS.vibratoDelay);
        depth.gain.linearRampToValueAtTime(STRINGS.vibratoCents, time + STRINGS.vibratoDelay + 0.5);
        lfo.connect(depth);
        lfo.start(time);

        const oscs = targets.map((freq) => [-STRINGS.detuneCents, STRINGS.detuneCents].map((cents) => {
            const osc = this.ctx.createOscillator();
            osc.type = 'sawtooth';
            osc.frequency.value = freq;
            osc.detune.value = cents;
            depth.connect(osc.detune);
            osc.connect(filter);
            osc.start(time);
            return osc;
        }));

        this.voice = { gain, oscs, sources: [lfo, ...oscs.flat()] };
    }

    // ------------------------------------------------------------------
    // Piano dan drum
    // ------------------------------------------------------------------

    // Piano: gelombang segitiga, serangan cepat lalu meluruh, ditambah sedikit
    // sinus satu oktaf di atas supaya lebih "berdenting".
    // dest: tujuan bunyi (bawaan bus instrumen; arranger memakai jalur track sendiri).
    playPiano(frequency, time, peak, dest = this.bus) {
        const gain = this.envelopeGain(time, 0.005, peak, 1.6, dest);
        for (const [type, multiple, level] of [['triangle', 1, 1], ['sine', 2, 0.15]]) {
            const osc = this.ctx.createOscillator();
            osc.type = type;
            osc.frequency.value = frequency * multiple;
            const mix = this.ctx.createGain();
            mix.gain.value = level;
            osc.connect(mix).connect(gain);
            osc.start(time);
            osc.stop(time + 1.7);
        }
    }

    // Drum disintesis tanpa file, dipilih dari kode bunyi.
    // OHAT, KDUT, KTAK, TAMB hanya dipakai pola arranger (tidak ada di database).
    playDrum(code, time, dest = this.bus) {
        switch (code) {
            case 'KICK':
                // Sinus yang nadanya turun cepat: "dum".
                this.pitchDrop(150, 45, 0.12, 0.9, 0.45, time, dest);
                break;
            case 'RIM':
                // Klik kering: noise bandpass sangat pendek + nada tinggi pendek.
                this.noiseHit('bandpass', 1700, 0.5, 0.035, time, 0.001, 4, dest);
                this.pitchDrop(820, 760, 0.02, 0.3, 0.03, time, dest);
                break;
            case 'SNARE':
                // Noise bandpass (desis senar) + nada pendek (badan drum).
                this.noiseHit('bandpass', 1800, 0.6, 0.2, time, 0.002, 1, dest);
                this.pitchDrop(220, 160, 0.05, 0.35, 0.12, time, dest);
                break;
            case 'HIHAT':
                // Noise highpass yang sangat pendek: "ts".
                this.noiseHit('highpass', 7000, 0.35, 0.06, time, 0.002, 1, dest);
                break;
            case 'OHAT':
                // Hi-hat terbuka: seperti HIHAT tetapi luruh lebih panjang: "tsss".
                this.noiseHit('highpass', 6500, 0.3, 0.35, time, 0.002, 1, dest);
                break;
            case 'CYMBAL':
                // Cymbal lembut: highpass, masuk pelan (sekitar 20 ms), luruh panjang, lebih pelan dari crash.
                this.noiseHit('highpass', 6000, 0.2, 1.8, time, 0.02, 1, dest);
                break;
            case 'TOM':
                // Sinus yang turun lebih lambat dari kick.
                this.pitchDrop(220, 110, 0.3, 0.7, 0.5, time, dest);
                break;
            case 'CRASH':
                // Noise highpass dengan luruh panjang.
                this.noiseHit('highpass', 5000, 0.4, 1.6, time, 0.002, 1, dest);
                break;
            case 'KDUT':
                // Kendang "dut": bunyi rendah membulat yang nadanya sedikit naik lalu turun.
                this.pitchDrop(95, 140, 0.04, 0.8, 0.35, time, dest);
                this.pitchDrop(140, 85, 0.25, 0.5, 0.3, time + 0.04, dest);
                break;
            case 'KTAK':
                // Kendang "tak": tepukan kulit kecil, nada tinggi pendek + noise bandpass.
                this.pitchDrop(520, 380, 0.03, 0.45, 0.09, time, dest);
                this.noiseHit('bandpass', 2500, 0.35, 0.05, time, 0.001, 3, dest);
                break;
            case 'TAMB':
                // Tamborin: kerincing tinggi yang pendek.
                this.noiseHit('bandpass', 9000, 0.22, 0.12, time, 0.003, 2, dest);
                break;
            default:
                // Kode tidak dikenal: ketukan pendek supaya tetap ada umpan balik.
                this.pitchDrop(400, 300, 0.05, 0.4, 0.1, time, dest);
        }
    }

    // ------------------------------------------------------------------
    // Suara iringan arranger (bass, petik, pad). Semua menuju dest dan
    // berhenti sendiri; arranger.js memotong nada lewat gain per nada.
    // ------------------------------------------------------------------

    // Bass: sinus + segitiga yang disaring, ditahan selama duration detik.
    playBass(frequency, time, duration, peak, dest) {
        const filter = this.ctx.createBiquadFilter();
        filter.type = 'lowpass';
        filter.frequency.value = 700;
        const gain = this.ctx.createGain();
        const end = time + Math.max(0.08, duration);
        gain.gain.setValueAtTime(0.0001, time);
        gain.gain.linearRampToValueAtTime(peak, time + 0.006);
        gain.gain.setTargetAtTime(peak * 0.7, time + 0.006, 0.3);
        gain.gain.setTargetAtTime(0.0001, end, 0.04);
        filter.connect(gain).connect(dest);
        for (const [type, level] of [['sine', 1], ['triangle', 0.5]]) {
            const osc = this.ctx.createOscillator();
            osc.type = type;
            osc.frequency.value = frequency;
            const mix = this.ctx.createGain();
            mix.gain.value = level;
            osc.connect(mix).connect(filter);
            osc.start(time);
            osc.stop(end + 0.3);
        }
    }

    // Petik (gitar/mandolin): gergaji dengan filter yang cepat menutup.
    playPluck(frequency, time, peak, dest) {
        const filter = this.ctx.createBiquadFilter();
        filter.type = 'lowpass';
        filter.Q.value = 2;
        filter.frequency.setValueAtTime(3200, time);
        filter.frequency.exponentialRampToValueAtTime(500, time + 0.25);
        const gain = this.envelopeGain(time, 0.003, peak, 0.45, dest);
        filter.connect(gain);
        const osc = this.ctx.createOscillator();
        osc.type = 'sawtooth';
        osc.frequency.value = frequency;
        osc.connect(filter);
        osc.start(time);
        osc.stop(time + 0.5);
    }

    // Pad: dua gergaji lembut (sedikit beda nada) yang masuk dan keluar pelan.
    playPad(frequency, time, duration, peak, dest) {
        const filter = this.ctx.createBiquadFilter();
        filter.type = 'lowpass';
        filter.frequency.value = 1800;
        const gain = this.ctx.createGain();
        const end = time + Math.max(0.1, duration);
        gain.gain.setValueAtTime(0.0001, time);
        gain.gain.linearRampToValueAtTime(peak, time + 0.12);
        gain.gain.setTargetAtTime(0.0001, end, 0.15);
        filter.connect(gain).connect(dest);
        for (const cents of [-6, 6]) {
            const osc = this.ctx.createOscillator();
            osc.type = 'sawtooth';
            osc.frequency.value = frequency;
            osc.detune.value = cents;
            osc.connect(filter);
            osc.start(time);
            osc.stop(end + 1);
        }
    }

    // Hi-hat otomatis yang pelan (pengiring pemain drum, tidak dinilai).
    hihat(time) {
        this.noiseHit('highpass', 8000, 0.09, 0.04, time);
    }

    // Klik metronom untuk hitungan masuk. Tidak lewat gema supaya tegas.
    click(time, accent) {
        const osc = this.ctx.createOscillator();
        osc.type = 'sine';
        osc.frequency.value = accent ? 1800 : 1200;
        const gain = this.ctx.createGain();
        gain.gain.setValueAtTime(0.0001, time);
        gain.gain.linearRampToValueAtTime(accent ? 0.35 : 0.22, time + 0.002);
        gain.gain.exponentialRampToValueAtTime(0.0001, time + 0.05);
        osc.connect(gain).connect(this.master);
        osc.start(time);
        osc.stop(time + 0.06);
    }

    // ------------------------------------------------------------------
    // Bahan dasar
    // ------------------------------------------------------------------

    // Gain dengan envelope: naik dalam attack detik ke level peak, lalu meluruh
    // eksponensial sampai hampir senyap pada detik ke-decay.
    envelopeGain(time, attack, peak, decay, dest = this.bus) {
        const gain = this.ctx.createGain();
        gain.gain.setValueAtTime(0.0001, time);
        gain.gain.linearRampToValueAtTime(peak, time + attack);
        gain.gain.exponentialRampToValueAtTime(0.0001, time + decay);
        gain.connect(dest);
        return gain;
    }

    // Nada sinus yang turun dari startHz ke endHz selama dropSeconds.
    pitchDrop(startHz, endHz, dropSeconds, peak, decay, time, dest = this.bus) {
        const osc = this.ctx.createOscillator();
        osc.type = 'sine';
        osc.frequency.setValueAtTime(startHz, time);
        osc.frequency.exponentialRampToValueAtTime(endHz, time + dropSeconds);
        osc.connect(this.envelopeGain(time, 0.002, peak, decay, dest));
        osc.start(time);
        osc.stop(time + decay + 0.05);
    }

    // Potongan noise yang disaring.
    noiseHit(filterType, filterHz, peak, decay, time, attack = 0.002, q = 1, dest = this.bus) {
        const source = this.ctx.createBufferSource();
        source.buffer = this.noiseBuffer;
        const filter = this.ctx.createBiquadFilter();
        filter.type = filterType;
        filter.frequency.value = filterHz;
        filter.Q.value = q;
        source.connect(filter).connect(this.envelopeGain(time, attack, peak, decay, dest));
        source.start(time);
        source.stop(time + decay + 0.05);
    }

    // Satu detik white noise, dibuat sekali lalu dipakai ulang.
    createNoiseBuffer() {
        const length = this.ctx.sampleRate;
        const buffer = this.ctx.createBuffer(1, length, this.ctx.sampleRate);
        const data = buffer.getChannelData(0);
        for (let i = 0; i < length; i++) {
            data[i] = Math.random() * 2 - 1;
        }
        return buffer;
    }

    // Impulse response untuk gema, dibuat dari noise yang meluruh (tanpa file).
    // seconds = panjang gema, decayPower = makin besar makin cepat meredup.
    createImpulseResponse(seconds, decayPower) {
        const length = Math.floor(this.ctx.sampleRate * seconds);
        const buffer = this.ctx.createBuffer(2, length, this.ctx.sampleRate);
        for (let channel = 0; channel < 2; channel++) {
            const data = buffer.getChannelData(channel);
            for (let i = 0; i < length; i++) {
                data[i] = (Math.random() * 2 - 1) * (1 - i / length) ** decayPower;
            }
        }
        return buffer;
    }

    // Putar file sampel. rate = kecepatan putar (transpose).
    // Untuk mode sustain, kembalikan voice supaya bisa dihentikan.
    playSample(buffer, time, rate, asVoice) {
        const source = this.ctx.createBufferSource();
        source.buffer = buffer;
        source.playbackRate.value = rate;
        const gain = this.ctx.createGain();
        gain.gain.value = 0.8;
        source.connect(gain).connect(this.bus);
        source.start(time);
        return asVoice ? { gain, sources: [source] } : null;
    }
}
