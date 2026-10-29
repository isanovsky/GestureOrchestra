// Halaman uji kamera (camera-test.php): jumlah jari per tangan, diagnostik,
// dan tes akurasi terpandu yang menilai beberapa cara hitung pada frame yang sama.

import { HandTracker, loadGestureMode, pipelineForMode } from './hand-tracker.js';
import { FINGER_SETTINGS, GesturePipeline, PIPELINES, excludedForCounting } from './finger-count.js';

const $ = (id) => document.getElementById(id);
const FINGER_NAMES = ['Ibu jari', 'Telunjuk', 'Tengah', 'Manis', 'Kelingking'];
const COMPARE = ['standard', 'thumbAngle', 'leftPlus5', 'legacy'];

// Tes terpandu: setiap hitungan 2 detik bersiap lalu 3 detik tahan. Yang
// dinilai hanya 2 detik TERAKHIR (detik pertama = waktu tangan berpindah).
// Urutan naik 0 sampai 10, lalu turun 9 sampai 0, supaya perpindahan turun
// (misalnya 5 ke 4) juga teruji.
const PREP_MS = 2000;
const HOLD_MS = 3000;
const MEASURE_LAST_MS = 2000;
const STEPS = [
    ...Array.from({ length: 11 }, (_, i) => ({ target: i, direction: 'naik' })),
    ...Array.from({ length: 10 }, (_, i) => ({ target: 9 - i, direction: 'turun' })),
];

let test = null;   // status tes yang sedang berjalan, atau null

const tracker = new HandTracker({
    video: $('cam'),
    canvas: $('cam-overlay'),
    pipeline: pipelineForMode(loadGestureMode()),
    compare: COMPARE,
    onStatus: (text) => {
        $('status').textContent = text;
    },
    onWarning: (text) => {
        $('position-warning').textContent = text;
        $('position-warning').hidden = text === '';
    },
    onGestureChange: (count) => {
        const el = $('stable-total');
        el.textContent = count;
        el.classList.remove('is-pulse');
        void el.offsetWidth;
        el.classList.add('is-pulse');
    },
    onFrame: (info) => {
        $('raw-total').textContent = info.rawTotal;
        $('perf').textContent = `Prosesor: ${info.delegate || '-'}, ${info.fps.toFixed(0)} fps, deteksi ${info.detectMs.toFixed(0)} ms per frame`;
        renderHands(info);
        if (test) {
            recordFrame(info);
        }
    },
});
$('pipeline').value = tracker.pipeline.name;

// ------------------------------------------------------------------
// Kartu per tangan: posisi, label MediaPipe, jumlah, lima indikator jari.
// ------------------------------------------------------------------
function renderHands({ detections, hands }) {
    const list = $('hand-list');
    list.replaceChildren();
    if (hands.length === 0) {
        const item = document.createElement('li');
        item.className = 'muted';
        item.textContent = 'Belum ada tangan terlihat.';
        list.appendChild(item);
        return;
    }
    for (const hand of hands) {
        const detection = detections.find((d) => Math.abs(d.screenX - hand.screenX) < 1e-6);
        const item = document.createElement('li');
        item.className = 'hand-card' + (hand.held ? ' is-held' : '');

        const head = document.createElement('p');
        const side = hand.screenX < 0.5 ? 'kiri' : 'kanan';
        const strong = document.createElement('strong');
        strong.textContent = `Sisi ${side} layar: ${hand.count} jari`;
        head.append(strong);
        if (hand.held) {
            head.append(' (ditahan, tangan hilang sesaat)');
        } else if (detection && detection.label) {
            head.append(` | label MediaPipe: ${detection.label} ${(detection.score * 100).toFixed(0)}%`);
        }

        const fingers = document.createElement('div');
        fingers.className = 'finger-dots';
        hand.states.forEach((up, i) => {
            const dot = document.createElement('span');
            dot.className = 'finger-dot' + (up ? ' is-up' : '');
            dot.title = FINGER_NAMES[i] + (up ? ': terangkat' : ': ditekuk');
            dot.textContent = FINGER_NAMES[i].slice(0, 1);
            fingers.appendChild(dot);
        });
        item.append(head, fingers);

        // Sudut tiap jari (derajat, 180 = lurus) dari worldLandmarks, untuk menyetel ambang di finger-count.js.
        if (hand.metrics) {
            const m = hand.metrics;
            const raw = document.createElement('p');
            raw.className = 'muted small finger-angles';
            raw.textContent = FINGER_NAMES.map((name, i) => `${name} ${m.angles[i].toFixed(0)}`).join(', ')
                + `. Jarak ibu jari ke kelingking: ${m.thumbSpread.toFixed(2)} lebar telapak.`;
            item.appendChild(raw);
        }
        if (detection && detection.problem) {
            const warn = document.createElement('p');
            warn.className = 'small hand-warning';
            warn.textContent = detection.problem === 'cut'
                ? 'Ujung jari keluar dari kamera: tangan ini tidak dihitung.'
                : 'Pergelangan terlalu ke bawah (tetap dihitung).';
            item.appendChild(warn);
        }
        list.appendChild(item);
    }
}

// ------------------------------------------------------------------
// Kontrol
// ------------------------------------------------------------------
$('btn-camera').addEventListener('click', async () => {
    const button = $('btn-camera');
    button.disabled = true;
    try {
        await tracker.start();
        $('camera-gate').hidden = true;
        $('btn-test').disabled = false;
        $('test-status').textContent = 'Siap. Tes berlangsung sekitar 105 detik (naik lalu turun).';
    } catch (error) {
        $('status').textContent = error.message;
        button.disabled = false;
    }
});

$('pipeline').addEventListener('change', (event) => {
    tracker.setPipeline(event.target.value);
});

$('delegate').addEventListener('change', async (event) => {
    try {
        await tracker.setDelegate(event.target.value);
    } catch (error) {
        $('perf').textContent = 'Prosesor itu tidak bisa dipakai di perangkat ini.';
    }
});

// Ubah lama debounce secara langsung supaya efeknya bisa dibandingkan.
$('stable-ms').addEventListener('input', (event) => {
    const value = Number(event.target.value);
    tracker.debouncer.stableMs = value;
    // 0 ms berarti tanpa debounce: cukup satu frame.
    tracker.debouncer.minFrames = value === 0 ? 1 : FINGER_SETTINGS.debounceMinFrames;
    $('stable-ms-value').textContent = value;
});

// ------------------------------------------------------------------
// Tes akurasi terpandu
// ------------------------------------------------------------------
function poseText(target) {
    if (target === 0) return 'Kepalkan tangan kanan';
    if (target <= 5) return `Tangan kanan ${target} jari, tangan kiri turun`;
    if (target === 10) return 'Kedua tangan terbuka';
    return `Tangan kiri terbuka + tangan kanan ${target - 5} jari`;
}

function expectedHands(target) {
    return target <= 5 ? 1 : 2;
}

function emptyRow() {
    return { frames: 0, judged: 0, badPosition: 0, ok: Object.fromEntries(COMPARE.map((n) => [n, 0])), missingHand: 0, labels: { left: [], right: [] } };
}

$('btn-test').addEventListener('click', () => {
    // Jalur pembanding dimulai dari nol supaya hysteresis dan toleransi tidak membawa sisa sebelumnya.
    tracker.compare = COMPARE.map((name) => new GesturePipeline(name));
    test = {
        startedAt: performance.now(),
        rows: STEPS.map(emptyRow),
        frames: [],
        delegate: tracker.delegate,
        palmFacing: $('palm-facing').value,
        frameWidth: $('cam').videoWidth,
        frameHeight: $('cam').videoHeight,
    };
    $('btn-test').disabled = true;
    $('palm-facing').disabled = true;
    $('btn-test-stop').hidden = false;
    $('guide-overlay').hidden = false;
    $('test-result').hidden = true;
    $('test-status').textContent = 'Tes berjalan...';
});

$('btn-test-stop').addEventListener('click', () => finishTest(true));

function round(value, digits) {
    const k = 10 ** digits;
    return Math.round(value * k) / k;
}

function recordFrame(info) {
    const elapsed = info.now - test.startedAt;
    const stepIndex = Math.floor(elapsed / (PREP_MS + HOLD_MS));
    if (stepIndex >= STEPS.length) {
        finishTest(false);
        return;
    }
    const { target, direction } = STEPS[stepIndex];
    const inStep = elapsed - stepIndex * (PREP_MS + HOLD_MS);
    const holdT = inStep >= PREP_MS ? inStep - PREP_MS : null;
    const judged = holdT !== null && holdT >= HOLD_MS - MEASURE_LAST_MS;

    // Aba-aba di atas video.
    $('guide-target').textContent = target;
    $('guide-pose').textContent = poseText(target) + (direction === 'turun' ? ' (urutan turun)' : '');
    $('guide-phase').textContent = holdT === null ? `Bersiap ${Math.ceil((PREP_MS - inStep) / 1000)}` : (judged ? 'Tahan... (dinilai)' : 'Tahan...');
    $('guide-bar').style.width = `${holdT === null ? 0 : (holdT / HOLD_MS) * 100}%`;
    $('guide-overlay').classList.toggle('is-holding', holdT !== null);

    // Rekaman versi 2: titik gambar dan titik dunia per frame, dibulatkan supaya
    // file tidak terlalu besar, plus langkah, arah urutan, dan waktu tahan.
    const result = info.result;
    test.frames.push({
        t: round(elapsed, 1),
        step: stepIndex,
        target,
        direction,
        holdT: holdT === null ? null : round(holdT, 1),
        counted: judged,
        hands: (result.landmarks || []).map((points, i) => ({
            p: points.map((pt) => [round(pt.x, 4), round(pt.y, 4), round(pt.z, 4)]),
            w: ((result.worldLandmarks || [])[i] || []).map((pt) => [round(pt.x, 4), round(pt.y, 4), round(pt.z, 4)]),
            label: info.detections[i] ? info.detections[i].label : '',
            score: info.detections[i] ? round(info.detections[i].score, 3) : 0,
        })),
    });

    if (!judged) {
        return;
    }
    const row = test.rows[stepIndex];
    row.frames++;
    // Frame yang tidak dipakai aplikasi untuk menghitung (ujung jari keluar gambar) tidak dinilai.
    if (info.detections.some((d) => excludedForCounting(d.problem))) {
        row.badPosition++;
        return;
    }
    row.judged++;
    for (const name of COMPARE) {
        if (info.totals[name] === target) {
            row.ok[name]++;
        }
    }
    if (info.detections.length < expectedHands(target)) {
        row.missingHand++;
    }
    for (const detection of info.detections) {
        row.labels[detection.screenX < 0.5 ? 'left' : 'right'].push(detection.label);
    }
}

// Persentase label yang berbeda dari label terbanyak di sisi layar yang sama.
function labelChangeRate(row) {
    let changed = 0;
    let total = 0;
    for (const labels of [row.labels.left, row.labels.right]) {
        if (labels.length === 0) continue;
        const counts = {};
        labels.forEach((label) => {
            counts[label] = (counts[label] || 0) + 1;
        });
        changed += labels.length - Math.max(...Object.values(counts));
        total += labels.length;
    }
    return total === 0 ? null : changed / total;
}

const percent = (part, whole) => (whole === 0 ? '-' : `${Math.round((100 * part) / whole)}%`);

function addRow(tbody, cells, className = '') {
    const tr = document.createElement('tr');
    tr.className = className;
    cells.forEach((cell, i) => {
        const td = document.createElement(i === 0 ? 'th' : 'td');
        if (i === 0) td.scope = 'row';
        td.textContent = cell;
        tr.appendChild(td);
    });
    tbody.appendChild(tr);
}

function finishTest(stopped) {
    if (!test) {
        return;
    }
    const finished = test;
    test = null;
    $('guide-overlay').hidden = true;
    $('btn-test').disabled = false;
    $('palm-facing').disabled = false;
    $('btn-test-stop').hidden = true;

    const headers = ['Hitungan', ...COMPARE.map((n) => PIPELINES[n].short), 'Posisi', 'Tangan kurang', 'Label berubah'];
    const lines = [headers.join('\t')];
    const tbody = $('test-rows');
    tbody.replaceChildren();
    const sum = { judged: 0, ok: Object.fromEntries(COMPARE.map((n) => [n, 0])) };
    finished.rows.forEach((row, i) => {
        if (row.frames === 0) return;
        const { target, direction } = STEPS[i];
        sum.judged += row.judged;
        COMPARE.forEach((n) => {
            sum.ok[n] += row.ok[n];
        });
        const labelRate = labelChangeRate(row);
        const cells = [
            `${target}${direction === 'turun' ? ' (turun)' : ''}`,
            ...COMPARE.map((n) => percent(row.ok[n], row.judged)),
            percent(row.badPosition, row.frames),
            percent(row.missingHand, row.judged),
            labelRate === null ? '-' : `${Math.round(labelRate * 100)}%`,
        ];
        lines.push(cells.join('\t'));
        addRow(tbody, cells);
    });
    const avg = ['Rata-rata', ...COMPARE.map((n) => percent(sum.ok[n], sum.judged)), '', '', ''];
    lines.push(avg.join('\t'));
    addRow(tbody, avg, 'is-total');

    const palmText = finished.palmFacing === 'back' ? 'punggung tangan ke kamera' : 'telapak ke kamera';
    lines.push(`Arah: ${palmText}. Prosesor: ${finished.delegate || '-'}. ${navigator.userAgent}`);
    $('test-text').value = lines.join('\n');
    $('test-result').hidden = false;
    $('test-status').textContent = stopped ? 'Tes dihentikan. Hasil di bawah hanya untuk hitungan yang sudah lewat.' : 'Tes selesai.';

    // Rekaman untuk diunduh (versi 2, dibaca tests/js/evaluate-finger-count.mjs).
    lastRecording = {
        version: 2,
        createdAt: new Date().toISOString(),
        userAgent: navigator.userAgent,
        delegate: finished.delegate,
        palmFacing: finished.palmFacing,
        frameWidth: finished.frameWidth,
        frameHeight: finished.frameHeight,
        prepMs: PREP_MS,
        holdMs: HOLD_MS,
        measureLastMs: MEASURE_LAST_MS,
        settings: FINGER_SETTINGS,
        frames: finished.frames,
    };
}

let lastRecording = null;

$('btn-copy').addEventListener('click', async () => {
    try {
        await navigator.clipboard.writeText($('test-text').value);
        $('test-status').textContent = 'Tabel tersalin. Tempel ke chat atau dokumen.';
    } catch (error) {
        // Clipboard bisa ditolak browser: pilih teks supaya bisa disalin manual (Ctrl+C).
        $('test-text').select();
        $('test-status').textContent = 'Salin otomatis ditolak browser. Teks sudah dipilih, tekan Ctrl+C.';
    }
});

$('btn-download').addEventListener('click', () => {
    if (!lastRecording) return;
    const blob = new Blob([JSON.stringify(lastRecording)], { type: 'application/json' });
    const link = document.createElement('a');
    link.href = URL.createObjectURL(blob);
    link.download = `rekaman-jari-${lastRecording.createdAt.slice(0, 19).replace(/[:T]/g, '-')}.json`;
    link.click();
    setTimeout(() => URL.revokeObjectURL(link.href), 1000);
});
