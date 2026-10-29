// Cadangan tanpa kamera: keyboard laptop. Peta tombol ada di keymap.js.
//
// Prinsip diadaptasi dari src/input/keyboardSource.ts di "Gesture Synth Weld"
// karya rainbow686 (MIT): tekan untuk berbunyi, lepas untuk diam, dan masukan
// keyboard masuk ke permainan lewat callback yang sama dengan kamera.
// Lihat THIRD_PARTY_NOTICES.md.
//
// - Piano dan strings: tombol memilih BUNYI langsung (onSound), sehingga nada
//   yang tidak punya gestur kamera (misalnya oktaf rendah dan tinggi) tetap
//   bisa dimainkan. Di mode akor, bunyi itu menjadi nada dasar akor.
// - Drum: tombol 1 sampai 9 = jumlah jari (onGestureChange), seperti kamera.
// Pad sentuh di layar ada di instrument-view.js.

import { DRUM_KEYS, NOTE_KEYS } from './keymap.js';

export class KeyboardInput {
    // onGestureChange(fingerCount, atMs): untuk drum (sama dengan HandTracker).
    // onSound(sound | null, atMs, keyLabel): untuk instrumen bernada; null = lepas.
    constructor({ onGestureChange, onSound }) {
        this.onGestureChange = onGestureChange;
        this.onSound = onSound || (() => {});
        this.enabled = false;
        this.noteKeys = null;   // Map event.code -> bunyi; null = drum
        this.heldCode = null;   // event.code tombol yang sedang ditahan
        this.onKeyDown = this.onKeyDown.bind(this);
        this.onKeyUp = this.onKeyUp.bind(this);
        window.addEventListener('keydown', this.onKeyDown);
        window.addEventListener('keyup', this.onKeyUp);
        // Jendela kehilangan fokus saat tombol ditahan: anggap dilepas.
        window.addEventListener('blur', () => this.release());
    }

    // noteKeys: hasil buildNoteKeyMap() untuk piano/strings, atau null untuk drum.
    setNoteKeys(noteKeys) {
        this.release();
        this.noteKeys = noteKeys;
    }

    // Saat dinonaktifkan (pemain beralih ke kamera), nada yang ditahan dilepas.
    setEnabled(enabled) {
        if (!enabled) {
            this.release();
        }
        this.enabled = enabled;
    }

    release(atMs = performance.now()) {
        if (this.heldCode === null) {
            return;
        }
        this.heldCode = null;
        if (!this.enabled) {
            return;
        }
        if (this.noteKeys) {
            this.onSound(null, atMs, null);
        } else {
            this.onGestureChange(0, atMs);
        }
    }

    onKeyDown(event) {
        // Shortcut browser (Ctrl, Alt, Cmd) dan tombol yang ditahan (auto-repeat) diabaikan.
        if (!this.enabled || event.repeat || event.ctrlKey || event.metaKey || event.altKey) {
            return;
        }
        // Jangan ganggu pemain yang sedang mengetik di kolom isian (misalnya nickname).
        if (event.target.closest && event.target.closest('input, textarea, select')) {
            return;
        }
        // event.timeStamp memakai jam yang sama dengan performance.now().
        if (this.noteKeys) {
            const sound = this.noteKeys.get(event.code);
            if (!sound) {
                return;
            }
            event.preventDefault();
            this.heldCode = event.code;
            this.onSound(sound, event.timeStamp, NOTE_KEYS.find((k) => k.code === event.code).label);
            return;
        }
        const key = DRUM_KEYS.find((k) => k.code === event.code);
        if (key) {
            event.preventDefault();
            this.heldCode = event.code;
            this.onGestureChange(key.count, event.timeStamp);
        }
    }

    onKeyUp(event) {
        // Hanya tombol yang terakhir ditekan yang menghentikan nada.
        if (event.code === this.heldCode) {
            this.release(event.timeStamp);
        }
    }
}
