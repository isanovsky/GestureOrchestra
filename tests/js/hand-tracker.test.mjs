// Uji debounce (hand-tracker.js) dan cara hitung LAMA (finger-count.js). Jalankan dari terminal:
//   node --test tests/js/
// Hanya untuk pengembangan; Node.js tidak dipakai di produksi.

import { test } from 'node:test';
import assert from 'node:assert/strict';
import { GestureDebouncer } from '../../assets/js/hand-tracker.js';
import { countFingersLegacy as countFingers } from '../../assets/js/finger-count.js';

// Tangan tegak buatan: pergelangan di bawah, jari mengarah ke atas.
// extended = daftar jari yang terangkat: 'thumb', 'index', 'middle', 'ring', 'pinky'.
function fakeHand(extended, angleDeg = 0) {
    const p = Array.from({ length: 21 }, () => ({ x: 0, y: 0 }));
    p[0] = { x: 0.5, y: 0.9 };
    const columns = { index: [5, 6, 7, 8, 0.44], middle: [9, 10, 11, 12, 0.5], ring: [13, 14, 15, 16, 0.56], pinky: [17, 18, 19, 20, 0.62] };
    for (const [name, [mcp, pip, dip, tip, x]] of Object.entries(columns)) {
        p[mcp] = { x, y: 0.7 };
        p[pip] = { x, y: 0.6 };
        if (extended.includes(name)) {
            p[dip] = { x, y: 0.53 };
            p[tip] = { x, y: 0.47 };
        } else {
            // Jari menekuk: ujung kembali turun mendekati telapak.
            p[dip] = { x, y: 0.66 };
            p[tip] = { x, y: 0.72 };
        }
    }
    p[1] = { x: 0.42, y: 0.84 };
    p[2] = { x: 0.37, y: 0.78 };
    p[3] = { x: 0.33, y: 0.73 };
    p[4] = extended.includes('thumb') ? { x: 0.27, y: 0.68 } : { x: 0.42, y: 0.70 };

    // Putar seluruh tangan terhadap pergelangan untuk menguji tangan miring.
    const rad = (angleDeg * Math.PI) / 180;
    return p.map(({ x, y }) => {
        const dx = x - 0.5;
        const dy = y - 0.9;
        return { x: 0.5 + dx * Math.cos(rad) - dy * Math.sin(rad), y: 0.9 + dx * Math.sin(rad) + dy * Math.cos(rad) };
    });
}

test('kepalan = 0 jari', () => {
    assert.equal(countFingers(fakeHand([])), 0);
});

test('telunjuk saja = 1 jari', () => {
    assert.equal(countFingers(fakeHand(['index'])), 1);
});

test('telapak terbuka = 5 jari', () => {
    assert.equal(countFingers(fakeHand(['thumb', 'index', 'middle', 'ring', 'pinky'])), 5);
});

test('tiga jari tetap terbaca walau tangan miring 60 derajat', () => {
    assert.equal(countFingers(fakeHand(['index', 'middle', 'ring'], 60)), 3);
    assert.equal(countFingers(fakeHand(['index', 'middle', 'ring'], -60)), 3);
});

test('debounce: nilai baru baru berlaku setelah stabil 100 ms', () => {
    const d = new GestureDebouncer(100, 2);
    assert.equal(d.push(3, 0), null);
    assert.equal(d.push(3, 50), null);
    assert.deepEqual(d.push(3, 100), { value: 3, at: 0 });
    assert.equal(d.current, 3);
});

test('debounce: kedipan satu frame diabaikan', () => {
    const d = new GestureDebouncer(100, 2);
    d.push(2, 0);
    d.push(2, 100);
    assert.equal(d.current, 2);
    assert.equal(d.push(4, 133), null);    // salah baca sesaat
    assert.equal(d.push(2, 166), null);    // kembali ke nilai lama
    assert.equal(d.push(4, 200), null);    // kandidat mulai dari awal lagi
    assert.equal(d.current, 2);
});

test('debounce 0 ms: berubah di frame pertama', () => {
    const d = new GestureDebouncer(0, 1);
    assert.deepEqual(d.push(5, 10), { value: 5, at: 10 });
});
