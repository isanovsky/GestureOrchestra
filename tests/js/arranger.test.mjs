// Uji mode Arranger: pembaca pola, teori akor, dan penjadwal iringan.
// Jalankan: node --test tests/js/

import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { parsePattern, chordFor, voiceChord, bassVoicing, eventMidis } from '../../assets/js/arranger-pattern.js';
import { ArrangerEngine, prepareStyle, CHORD_QUANTUM } from '../../assets/js/arranger-engine.js';

const root = new URL('../../', import.meta.url);
const fixture = JSON.parse(readFileSync(new URL('tests/fixtures/arranger-patterns.json', root), 'utf8'));

// ----------------------------------------------------------------------
// Pembaca pola (kasus yang sama dengan uji PHP)
// ----------------------------------------------------------------------
test('pola: kasus bersama PHP/JS memberi jumlah kejadian dan pesan error yang sama', () => {
    fixture.cases.forEach((c, i) => {
        const result = parsePattern(c.text, c.track, c.length);
        assert.deepEqual([result.events.length, result.errors], [c.events, c.errors], `kasus #${i}`);
    });
});

test('pola: pecahan, urutan, dan nilai bawaan', () => {
    const { events } = parsePattern('2 R35\n4/3 5- 0.5 0.7', 'chord', 4);
    assert.equal(events[0].beat, 4 / 3);
    assert.deepEqual(events[0], { beat: 4 / 3, tones: '5', low: true, dur: 0.5, vel: 0.7 });
    assert.deepEqual(events[1], { beat: 2, tones: 'R35', low: false, dur: 1, vel: 1 });
});

// Data awal migrasi 007, dibaca langsung dari file SQL.
function seedStyles() {
    const sql = readFileSync(new URL('database/migrations/007_arranger.sql', root), 'utf8');
    const styles = {};
    for (const m of sql.matchAll(/\('(\w+)', '([^']+)', '[^']*', (\d+), (\d+), '(\w+)', 1, \d+\)/g)) {
        styles[m[1]] = { id: m[1], name: m[2], bpm: Number(m[3]), beats_per_bar: Number(m[4]), chord_voice: m[5], sections: {} };
    }
    for (const m of sql.matchAll(/SELECT '(\w+)'(?: AS slug)?, '(\w+)'(?: AS section)?, (\d+)/g)) {
        styles[m[1]].sections[m[2]] = { length: Number(m[3]), tracks: {} };
    }
    for (const m of sql.matchAll(/SELECT '(\w+)'(?: AS slug)?, '(\w+)'(?: AS section)?, '(\w+)'(?: AS track)?,\s*'([^']*)'/g)) {
        styles[m[1]].sections[m[2]].tracks[m[3]] = m[4].replaceAll('\\n', '\n');
    }
    return styles;
}

test('pola: semua pola awal 007 lolos pembaca JS', () => {
    const styles = seedStyles();
    assert.deepEqual(Object.keys(styles), ['ballad', 'pop', 'dangdut']);
    for (const style of Object.values(styles)) {
        for (const [name, section] of Object.entries(style.sections)) {
            for (const [track, text] of Object.entries(section.tracks)) {
                const result = parsePattern(text, track, section.length);
                assert.deepEqual(result.errors, [], `${style.id}/${name}/${track}`);
                assert.ok(result.events.length > 0, `${style.id}/${name}/${track} kosong`);
            }
        }
    }
});

// ----------------------------------------------------------------------
// Akor
// ----------------------------------------------------------------------
test('akor: diatonis, Shift membalik mayor/minor, ikut transpose', () => {
    assert.equal(chordFor(1, 0).name, 'C');
    assert.equal(chordFor(2, 0).name, 'Dm');
    assert.equal(chordFor(2, 0, true).name, 'D');
    assert.equal(chordFor(1, 0, true).name, 'Cm');
    assert.equal(chordFor(7, 0).name, 'Bdim');
    assert.equal(chordFor(7, 0, true).name, 'Bm');
    assert.equal(chordFor(3, -3, true).name, 'C#');   // Do = A: iii = C#m, dibalik = C#
    assert.equal(chordFor(5, 2).name, 'A');
    assert.equal(chordFor(6, -5).name, 'Em');         // Do = G
});

test('akor: susunan dekat C4 dan berpindah halus (pembalikan otomatis)', () => {
    let voicing = voiceChord(chordFor(1, 0));
    assert.deepEqual([voicing.R, voicing[3], voicing[5]].sort((a, b) => a - b), [60, 64, 67]);
    for (const degree of [4, 5, 6, 2, 5, 1, 3, 7]) {
        const next = voiceChord(chordFor(degree, 0), voicing.center);
        assert.ok(Math.abs(next.center - voicing.center) <= 4, `lompatan ke derajat ${degree}`);
        for (const n of [next.R, next[3], next[5]]) {
            assert.ok(n >= 52 && n < 72, `nada ${n} di luar jangkauan`);
        }
        voicing = next;
    }
});

test('akor: bass di oktaf 2, nada kejadian mengikuti akor', () => {
    const am = chordFor(6, 0);
    const bass = bassVoicing(am);
    assert.deepEqual([bass.R, bass[3], bass[5]], [45, 48, 52]);   // A2 C3 E3
    assert.deepEqual(eventMidis({ tones: '5', low: true }, bass), [40]);
    assert.deepEqual(eventMidis({ tones: 'R8', low: false }, bass), [45, 57]);
});

// ----------------------------------------------------------------------
// Penjadwal
// ----------------------------------------------------------------------
// Style uji sederhana: 4/4, 120 bpm (0,5 detik per ketukan).
function testStyle(extra = {}) {
    return prepareStyle({
        id: 1, name: 'Uji', bpm: 120, beats_per_bar: 4, chord_voice: 'piano',
        sections: {
            intro: { length: 4, tracks: { drum: '0 CRASH' } },
            main_a: { length: 4, tracks: { drum: '0 KICK\n1 SNARE\n2 KICK\n3 SNARE', bass: '0 R 4', chord: '0 R35 2\n2 R35 2' } },
            main_b: { length: 4, tracks: { drum: '0 OHAT\n2 OHAT' } },
            fill: { length: 4, tracks: { drum: '0 TOM\n1 TOM\n2 TOM\n3 TOM' } },
            ending: { length: 4, tracks: { drum: '0 CYMBAL', chord: '0 R358 4' } },
            ...extra,
        },
    });
}

// Jalankan tick setiap 25 ms dari t0 sampai t1; kumpulkan semua kejadian.
function run(engine, t0, t1, out = []) {
    for (let t = t0; t < t1 - 1e-9; t += 0.025) {
        out.push(...engine.tick(t, 0.1));
    }
    return out;
}

const drums = (actions) => actions.filter((a) => a.kind === 'drum').map((a) => [a.beat, a.code]);

test('jadwal: Main A diulang, setiap kejadian sekali, waktu sesuai tempo', () => {
    const engine = new ArrangerEngine({ style: testStyle() });
    engine.start(0);
    const actions = run(engine, 0, 4);    // 4 detik = 8 ketukan
    const hits = drums(actions).filter(([beat]) => beat < 8);
    assert.deepEqual(hits, [[0, 'KICK'], [1, 'SNARE'], [2, 'KICK'], [3, 'SNARE'], [4, 'KICK'], [5, 'SNARE'], [6, 'KICK'], [7, 'SNARE']]);
    assert.ok(Math.abs(engine.timeAt(4) - (0.05 + 2)) < 1e-9);
    // Kejadian dijadwalkan di depan (look-ahead), tidak terlambat.
    const notes = actions.filter((a) => a.kind === 'note' && a.track === 'chord');
    assert.deepEqual(notes.slice(0, 2).map((a) => a.midis), [[60, 64, 67], [60, 64, 67]]);
});

test('jadwal: akor baru berlaku di seperdelapan berikutnya, nada panjang dibunyikan ulang', () => {
    const engine = new ArrangerEngine({ style: testStyle() });
    engine.start(0);
    const before = run(engine, 0, 0.6);          // sampai ketukan ~1.1
    const pressAt = 0.6;
    engine.setChord(4, false, pressAt);          // F
    const at = engine.pendingChord.at;
    assert.equal(at % CHORD_QUANTUM, 0);
    assert.ok(at >= engine.beatAt(pressAt) && at - engine.beatAt(pressAt) <= CHORD_QUANTUM + 0.4);
    const after = run(engine, 0.6, 1.5);
    const cuts = after.filter((a) => a.kind === 'cut');
    assert.ok(cuts.length >= 2, 'bass dan akor yang masih berbunyi dipotong');
    assert.ok(cuts.every((c) => c.beat === at));
    const retrig = after.filter((a) => a.kind === 'note' && a.beat === at);
    const bass = retrig.find((a) => a.track === 'bass');
    assert.deepEqual(bass.midis, [41]);           // F2
    assert.equal(bass.dur, 4 - at);
    const chord = after.find((a) => a.kind === 'note' && a.track === 'chord' && a.beat === 2);
    assert.deepEqual([...chord.midis].sort((x, y) => x - y), [60, 65, 69]);   // F/C dekat susunan C
    assert.equal(before.filter((a) => a.kind === 'note' && a.beat >= at).length, 0);
});

test('jadwal: Fill di birama berikutnya, lalu kembali ke Main', () => {
    const engine = new ArrangerEngine({ style: testStyle() });
    engine.start(0);
    run(engine, 0, 0.5);
    engine.selectSection('fill', 0.5);
    assert.equal(engine.queued.at, 4);
    const actions = run(engine, 0.5, 6.05);      // sampai ketukan ~12
    const hits = drums(actions).filter(([beat]) => beat >= 4 && beat < 12);
    assert.deepEqual(hits, [[4, 'TOM'], [5, 'TOM'], [6, 'TOM'], [7, 'TOM'], [8, 'KICK'], [9, 'SNARE'], [10, 'KICK'], [11, 'SNARE']]);
});

test('jadwal: Main B di birama berikutnya; Main B kosong memakai Main A', () => {
    const engine = new ArrangerEngine({ style: testStyle() });
    engine.start(0);
    run(engine, 0, 1);
    engine.selectSection('main_b', 1);
    const hits = drums(run(engine, 1, 4)).filter(([beat]) => beat >= 4 && beat < 8);
    assert.deepEqual(hits, [[4, 'OHAT'], [6, 'OHAT']]);

    const noB = new ArrangerEngine({ style: testStyle({ main_b: undefined }) });
    noB.selectSection('main_b', 0);
    assert.equal(noB.mainVariant, 'main_a');
});

test('jadwal: Intro lalu Main; berhenti masuk Ending lalu diam', () => {
    const engine = new ArrangerEngine({ style: testStyle() });
    engine.selectSection('intro', 0);
    assert.equal(engine.status(0).introArmed, true);
    engine.start(0);
    let hits = drums(run(engine, 0, 2.6)).filter(([beat]) => beat < 5);
    assert.deepEqual(hits, [[0, 'CRASH'], [4, 'KICK']]);
    engine.stop(2.6);                            // ketukan ~5.1 -> Ending di 8
    assert.equal(engine.queued.name, 'ending');
    const actions = run(engine, 2.6, 7);
    hits = drums(actions).filter(([beat]) => beat >= 8);
    assert.deepEqual(hits, [[8, 'CYMBAL']]);
    assert.equal(engine.playing, false);
    // Ending dibiarkan meluruh: tidak ada perintah berhenti seketika.
    assert.equal(actions.filter((a) => a.kind === 'stop').length, 0);
});

test('jadwal: tanpa Ending, berhenti di birama berikutnya; tekan dua kali = seketika', () => {
    const engine = new ArrangerEngine({ style: testStyle({ ending: undefined }) });
    engine.start(0);
    run(engine, 0, 1);
    engine.stop(1);
    const actions = run(engine, 1, 3);
    assert.deepEqual(actions.filter((a) => a.kind === 'stop').map((a) => a.beat), [4]);
    assert.equal(drums(actions).filter(([beat]) => beat >= 4).length, 0);

    const twice = new ArrangerEngine({ style: testStyle() });
    twice.start(0);
    run(twice, 0, 1);
    twice.stop(1);
    twice.stop(1.1);
    assert.equal(twice.playing, false);
    assert.equal(twice.tick(1.2, 0.1)[0].kind, 'stop');
});

test('jadwal: tempo berubah tanpa melompat; sync start mulai dari akor pertama', () => {
    const engine = new ArrangerEngine({ style: testStyle() });
    engine.start(0);
    run(engine, 0, 1);
    const beat = engine.beatAt(1);
    engine.setBpm(60, 1);
    assert.ok(Math.abs(engine.beatAt(1) - beat) < 1e-9);
    assert.ok(Math.abs(engine.beatAt(2) - beat - 1) < 1e-9);

    const sync = new ArrangerEngine({ style: testStyle() });
    sync.syncStart = true;
    sync.setChord(6, false, 3);
    assert.equal(sync.playing, true);
    assert.equal(sync.chord.name, 'Am');
});

test('jadwal: tiga style awal berjalan 2 menit dengan semua tombol tanpa macet', () => {
    for (const raw of Object.values(seedStyles())) {
        const engine = new ArrangerEngine({ style: prepareStyle(raw) });
        engine.selectSection('intro', 0);
        engine.start(0);
        const actions = [];
        const presses = [[5, () => engine.setChord(4, false, 5)], [9, () => engine.selectSection('fill', 9)],
            [14, () => engine.selectSection('main_b', 14)], [20, () => engine.setChord(6, true, 20)],
            [33, () => engine.setBpm(150, 33)], [60, () => engine.selectSection('main_a', 60)],
            [90, () => engine.stop(90)]];
        let t = 0;
        for (const [at, press] of presses) {
            run(engine, t, at, actions);
            press();
            t = at;
        }
        run(engine, t, 120, actions);
        assert.equal(engine.playing, false, `${raw.id} berhenti setelah Ending`);
        const drumHits = actions.filter((a) => a.kind === 'drum').length;
        assert.ok(drumHits > 100, `${raw.id}: ${drumHits} pukulan drum`);
        // Setiap kejadian terjadwal berurutan (tidak mundur) dan tidak ganda.
        const keys = actions.filter((a) => a.kind === 'drum').map((a) => a.beat + a.code);
        assert.equal(new Set(keys).size, keys.length, `${raw.id}: pukulan ganda`);
    }
});
