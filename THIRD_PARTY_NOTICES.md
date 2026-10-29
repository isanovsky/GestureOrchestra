# Pemberitahuan Pihak Ketiga

GestureOrchestra memakai atau mengadaptasi karya pihak lain berikut. Semua
ilustrasi SVG di `assets/img/` dan `includes/icons.php` adalah gambar orisinal
kelompok, bukan aset pihak ketiga.

## 1. Gesture Synth Weld (kode diadaptasi)

- Sumber asli: https://github.com/rainbow686/gesture-synth-weld (karya rainbow686)
- Salinan yang dipelajari: https://github.com/isanovsky/gesture-synth
- Lisensi: MIT (teks lengkap di bawah)

Bagian yang diadaptasi (ditulis ulang ke JavaScript vanilla, tanpa React,
TypeScript, Vite, atau Tone.js), dan file yang memakainya:

| File di proyek ini | Yang diadaptasi | Dari file asli |
|---|---|---|
| `assets/js/hand-tracker.js` | Cara memuat HandLandmarker (GPU lalu CPU, `numHands: 2`, mode VIDEO, ambang 0.5), daftar `HAND_CONNECTIONS`, pola loop deteksi per frame | `src/handTracker.ts`, `src/input/cameraSource.ts` |
| `assets/js/keyboard-input.js` | Prinsip keyboard sebagai sumber gestur yang setara dengan kamera, tahan untuk berbunyi dan lepas untuk diam | `src/input/keyboardSource.ts` |
| `assets/js/audio-engine.js` | Pola master gain dengan perubahan volume yang dihaluskan dan fungsi hentikan semua bunyi | `src/audioEngine.ts` |
| `assets/js/hand-tracker.js` | Versi MediaPipe yang di-pin (1.0.1) | `mediapipe-version.json` |

Yang tidak dibawa: iklan, AdSense, analytics, tautan versi Pro, kartu afiliasi,
UTM, dan semua kode perekaman.

Teks lisensi MIT dari repo asli:

```
MIT License

Copyright (c) 2026 Gesture Synth Weld

Permission is hereby granted, free of charge, to any person obtaining a copy
of this software and associated documentation files (the "Software"), to deal
in the Software without restriction, including without limitation the rights
to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
copies of the Software, and to permit persons to whom the Software is
furnished to do so, subject to the following conditions:

The above copyright notice and this permission notice shall be included in all
copies or substantial portions of the Software.

THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE
SOFTWARE.
```

## 2. Piano oleh Rangga-Ari (ide dan pola)

- Sumber: https://github.com/Rangga-Ari/piano
- Yang dipakai: pola PDO dan struktur halaman PHP native, ide mengambil
  pemetaan gestur ke bunyi dari database, dan rumus hitung jari berbasis jarak
  (ujung jari dibanding sendi terhadap pergelangan, ibu jari dibanding pangkal
  telunjuk) di `assets/js/finger-count.js` (cara hitung lama,
  `legacyFingerStates`, dipertahankan sebagai pembanding). Kode tidak disalin utuh.

## 3. Cadenza oleh Piskocis (referensi konsep saja, tanpa kode)

- Sumber: https://github.com/Piskocis/Cadenza
- Lisensi: GPL-3.0

Mode Arranger (`arranger.php`, `assets/js/arranger*.js`) memakai konsep keyboard arranger yang
juga ada di Cadenza: bagian Intro, Main A/B, Fill, Ending, dan iringan yang mengikuti akor yang
dipilih pemain. **Tidak ada kode, data pola, atau aset yang diambil dari Cadenza.** Cadenza adalah
aplikasi desktop C++/JUCE; seluruh implementasi di proyek ini ditulis sendiri dalam PHP dan
JavaScript, sehingga lisensi GPL-3 Cadenza tidak berlaku untuk proyek ini.

## 4. Dimuat dari CDN (tidak disimpan di repo)

| Komponen | Versi | Lisensi | Dimuat dari |
|---|---|---|---|
| MediaPipe Tasks Vision (`@mediapipe/tasks-vision`) | 1.0.1 | Apache License 2.0 | cdn.jsdelivr.net |
| Model `hand_landmarker.task` (float16/1) | 1 | Apache License 2.0 | storage.googleapis.com |
| Font Fraunces | - | SIL Open Font License 1.1 | Google Fonts |
| Font Atkinson Hyperlegible | - | SIL Open Font License 1.1 | Google Fonts |
