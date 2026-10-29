// Visual instrumen di bawah layar kamera, sekaligus pad untuk mode keyboard/sentuh.
//
//   Piano  : keyboard C3 sampai C6 (bergeser mengikuti transpose).
//   Strings: tiga baris nada (oktaf rendah, tengah, tinggi) dari bunyi di database.
//   Drum   : ilustrasi set drum (SVG dari includes/drum_kit.php).
//
// Tuts/bagian yang sedang berbunyi menyala dengan warna instrumen lalu memudar.
// Saat bermain lagu, nada atau akor berikutnya diberi tanda tipis.
// Di mode melodi, tuts yang punya bunyi bisa ditekan; di mode akor, muncul
// deretan tombol akor (C, Dm, Em, F, G, Am, Bdim).
// Setiap tuts menampilkan jumlah jari (bila punya gestur kamera) dan, di mode
// keyboard, tombol keyboard laptopnya (lihat keymap.js).
//
// Komponen ini tidak membunyikan apa pun sendiri: tekanan diteruskan sebagai
// bunyi lewat onPress(bunyi)/onRelease, seperti keyboard laptop.

import { codeToMidi, codeToDegree, chordName, instrumentDoOctave, DEGREE_LETTERS } from './music.js';
import { keyLabelForSound } from './keymap.js';

const PIANO_LOW = 48;    // C3
const PIANO_HIGH = 84;   // C6
const BLACK_KEYS = [1, 3, 6, 8, 10];    // posisi tuts hitam dalam satu oktaf
const FLASH_MS = 350;
const OCTAVE_ROWS = [
    { shift: -1, name: 'Rendah' },
    { shift: 0, name: 'Tengah' },
    { shift: 1, name: 'Tinggi' },
];

const isBlack = (midi) => BLACK_KEYS.includes(((midi % 12) + 12) % 12);

export class InstrumentView {
    // container     : elemen tempat visual digambar
    // instrumentData: hasil api/instrument.php
    // onPress(bunyi, atMs), onRelease(atMs): tekanan pad diteruskan sebagai bunyi
    constructor({ container, instrumentData, onPress, onRelease }) {
        this.container = container;
        this.data = instrumentData;
        this.type = instrumentData.instrument.type;
        this.onPress = onPress;
        this.onRelease = onRelease;
        this.transpose = 0;
        this.style = 'notes';
        this.interactive = false;
        this.held = new Set();          // elemen yang menyala karena nada ditahan
        this.timers = new Map();        // elemen -> timer padam
        this.doOctave = instrumentDoOctave(instrumentData.sounds, instrumentData.gestures);
        this.container.style.setProperty('--inst-color', instrumentData.instrument.color);

        // Jumlah jari per bunyi (hanya bunyi yang punya gestur kamera).
        this.countBySound = new Map();
        for (const gesture of instrumentData.gestures) {
            if (!this.countBySound.has(gesture.sound_id)) {
                this.countBySound.set(gesture.sound_id, gesture.finger_count);
            }
        }
    }

    setTranspose(semitones) {
        this.transpose = semitones;
        this.render();
    }

    setStyle(style) {
        this.style = style;
        this.render();
    }

    // Di mode kamera, visual hanya menampilkan; di mode keyboard, bisa ditekan
    // dan tombol keyboard laptop ditampilkan di tuts.
    setInteractive(interactive) {
        this.interactive = interactive;
        this.container.classList.toggle('is-interactive', interactive);
    }

    // ------------------------------------------------------------------
    // Menggambar
    // ------------------------------------------------------------------
    render() {
        this.held.clear();
        this.keys = new Map();          // MIDI -> elemen tuts/sel
        if (this.type === 'drum') {
            this.bindDrum();
            return;
        }
        this.container.replaceChildren();
        if (this.style === 'chords') {
            this.container.appendChild(this.chordButtons());
        }
        this.container.appendChild(this.type === 'piano' ? this.pianoKeyboard() : this.noteRows());
    }

    // Label kecil di tuts: jumlah jari (data-finger) dan tombol keyboard (key-cap).
    label(el, sound) {
        const count = this.countBySound.get(sound.id);
        if (count !== undefined) {
            el.dataset.finger = String(count);
        }
        const key = keyLabelForSound(sound, this.doOctave);
        if (key) {
            const cap = document.createElement('span');
            cap.className = 'key-cap';
            cap.textContent = key;
            el.appendChild(cap);
        }
    }

    // Keyboard C3-C6, digeser sebesar transpose lalu dibulatkan ke tuts putih.
    pianoKeyboard() {
        let low = PIANO_LOW + this.transpose;
        let high = PIANO_HIGH + this.transpose;
        while (isBlack(low)) low--;
        while (isBlack(high)) high++;

        const playable = this.style === 'notes' ? this.playableMap() : new Map();
        const keyboard = document.createElement('div');
        keyboard.className = 'piano';
        const whites = [];
        for (let midi = low; midi <= high; midi++) {
            if (!isBlack(midi)) {
                whites.push(midi);
            }
        }
        const whiteWidth = 100 / whites.length;

        for (let midi = low; midi <= high; midi++) {
            const key = document.createElement('span');
            const black = isBlack(midi);
            key.className = black ? 'piano-key is-black' : 'piano-key';
            if (black) {
                // Tuts hitam berada di batas dua tuts putih.
                const whitesBefore = whites.filter((w) => w < midi).length;
                key.style.left = `calc(${whitesBefore * whiteWidth}% - ${whiteWidth * 0.3}%)`;
                key.style.width = `${whiteWidth * 0.6}%`;
            }
            if (midi % 12 === 0) {
                key.dataset.label = 'C' + (Math.floor(midi / 12) - 1);
            }
            // Mode akor: tuts hanya menampilkan nada akor, tidak diredupkan.
            if (this.style === 'notes') {
                const sound = playable.get(midi);
                if (sound) {
                    this.label(key, sound);
                }
                this.attachPress(key, sound);
            }
            keyboard.appendChild(key);
            this.keys.set(midi, key);
        }
        return keyboard;
    }

    // Strings: tiga baris, satu per oktaf relatif terhadap Do instrumen.
    // Do sangat tinggi (dua oktaf di atas Do) ikut baris "Tinggi".
    noteRows() {
        const wrap = document.createElement('div');
        wrap.className = 'note-rows';
        const items = this.data.sounds
            .map((sound) => ({ sound, midi: codeToMidi(sound.code), degree: this.doOctave === null ? null : codeToDegree(sound.code, this.doOctave) }))
            .filter((item) => item.midi !== null)
            .sort((a, b) => a.midi - b.midi);

        for (const row of OCTAVE_ROWS) {
            const inRow = items.filter(({ degree }) => {
                const shift = degree ? Math.min(Math.max(degree.octaveShift, -1), 1) : 0;
                return shift === row.shift;
            });
            if (inRow.length === 0) {
                continue;
            }
            const line = document.createElement('div');
            line.className = 'note-row';
            const title = document.createElement('span');
            title.className = 'note-row-title';
            title.textContent = row.name;
            line.appendChild(title);
            for (const { sound, midi } of inRow) {
                const cell = document.createElement('span');
                cell.className = 'note-cell';
                const name = document.createElement('strong');
                name.textContent = sound.name;
                const finger = document.createElement('small');
                const count = this.countBySound.get(sound.id);
                finger.textContent = count === undefined ? '' : count + ' jari';
                cell.append(name, finger);
                const key = keyLabelForSound(sound, this.doOctave);
                if (key) {
                    const cap = document.createElement('span');
                    cap.className = 'key-cap';
                    cap.textContent = key;
                    cell.appendChild(cap);
                }
                if (this.style === 'notes') {
                    this.attachPress(cell, sound);
                }
                line.appendChild(cell);
                this.keys.set(midi + this.transpose, cell);
            }
            wrap.appendChild(line);
        }
        return wrap;
    }

    // Deretan tombol akor diatonis dari nada oktaf tengah (Do sampai Si).
    chordButtons() {
        const row = document.createElement('div');
        row.className = 'chord-buttons';
        if (this.doOctave === null) {
            return row;
        }
        const byCode = new Map(this.data.sounds.map((sound) => [sound.code, sound]));
        for (let degree = 1; degree <= 7; degree++) {
            const sound = byCode.get(DEGREE_LETTERS[degree] + this.doOctave);
            if (!sound) {
                continue;
            }
            const button = document.createElement('button');
            button.type = 'button';
            button.className = 'chord-button';
            button.textContent = chordName(degree, this.transpose);
            const count = this.countBySound.get(sound.id);
            const key = keyLabelForSound(sound, this.doOctave);
            button.title = [count !== undefined ? count + ' jari' : '', key ? 'tombol ' + key : ''].filter(Boolean).join(', ');
            if (key) {
                const cap = document.createElement('span');
                cap.className = 'key-cap';
                cap.textContent = key;
                button.appendChild(cap);
            }
            this.attachPress(button, sound);
            row.appendChild(button);
        }
        return row;
    }

    // Drum: SVG sudah ada di halaman; cukup sambungkan bagian-bagiannya.
    bindDrum() {
        if (this.drumBound) {
            return;
        }
        this.drumBound = true;
        const soundByCode = new Map(this.data.sounds.map((sound) => [sound.code, sound]));
        this.container.querySelectorAll('[data-code]').forEach((part) => {
            const sound = soundByCode.get(part.dataset.code);
            const count = sound ? this.countBySound.get(sound.id) : undefined;
            if (count !== undefined) {
                part.dataset.finger = String(count);
            }
            this.attachPress(part, sound);
        });
    }

    // Peta MIDI (setelah transpose) -> bunyi, untuk SEMUA bunyi instrumen.
    playableMap() {
        const map = new Map();
        for (const sound of this.data.sounds) {
            const midi = codeToMidi(sound.code);
            if (midi !== null && !map.has(midi + this.transpose)) {
                map.set(midi + this.transpose, sound);
            }
        }
        return map;
    }

    // Tekan = bunyi tersebut, lepas = diam. Pointer events: mouse dan sentuhan.
    attachPress(el, sound) {
        if (!sound) {
            el.classList.add('is-dim');
            return;
        }
        el.classList.add('is-playable');
        el.addEventListener('pointerdown', (event) => {
            if (!this.interactive) {
                return;
            }
            event.preventDefault();
            // Tangkap pointer supaya "lepas" tetap terdeteksi walau jari bergeser keluar tuts.
            try {
                el.setPointerCapture(event.pointerId);
            } catch (error) {
                // Pointer sudah tidak aktif; tekanan tetap diproses.
            }
            this.pressing = event.pointerId;
            this.onPress(sound, event.timeStamp);
        });
        const up = (event) => {
            if (this.interactive && this.pressing === event.pointerId) {
                this.pressing = null;
                this.onRelease(event.timeStamp);
            }
        };
        el.addEventListener('pointerup', up);
        el.addEventListener('pointercancel', up);
        el.addEventListener('contextmenu', (event) => event.preventDefault());
    }

    // ------------------------------------------------------------------
    // Menyala
    // ------------------------------------------------------------------
    // midis: nada yang berbunyi (sudah ditranspose). hold: menyala sampai release().
    light(midis, hold) {
        for (const midi of midis) {
            const el = this.keys && this.keys.get(midi);
            if (el) {
                this.turnOn(el, hold ? null : FLASH_MS);
            }
        }
    }

    // Drum: nyalakan bagian set drum berdasarkan kode bunyi.
    hitDrum(code) {
        const el = this.container.querySelector(`[data-code="${code}"]`);
        if (el) {
            this.turnOn(el, code === 'CYMBAL' || code === 'CRASH' ? 600 : 200);
        }
    }

    turnOn(el, durationMs) {
        clearTimeout(this.timers.get(el));
        el.classList.remove('is-on');
        void el.getBoundingClientRect();   // ulang animasi bila tuts yang sama
        el.classList.add('is-on');
        if (durationMs === null) {
            this.held.add(el);
        } else {
            this.timers.set(el, setTimeout(() => el.classList.remove('is-on'), durationMs));
        }
    }

    // Lepas nada yang ditahan (strings).
    release() {
        this.held.forEach((el) => el.classList.remove('is-on'));
        this.held.clear();
    }

    // Tanda tipis untuk nada/akor berikutnya. midis: array MIDI, atau kode drum.
    markNext(target) {
        this.container.querySelectorAll('.is-next').forEach((el) => el.classList.remove('is-next'));
        if (target === null) {
            return;
        }
        if (typeof target === 'string') {
            const el = this.container.querySelector(`[data-code="${target}"]`);
            el && el.classList.add('is-next');
            return;
        }
        for (const midi of target) {
            const el = this.keys && this.keys.get(midi);
            el && el.classList.add('is-next');
        }
    }
}
