// Uji cara hitung jari baru (assets/js/finger-count.js) dengan tangan sintetis.
// Jalankan dari terminal: node --test tests/js/

import { test } from 'node:test';
import assert from 'node:assert/strict';
import {
    FINGER_SETTINGS, FingerState, GesturePipeline, LeftHandSwitch,
    countFingersAngles, countFingersLegacy, detectionsFromResult, excludedForCounting, jointAngle, positionProblem,
} from '../../assets/js/finger-count.js';
import { makeRandom, makeHand, extendedFor } from './synthetic-hand.mjs';

const upright = { roll: 0, pitch: 0, yaw: 0 };

test('tangan tegak: 0 sampai 5 jari terbaca benar, tangan kiri maupun kanan', () => {
    const rand = makeRandom(1);
    for (const mirror of [false, true]) {
        for (let n = 0; n <= 5; n++) {
            for (let i = 0; i < 20; i++) {
                const hand = makeHand(rand, extendedFor(n), upright, { mirror });
                assert.equal(countFingersAngles(hand.world), n, `n=${n} mirror=${mirror}`);
            }
        }
    }
});

test('tangan miring 60 derajat, condong 40 derajat, menoleh 40 derajat tetap benar', () => {
    const rand = makeRandom(2);
    for (const pose of [{ roll: 60, pitch: 0, yaw: 0 }, { roll: -60, pitch: 0, yaw: 0 }, { roll: 0, pitch: 40, yaw: 0 }, { roll: 0, pitch: -40, yaw: 0 }, { roll: 0, pitch: 0, yaw: 40 }]) {
        for (let n = 0; n <= 5; n++) {
            const hand = makeHand(rand, extendedFor(n), pose);
            assert.equal(countFingersAngles(hand.world), n, `n=${n} pose=${JSON.stringify(pose)}`);
        }
    }
});

test('sudut sendi: 180 = lurus, 90 = siku', () => {
    const p = [{ x: 0, y: 0, z: 0 }, { x: 0, y: 1, z: 0 }, { x: 0, y: 2, z: 0 }, { x: 1, y: 1, z: 0 }];
    assert.equal(Math.round(jointAngle(p, 0, 1, 2)), 180);
    assert.equal(Math.round(jointAngle(p, 0, 1, 3)), 90);
});

test('tanpa worldLandmarks tangan tidak dihitung (koordinat 2D tidak dipakai)', () => {
    const rand = makeRandom(9);
    const hand = makeHand(rand, extendedFor(3), upright, { offsetX: -0.13 });
    const detections = detectionsFromResult({ landmarks: [hand.points], worldLandmarks: [] });
    assert.equal(detections[0].world, null);
    assert.equal(new GesturePipeline('standard').process(detections, 0).total, 0);
});

test('posisi: ujung jari di luar gambar = tidak dihitung, pergelangan rendah = hanya peringatan', () => {
    const base = Array.from({ length: 21 }, () => ({ x: 0.5, y: 0.5, z: 0 }));
    assert.equal(positionProblem(base), null);
    const tipOut = base.map((p, i) => (i === 8 ? { ...p, y: -0.02 } : p));
    assert.equal(positionProblem(tipOut), 'cut');
    assert.equal(excludedForCounting('cut'), true);
    const wristLow = base.map((p, i) => (i === 0 ? { ...p, y: 1.03 } : p));
    assert.equal(positionProblem(wristLow), 'low');
    assert.equal(excludedForCounting('low'), false);
    assert.equal(excludedForCounting('low', { ...FINGER_SETTINGS, excludeLowWrist: true }), true);
});

test('hysteresis: jari setengah menekuk mempertahankan status sebelumnya', () => {
    // Semua jari ditekuk di PIP sebesar bend derajat: sudut PIP = 180 - bend.
    // Ambang: naik di atas 140, turun di bawah 128.
    const bentPoints = (bend) => {
        const p = Array.from({ length: 21 }, (_, i) => ({ x: i * 0.001, y: 0, z: 0 }));
        const rad = (bend * Math.PI) / 180;
        for (const [mcp, pip, dip, tip] of [[1, 2, 3, 4], [5, 6, 7, 8], [9, 10, 11, 12], [13, 14, 15, 16], [17, 18, 19, 20]]) {
            p[mcp] = { x: mcp * 0.002, y: 0.08, z: 0 };
            p[pip] = { x: mcp * 0.002, y: 0.12, z: 0 };
            p[dip] = { x: mcp * 0.002, y: 0.12 + 0.025 * Math.cos(rad), z: 0.025 * Math.sin(rad) };
            p[tip] = { x: mcp * 0.002, y: p[dip].y + 0.02 * Math.cos(rad), z: p[dip].z + 0.02 * Math.sin(rad) };
        }
        return p;
    };
    const state = new FingerState();
    assert.equal(state.update(bentPoints(0)).states[2], true, 'sudut 180 = terangkat');
    assert.equal(state.update(bentPoints(46)).states[2], true, 'sudut 134 (di antara ambang): tetap terangkat');
    assert.equal(state.update(bentPoints(60)).states[2], false, 'sudut 120 = ditekuk');
    assert.equal(state.update(bentPoints(46)).states[2], false, 'sudut 134 (di antara ambang): tetap ditekuk');
});

function frameOf(hands) {
    return detectionsFromResult({ landmarks: hands.map((h) => h.points), worldLandmarks: hands.map((h) => h.world) });
}

test('masa toleransi: tangan yang hilang 100 ms tetap dihitung, setelah 200 ms dianggap hilang', () => {
    const rand = makeRandom(3);
    const left = makeHand(rand, extendedFor(5), upright, { offsetX: 0.13, mirror: true });
    const right = makeHand(rand, extendedFor(2), upright, { offsetX: -0.13 });
    const pipeline = new GesturePipeline('standard');
    assert.equal(pipeline.process(frameOf([left, right]), 0).total, 7);
    assert.equal(pipeline.process(frameOf([right]), 100).total, 7, 'tangan kiri hilang 100 ms');
    assert.equal(pipeline.process(frameOf([right]), 200).total, 2, 'tangan kiri hilang 200 ms');

    // Jarak 2D (versi awal) tidak punya masa toleransi: begitu tangan kiri hilang, hanya tangan kanan yang dihitung.
    const legacy = new GesturePipeline('legacy');
    legacy.process(frameOf([left, right]), 0);
    assert.equal(legacy.process(frameOf([right]), 33).total, countFingersLegacy(right.points));
});

test('tangan kiri +5: kiri terbuka + kanan 2 = 7, kiri mengepal + kanan 3 = 3, satu tangan terbuka = 5', () => {
    const rand = makeRandom(4);
    const leftOpen = makeHand(rand, extendedFor(5), upright, { offsetX: 0.13, mirror: true });
    const leftFist = makeHand(rand, extendedFor(0), upright, { offsetX: 0.13, mirror: true });
    const right2 = makeHand(rand, extendedFor(2), upright, { offsetX: -0.13 });
    const right3 = makeHand(rand, extendedFor(3), upright, { offsetX: -0.13 });
    assert.equal(new GesturePipeline('leftPlus5').process(frameOf([leftOpen, right2]), 0).total, 7);
    assert.equal(new GesturePipeline('leftPlus5').process(frameOf([leftFist, right3]), 0).total, 3);
    assert.equal(new GesturePipeline('leftPlus5').process(frameOf([right3]), 0).total, 3);
    assert.equal(new GesturePipeline('leftPlus5').process(frameOf([leftOpen]), 0).total, 5);
});

test('tangan kiri +5: tangan kiri ditentukan dari posisi di layar, bukan bentuk tangan', () => {
    const rand = makeRandom(5);
    // Tangan "kanan" (tidak dicerminkan) yang diletakkan di sisi kiri layar tetap dianggap tangan kiri.
    const openOnLeftSide = makeHand(rand, extendedFor(5), upright, { offsetX: 0.13 });
    const twoOnRightSide = makeHand(rand, extendedFor(2), upright, { offsetX: -0.13, mirror: true });
    assert.equal(new GesturePipeline('leftPlus5').process(frameOf([openOnLeftSide, twoOnRightSide]), 0).total, 7);
});

test('tangan kiri +5: hysteresis terbuka/tertutup', () => {
    const s = new LeftHandSwitch(FINGER_SETTINGS);
    assert.equal(s.update(5), true);
    assert.equal(s.update(3), true, '3 jari setelah terbuka: tetap terbuka');
    assert.equal(s.update(1), false);
    assert.equal(s.update(3), false, '3 jari setelah tertutup: tetap tertutup');
    assert.equal(s.update(null), false, 'tangan kiri hilang = tertutup');
});
