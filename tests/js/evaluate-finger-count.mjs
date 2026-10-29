// Replay rekaman tes akurasi: jalankan cara hitung jari pada frame yang sama
// dan cetak tabel akurasi per hitungan. Memakai fungsi yang SAMA dengan
// aplikasi (assets/js/finger-count.js), jadi hasilnya sama dengan di browser.
//
// Pemakaian (dari folder proyek, butuh Node.js 18+):
//   node tests/js/evaluate-finger-count.mjs rekaman.json
//   node tests/js/evaluate-finger-count.mjs                 (tanpa file: data tangan sintetis)
//
// Yang dinilai: hanya 2 detik terakhir setiap hitungan (detik sebelumnya =
// waktu memindahkan tangan). Frame yang tidak dipakai aplikasi untuk menghitung
// (ujung jari keluar gambar, lihat excludedForCounting) tidak dinilai dan
// dilaporkan di kolom "Posisi".

import { readFileSync } from 'node:fs';
import { GesturePipeline, PIPELINES, detectionsFromResult, excludedForCounting } from '../../assets/js/finger-count.js';
import { makeRandom, makeHand, addNoise, extendedFor } from './synthetic-hand.mjs';

const NAMES = Object.keys(PIPELINES);
const MEASURE_LAST_MS = 2000;

function percent(ok, total) {
    return total === 0 ? '-' : `${Math.round((100 * ok) / total)}%`;
}

// rows: Map label -> { ok: {nama: n}, total, badPosition, frames }
function printTable(title, rows) {
    console.log(`\n${title}`);
    const header = ['Hitungan'.padEnd(12), ...NAMES.map((n) => n.padStart(11)), 'Posisi'.padStart(8)];
    console.log(header.join(' | '));
    const sum = { ok: Object.fromEntries(NAMES.map((n) => [n, 0])), total: 0 };
    for (const [label, row] of rows) {
        sum.total += row.total;
        NAMES.forEach((n) => {
            sum.ok[n] += row.ok[n];
        });
        console.log([label.padEnd(12), ...NAMES.map((n) => percent(row.ok[n], row.total).padStart(11)),
            percent(row.badPosition, row.frames).padStart(8)].join(' | '));
    }
    console.log(['Rata-rata'.padEnd(12), ...NAMES.map((n) => percent(sum.ok[n], sum.total).padStart(11)), ''.padStart(8)].join(' | '));
}

function newRow() {
    return { ok: Object.fromEntries(NAMES.map((n) => [n, 0])), total: 0, badPosition: 0, frames: 0 };
}

// ---------------------------------------------------------------------
// Rekaman sungguhan
// ---------------------------------------------------------------------
// Versi 1: setiap frame punya target dan counted (seluruh 3 detik tahan).
//          Langkah dan waktu tahan dihitung ulang dari t, prepMs, dan holdMs.
// Versi 2: setiap frame juga punya step, direction ('naik'/'turun') dan
//          holdT (ms sejak mulai tahan, null saat bersiap).
function frameInfo(recording, frame) {
    if (recording.version >= 2) {
        return { step: frame.step, direction: frame.direction, holdT: frame.holdT };
    }
    const stepMs = recording.prepMs + recording.holdMs;
    const step = Math.floor(frame.t / stepMs);
    const inStep = frame.t - step * stepMs;
    return { step, direction: 'naik', holdT: inStep >= recording.prepMs ? inStep - recording.prepMs : null };
}

function evaluateRecording(file) {
    const recording = JSON.parse(readFileSync(file, 'utf8'));
    const holdMs = recording.holdMs;
    const pipelines = NAMES.map((n) => new GesturePipeline(n));
    const rows = new Map();
    const unpack = (list) => list.map(([x, y, z]) => ({ x, y, z }));

    for (const frame of recording.frames) {
        const result = {
            landmarks: frame.hands.map((h) => unpack(h.p)),
            worldLandmarks: frame.hands.map((h) => unpack(h.w)),
        };
        const detections = detectionsFromResult(result);
        // Semua jalur diproses di SETIAP frame (juga saat bersiap), supaya
        // hysteresis dan masa toleransi berjalan seperti di aplikasi.
        const totals = Object.fromEntries(pipelines.map((p) => [p.name, p.process(detections, frame.t).total]));

        const info = frameInfo(recording, frame);
        if (info.holdT === null || info.holdT < holdMs - MEASURE_LAST_MS) {
            continue;
        }
        const label = `${frame.target}${info.direction === 'turun' ? ' (turun)' : ''}`;
        if (!rows.has(label)) {
            rows.set(label, newRow());
        }
        const row = rows.get(label);
        row.frames++;
        if (detections.some((d) => excludedForCounting(d.problem))) {
            row.badPosition++;
            continue;
        }
        row.total++;
        NAMES.forEach((n) => {
            if (totals[n] === frame.target) {
                row.ok[n]++;
            }
        });
    }
    const extra = [recording.delegate || '?', recording.palmFacing ? `arah: ${recording.palmFacing}` : 'arah: tidak dicatat'].join(', ');
    printTable(`Rekaman ${file} (${recording.frames.length} frame, ${extra}), 2 detik terakhir tiap hitungan`, rows);
}

// ---------------------------------------------------------------------
// Data sintetis (tanpa kamera). Hanya menunjukkan kelemahan geometris.
// ---------------------------------------------------------------------
function evaluateSynthetic({ title, dropoutChance, trials = 40, frames = 60, seed = 11 }) {
    const rand = makeRandom(seed);
    const rows = new Map();
    const tilt = 45;
    for (let target = 0; target <= 10; target++) {
        const row = newRow();
        for (let trial = 0; trial < trials; trial++) {
            const pose = () => ({ roll: rand.range(-tilt, tilt), pitch: rand.range(-tilt, tilt * 0.7), yaw: rand.range(-tilt, tilt) });
            const right = makeHand(rand, extendedFor(target > 5 ? target - 5 : target), pose(), { offsetX: -0.13 });
            const hands = target > 5 ? [makeHand(rand, extendedFor(5), pose(), { offsetX: 0.13, mirror: true }), right] : [right];
            const pipelines = NAMES.map((n) => new GesturePipeline(n));
            const missing = hands.map(() => 0);
            for (let f = 0; f < frames; f++) {
                const visible = [];
                hands.forEach((hand, i) => {
                    if (missing[i] === 0 && rand.next() < dropoutChance) {
                        missing[i] = 1 + Math.floor(rand.next() * 6);
                    }
                    if (missing[i] > 0) {
                        missing[i]--;
                        return;
                    }
                    visible.push(addNoise(rand, hand));
                });
                const detections = detectionsFromResult({ landmarks: visible.map((h) => h.points), worldLandmarks: visible.map((h) => h.world) });
                pipelines.forEach((pipeline) => {
                    const { total } = pipeline.process(detections, f * 67);
                    if (f >= 10 && pipeline === pipelines[0]) {
                        row.frames++;
                        row.total++;
                    }
                    if (f >= 10 && total === target) {
                        row.ok[pipeline.name]++;
                    }
                });
            }
        }
        rows.set(String(target), row);
    }
    printTable(title, rows);
}

const file = process.argv[2];
if (file) {
    evaluateRecording(file);
} else {
    console.log('Data sintetis (tests/js/synthetic-hand.mjs): tangan diputar acak sampai 45 derajat.');
    console.log('Angka ini hanya menunjukkan kelemahan geometris; angka dari rekaman kamera yang menentukan.');
    evaluateSynthetic({ title: 'Kedua tangan selalu terdeteksi', dropoutChance: 0 });
    evaluateSynthetic({ title: 'Setiap tangan kadang hilang 1-6 frame', dropoutChance: 0.03 });
}
