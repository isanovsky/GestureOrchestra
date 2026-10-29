// Deteksi tangan lewat kamera dan debounce. Cara menghitung jari ada di
// finger-count.js (fungsi murni, bisa diuji tanpa kamera).
//
// Sumber: sebagian diadaptasi dari "Gesture Synth Weld" karya rainbow686
// (https://github.com/rainbow686/gesture-synth-weld), lisensi MIT.
// Yang diadaptasi: cara memuat HandLandmarker (GPU lalu cadangan CPU,
// numHands 2, mode VIDEO, ambang keyakinan 0.5) dari src/handTracker.ts,
// daftar sambungan kerangka tangan HAND_CONNECTIONS, dan pola loop deteksi
// per frame video. Lihat THIRD_PARTY_NOTICES.md.
//
// Modul lain cukup memakai callback onGestureChange(jumlahJari, waktu),
// tanpa perlu tahu apa pun tentang MediaPipe.

import { FINGER_SETTINGS, GesturePipeline, detectionsFromResult } from './finger-count.js';

// Versi di-pin sama dengan mediapipe-version.json di repo referensi.
// File .wasm MediaPipe lebih dari 10 MB (batas InfinityFree), jadi tidak
// disimpan di repo ini, melainkan dimuat dari CDN.
const MEDIAPIPE_VERSION = '1.0.1';
const VISION_BUNDLE_URL = `https://cdn.jsdelivr.net/npm/@mediapipe/tasks-vision@${MEDIAPIPE_VERSION}/vision_bundle.mjs`;
const WASM_BASE_URL = `https://cdn.jsdelivr.net/npm/@mediapipe/tasks-vision@${MEDIAPIPE_VERSION}/wasm`;
const MODEL_URL = 'https://storage.googleapis.com/mediapipe-models/hand_landmarker/hand_landmarker/float16/1/hand_landmarker.task';

// Garis kerangka tangan (pasangan titik). Dari gesture-synth-weld, src/handTracker.ts.
export const HAND_CONNECTIONS = [
    [0, 1], [1, 2], [2, 3], [3, 4],
    [0, 5], [5, 6], [6, 7], [7, 8],
    [0, 9], [9, 10], [10, 11], [11, 12],
    [0, 13], [13, 14], [14, 15], [15, 16],
    [0, 17], [17, 18], [18, 19], [19, 20],
    [5, 9], [9, 13], [13, 17],
];

// Ujung jari, urutan sama dengan status jari: ibu jari, telunjuk, tengah, manis, kelingking.
const FINGER_TIPS = [4, 8, 12, 16, 20];

// Cara membaca gestur yang dipilih pemain, disimpan di localStorage browser.
//   'sum'       : jumlah jari kedua tangan
//   'leftPlus5' : tangan kanan 1 sampai 5, tangan kiri terbuka = +5
export const GESTURE_MODES = {
    sum: 'Jumlah kedua tangan',
    leftPlus5: 'Tangan kiri +5',
};
const GESTURE_MODE_KEY = 'go_gesture_mode';

export function loadGestureMode() {
    try {
        const value = localStorage.getItem(GESTURE_MODE_KEY);
        return value in GESTURE_MODES ? value : 'sum';
    } catch (error) {
        return 'sum';   // localStorage bisa diblokir (mode privat); pakai bawaan
    }
}

export function saveGestureMode(mode) {
    try {
        localStorage.setItem(GESTURE_MODE_KEY, mode);
    } catch (error) {
        // Tidak tersimpan, tetapi tetap berlaku sampai halaman ditutup.
    }
}

// Cara membentuk sebuah hitungan, untuk contekan jari. Teks yang sama dibuat
// PHP di includes/content.php (finger_how_to) untuk halaman instrumen; ubah keduanya bersamaan.
export function fingerHowTo(count, mode) {
    if (count === 0) return 'kepalan';
    if (mode === 'leftPlus5') {
        return count <= 5 ? `kanan ${count}` : `kiri buka + kanan ${count - 5}`;
    }
    return count <= 5 ? `${count} jari` : `5 + ${count - 5}, dua tangan`;
}

// Nama jalur hitung (lihat PIPELINES di finger-count.js) untuk sebuah cara membaca gestur.
export function pipelineForMode(mode) {
    return mode === 'leftPlus5' ? 'leftPlus5' : 'standard';
}

// Debounce: jumlah jari baru dianggap berubah setelah nilai itu terlihat terus-
// menerus selama minimal stableMs DAN minimal minFrames frame berturut-turut.
// Tanpa ini, satu frame yang salah baca langsung membunyikan nada acak.
export class GestureDebouncer {
    constructor(stableMs = FINGER_SETTINGS.debounceMs, minFrames = FINGER_SETTINGS.debounceMinFrames) {
        this.stableMs = stableMs;
        this.minFrames = minFrames;
        this.reset();
    }

    reset() {
        this.current = 0;          // nilai stabil yang sedang berlaku
        this.candidate = 0;        // nilai baru yang sedang "diuji"
        this.candidateSince = 0;   // kapan kandidat pertama kali terlihat
        this.candidateFrames = 0;  // sudah berapa frame kandidat terlihat
    }

    // Masukkan nilai mentah satu frame beserta waktunya (ms).
    // Mengembalikan { value, at } saat nilai stabil berubah; selain itu null.
    // "at" = waktu kandidat PERTAMA terlihat, sehingga jeda debounce tidak
    // membuat pemain dinilai terlambat.
    push(raw, nowMs) {
        if (raw === this.current) {
            // Kembali ke nilai lama: kedipan sesaat dibatalkan.
            this.candidate = raw;
            this.candidateFrames = 0;
            return null;
        }
        if (raw !== this.candidate || this.candidateFrames === 0) {
            // Nilai baru mulai diuji dari frame ini.
            this.candidate = raw;
            this.candidateSince = nowMs;
            this.candidateFrames = 0;
        }
        this.candidateFrames++;
        if (nowMs - this.candidateSince >= this.stableMs && this.candidateFrames >= this.minFrames) {
            this.current = raw;
            this.candidateFrames = 0;
            return { value: raw, at: this.candidateSince };
        }
        return null;
    }
}

// Mode 'auto': bila GPU ternyata lambat (misalnya browser memakai GPU tiruan
// berbasis software karena driver kartu grafis diblokir), pindah ke CPU.
// Diukur dari median lama deteksi. Beberapa frame pertama dilewati karena GPU
// sungguhan pun lambat di awal (menyiapkan shader). Keputusan diambil setelah
// cukup sampel atau setelah 2,5 detik, mana yang lebih dulu, supaya kamera tidak
// tersendat lama di GPU tiruan yang hanya 1 sampai 3 fps.
const GPU_SKIP_FRAMES = 3;
const GPU_MIN_SAMPLES = 5;
const GPU_ENOUGH_SAMPLES = 30;
const GPU_CHECK_MAX_MS = 2500;
const GPU_SLOW_MS = 45;

// Model dimuat sekali per halaman untuk setiap pilihan prosesor.
// preference 'auto' = coba GPU dulu, bila gagal pakai CPU; 'CPU' = langsung CPU.
// Hasil: { landmarker, delegate } dengan delegate = prosesor yang benar-benar dipakai.
const landmarkerPromises = new Map();

function loadLandmarker(preference = 'auto') {
    if (!landmarkerPromises.has(preference)) {
        const promise = (async () => {
            const { FilesetResolver, HandLandmarker } = await import(VISION_BUNDLE_URL);
            const vision = await FilesetResolver.forVisionTasks(WASM_BASE_URL);
            const options = (delegate) => ({
                baseOptions: { modelAssetPath: MODEL_URL, delegate },
                runningMode: 'VIDEO',
                numHands: 2,
                minHandDetectionConfidence: 0.5,
                minHandPresenceConfidence: 0.5,
                minTrackingConfidence: 0.5,
            });
            if (preference === 'auto') {
                // GPU lebih ringan untuk prosesor; bila tidak tersedia, pakai CPU.
                try {
                    return { landmarker: await HandLandmarker.createFromOptions(vision, options('GPU')), delegate: 'GPU' };
                } catch (gpuError) {
                    // lanjut ke CPU
                }
            }
            return { landmarker: await HandLandmarker.createFromOptions(vision, options('CPU')), delegate: 'CPU' };
        })();
        // Bila gagal (misalnya internet putus), izinkan mencoba lagi.
        promise.catch(() => {
            landmarkerPromises.delete(preference);
        });
        landmarkerPromises.set(preference, promise);
    }
    return landmarkerPromises.get(preference);
}

// Ubah error kamera dari browser menjadi pesan yang bisa dipahami pemain.
function cameraErrorMessage(error) {
    if (!window.isSecureContext) {
        return 'Kamera hanya bisa dipakai lewat https:// atau http://localhost.';
    }
    switch (error && error.name) {
        case 'NotAllowedError':
            return 'Izin kamera ditolak. Klik ikon kamera di bilah alamat, izinkan, lalu coba lagi.';
        case 'NotFoundError':
            return 'Kamera tidak ditemukan. Pakai mode keyboard saja.';
        case 'NotReadableError':
            return 'Kamera sedang dipakai aplikasi lain. Tutup aplikasi itu, lalu coba lagi.';
        default:
            return 'Kamera tidak bisa dinyalakan. Pakai mode keyboard saja.';
    }
}

export class HandTracker {
    // video, canvas : elemen <video> dan <canvas> (keduanya dicerminkan lewat CSS)
    // stableMs      : lama debounce dalam ms
    // pipeline      : jalur hitung yang menentukan gestur (kunci PIPELINES di finger-count.js);
    //                 bawaan mengikuti pilihan pemain di localStorage
    // compare       : jalur tambahan yang ikut dihitung untuk perbandingan (halaman uji kamera)
    // delegate      : 'auto' (GPU, cadangan CPU) atau 'CPU'
    // onGestureChange(fingerCount, atMs) : dipanggil saat jumlah jari stabil berubah
    // onFrame(info) : opsional, data per frame untuk tampilan diagnostik
    // onStatus(teks): opsional, untuk menampilkan tahap memuat
    // onWarning(teks): opsional, peringatan posisi tangan ('' bila posisi sudah baik)
    constructor({ video, canvas, stableMs = FINGER_SETTINGS.debounceMs, pipeline, compare = [], delegate = 'auto', onGestureChange, onFrame, onStatus, onWarning }) {
        this.video = video;
        this.canvas = canvas;
        this.ctx = canvas ? canvas.getContext('2d') : null;
        this.debouncer = new GestureDebouncer(stableMs);
        this.pipeline = new GesturePipeline(pipeline || pipelineForMode(loadGestureMode()));
        this.compare = compare.map((name) => new GesturePipeline(name));
        this.delegatePreference = delegate;
        this.delegate = null;
        this.onGestureChange = onGestureChange || (() => {});
        this.onFrame = onFrame || (() => {});
        this.onStatus = onStatus || (() => {});
        this.onWarning = onWarning || (() => {});
        this.lastWarning = '';
        this.landmarker = null;
        this.stream = null;
        this.running = false;
        this.lastVideoTime = -1;
        this.fps = 0;
        this.detectMs = 0;
        this.lastFrameAt = 0;
        this.gpuSamples = [];          // lama deteksi (ms) per frame di GPU, untuk cek kecepatan
        this.delegateSince = 0;
        this.switching = false;
        this.loop = this.loop.bind(this);
    }

    // Ganti jalur hitung (misalnya pemain memilih mode "Tangan kiri +5").
    setPipeline(name) {
        this.pipeline = new GesturePipeline(name);
    }

    // Ganti prosesor (GPU/CPU) saat kamera menyala; model dimuat ulang.
    async setDelegate(preference) {
        this.delegatePreference = preference;
        if (!this.running) {
            return;
        }
        this.onStatus('Memuat ulang model...');
        const loaded = await loadLandmarker(preference);
        this.landmarker = loaded.landmarker;
        this.delegate = loaded.delegate;
        this.fps = 0;
        this.detectMs = 0;
        this.gpuSamples = [];
        this.delegateSince = performance.now();
        this.switching = false;
        this.onStatus('Kamera siap. Angkat tanganmu!');
    }

    // Dipanggil setiap frame: di mode 'auto', GPU yang lambat diganti CPU sekali saja.
    checkGpuSpeed(detectMs, now) {
        if (this.delegatePreference !== 'auto' || this.delegate !== 'GPU' || this.switching) {
            return;
        }
        this.gpuSamples.push(detectMs);
        const samples = this.gpuSamples.slice(GPU_SKIP_FRAMES);
        const enough = samples.length >= GPU_ENOUGH_SAMPLES
            || (samples.length >= GPU_MIN_SAMPLES && now - this.delegateSince >= GPU_CHECK_MAX_MS);
        if (!enough) {
            return;
        }
        const sorted = [...samples].sort((a, b) => a - b);
        const median = sorted[Math.floor(sorted.length / 2)];
        if (median < GPU_SLOW_MS) {
            this.switching = true;   // GPU cukup cepat: tidak perlu dicek lagi
            return;
        }
        this.switching = true;
        loadLandmarker('CPU').then((loaded) => {
            this.landmarker = loaded.landmarker;
            this.delegate = 'CPU';
            this.detectMs = 0;
            this.fps = 0;
            this.onStatus(`GPU lambat (${median.toFixed(0)} ms per frame), pindah ke CPU.`);
        }).catch(() => {
            // CPU juga gagal dimuat: tetap pakai GPU.
        });
    }

    // Nyalakan kamera dan muat model. Melempar Error berisi pesan Bahasa Indonesia.
    async start() {
        if (this.running) {
            return;
        }
        if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
            throw new Error(cameraErrorMessage(null));
        }

        this.onStatus('Meminta izin kamera...');
        try {
            this.stream = await navigator.mediaDevices.getUserMedia({
                video: { width: { ideal: 640 }, height: { ideal: 480 }, facingMode: 'user' },
                audio: false,
            });
        } catch (error) {
            throw new Error(cameraErrorMessage(error));
        }
        this.video.srcObject = this.stream;
        await this.video.play();

        this.onStatus('Memuat model pendeteksi tangan (sekitar 20 MB, hanya sekali)...');
        try {
            const loaded = await loadLandmarker(this.delegatePreference);
            this.landmarker = loaded.landmarker;
            this.delegate = loaded.delegate;
            this.gpuSamples = [];
            this.delegateSince = performance.now();
            this.switching = false;
        } catch (error) {
            this.stop();
            throw new Error('Model pendeteksi tangan gagal dimuat. Periksa koneksi internet, lalu coba lagi.');
        }

        this.debouncer.reset();
        this.pipeline.reset();
        this.compare.forEach((pipeline) => pipeline.reset());
        this.running = true;
        this.lastVideoTime = -1;
        this.onStatus('Kamera siap. Angkat tanganmu!');
        requestAnimationFrame(this.loop);
    }

    stop() {
        this.running = false;
        if (this.stream) {
            this.stream.getTracks().forEach((track) => track.stop());
            this.stream = null;
        }
        if (this.video) {
            this.video.srcObject = null;
        }
        if (this.ctx) {
            this.ctx.clearRect(0, 0, this.canvas.width, this.canvas.height);
        }
        // Tangan dianggap turun supaya nada yang ditahan berhenti.
        if (this.debouncer.current !== 0) {
            this.debouncer.reset();
            this.onGestureChange(0, performance.now());
        }
    }

    loop() {
        if (!this.running) {
            return;
        }
        const video = this.video;

        // Deteksi hanya bila video punya frame baru.
        if (video.readyState >= 2 && video.currentTime !== this.lastVideoTime) {
            this.lastVideoTime = video.currentTime;
            const now = performance.now();
            const result = this.landmarker.detectForVideo(video, now);
            const detectMs = performance.now() - now;

            // FPS deteksi dan lama deteksi, dihaluskan supaya angkanya tidak meloncat.
            if (this.lastFrameAt > 0) {
                const instant = 1000 / Math.max(1, now - this.lastFrameAt);
                this.fps = this.fps === 0 ? instant : this.fps * 0.9 + instant * 0.1;
            }
            this.detectMs = this.detectMs === 0 ? detectMs : this.detectMs * 0.9 + detectMs * 0.1;
            this.lastFrameAt = now;
            this.checkGpuSpeed(detectMs, now);

            const detections = detectionsFromResult(result);
            this.reportPosition(detections);
            const { total, hands } = this.pipeline.process(detections, now);
            const change = this.debouncer.push(total, now);
            if (change !== null) {
                this.onGestureChange(change.value, change.at);
            }

            const totals = {};
            for (const pipeline of this.compare) {
                totals[pipeline.name] = pipeline.process(detections, now).total;
            }

            this.draw(detections, hands);
            this.onFrame({
                now,
                result,
                detections,
                hands,
                rawTotal: total,
                stableTotal: this.debouncer.current,
                totals,
                fps: this.fps,
                detectMs: this.detectMs,
                delegate: this.delegate,
            });
        }

        requestAnimationFrame(this.loop);
    }

    // Peringatan ramah bila tangan terpotong atau terlalu ke bawah. Frame seperti
    // itu tidak dipakai menghitung jari tangan tersebut (lihat finger-count.js).
    reportPosition(detections) {
        let text = '';
        if (detections.some((d) => d.problem === 'cut')) {
            text = 'Ujung jari keluar dari kamera. Geser tangan ke tengah, di dalam garis putus-putus.';
        } else if (detections.some((d) => d.problem === 'low')) {
            text = 'Pergelangan terlalu dekat ke bawah. Angkat tangan sedikit, di atas garis oranye.';
        }
        if (text !== this.lastWarning) {
            this.lastWarning = text;
            this.onWarning(text);
        }
    }

    // Garis bantu posisi: kotak aman di dalam tepi gambar dan garis batas
    // pergelangan di bawah. Tanpa teks, karena kanvas dicerminkan.
    drawGuide() {
        const { canvas, ctx } = this;
        const margin = 0.03;
        ctx.save();
        ctx.setLineDash([10, 8]);
        ctx.lineWidth = 3;
        ctx.strokeStyle = 'rgba(255, 250, 241, 0.75)';
        ctx.strokeRect(canvas.width * margin, canvas.height * margin, canvas.width * (1 - 2 * margin), canvas.height * (1 - 2 * margin));
        const y = canvas.height * FINGER_SETTINGS.wristBottomMax;
        ctx.strokeStyle = 'rgba(233, 162, 59, 0.9)';
        ctx.beginPath();
        ctx.moveTo(canvas.width * margin, y);
        ctx.lineTo(canvas.width * (1 - margin), y);
        ctx.stroke();
        ctx.restore();
    }

    // Gambar kerangka tangan di atas video, bergaya spidol: garis krem tebal lalu garis cokelat.
    // Ujung jari yang dihitung terangkat diberi lingkaran besar berwarna mustard.
    // Tangan yang posisinya kurang pas digambar dengan garis merah tomat.
    draw(detections, hands) {
        if (!this.ctx) {
            return;
        }
        const { canvas, ctx, video } = this;
        if (canvas.width !== video.videoWidth || canvas.height !== video.videoHeight) {
            canvas.width = video.videoWidth;
            canvas.height = video.videoHeight;
        }
        ctx.clearRect(0, 0, canvas.width, canvas.height);
        this.drawGuide();
        ctx.lineCap = 'round';
        ctx.lineJoin = 'round';

        for (const detection of detections) {
            const p = detection.points.map((pt) => ({ x: pt.x * canvas.width, y: pt.y * canvas.height }));
            const hand = hands.find((h) => !h.held && Math.abs(h.screenX - detection.screenX) < 1e-6);
            const states = hand ? hand.states : [];

            const lineColor = detection.problem === 'cut' ? '#B23A17' : '#3B2A1E';
            for (const [width, color] of [[9, '#FFFAF1'], [4, lineColor]]) {
                ctx.strokeStyle = color;
                ctx.lineWidth = width;
                ctx.beginPath();
                for (const [a, b] of HAND_CONNECTIONS) {
                    ctx.moveTo(p[a].x, p[a].y);
                    ctx.lineTo(p[b].x, p[b].y);
                }
                ctx.stroke();
            }

            ctx.strokeStyle = '#3B2A1E';
            ctx.lineWidth = 2;
            p.forEach((point, i) => {
                const finger = FINGER_TIPS.indexOf(i);
                const up = finger >= 0 && states[finger];
                ctx.fillStyle = up ? '#E9A23B' : '#FFFAF1';
                ctx.beginPath();
                ctx.arc(point.x, point.y, up ? 10 : 5, 0, Math.PI * 2);
                ctx.fill();
                ctx.stroke();
            });
        }
    }
}
