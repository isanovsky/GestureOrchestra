// Halaman instrumen: pilihan cara membaca jari. Pilihan disimpan di browser
// (sama dengan pengaturan di halaman bermain), lalu teks "cara membentuk" di
// setiap baris ikut berganti. Teksnya sudah disiapkan PHP di data-how-*.

import { loadGestureMode, saveGestureMode } from './hand-tracker.js';

function apply(mode) {
    document.querySelectorAll('[data-gesture-mode]').forEach((btn) => {
        btn.classList.toggle('is-active', btn.dataset.gestureMode === mode);
        btn.setAttribute('aria-pressed', btn.dataset.gestureMode === mode ? 'true' : 'false');
    });
    document.querySelectorAll('[data-gesture-help]').forEach((el) => {
        el.hidden = el.dataset.gestureHelp !== mode;
    });
    document.querySelectorAll('.finger-how').forEach((el) => {
        el.textContent = mode === 'leftPlus5' ? el.dataset.howLeftPlus5 : el.dataset.howSum;
    });
}

document.querySelectorAll('[data-gesture-mode]').forEach((btn) => {
    btn.addEventListener('click', () => {
        saveGestureMode(btn.dataset.gestureMode);
        apply(btn.dataset.gestureMode);
    });
});
apply(loadGestureMode());
