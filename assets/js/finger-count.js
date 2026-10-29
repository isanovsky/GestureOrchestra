// Cara menghitung jari dari titik-titik tangan MediaPipe. Semua fungsi dan kelas
// di sini murni (tanpa kamera, tanpa DOM), jadi bisa diuji di terminal dan
// dipakai ulang oleh hand-tracker.js, halaman uji kamera, dan skrip replay
// rekaman (tests/js/evaluate-finger-count.mjs). Skrip replay memakai fungsi
// yang SAMA dengan aplikasi, jadi hasilnya bisa dipercaya.
//
// Cara hitung per tangan:
// - 'legacy' : cara lama (Fase 3), jarak 2D ke pergelangan. Hanya pembanding.
// - 'angles' : sudut sendi dari worldLandmarks (3D), hysteresis per jari.
// Cara menggabungkan dua tangan:
// - 'sum'      : jumlah jari kedua tangan (0 sampai 10).
// - 'leftPlus5': tangan kanan menghitung 1 sampai 5, tangan kiri terbuka = +5.
//
// Nomor titik MediaPipe: 0 pergelangan; ibu jari 1-4; telunjuk 5-8;
// tengah 9-12; manis 13-16; kelingking 17-20.

// ---------------------------------------------------------------------
// Semua ambang di satu tempat. Nilai diambil dari analisis rekaman tes
// akurasi di laptop presentasi (Edge, GPU, sekitar 15 fps).
// ---------------------------------------------------------------------
export const FINGER_SETTINGS = {
    // Telunjuk sampai kelingking: sudut di sendi PIP (titik MCP-PIP-DIP),
    // 180 derajat = lurus. Diukur dari worldLandmarks (3D). Koordinat gambar
    // (2D) tidak dipakai: jari yang terlipat ke arah kamera terlihat lurus di 2D.
    // Hysteresis: jari baru dianggap terangkat di atas 140 dan baru dianggap
    // ditekuk di bawah 128; di antaranya status sebelumnya dipertahankan.
    fingerUpAbove: 140,
    fingerDownBelow: 128,

    // Ibu jari. Dua aturan tersedia, dipilih dengan thumbRule:
    // - 'spread' (bawaan): jarak ujung ibu jari ke pangkal kelingking dibagi
    //   lebar telapak. Pada rekaman telapak ke kamera, terlipat paling besar
    //   1,17 dan terbuka paling kecil 1,38 (tidak bertumpuk). Pada tes punggung
    //   tangan ke kamera tetap 99% benar.
    // - 'ipAngle': sudut di sendi IP (titik 2-3-4). Batasnya sempit (terlipat
    //   sampai sekitar 140 derajat, terbuka kadang hanya 133), jadi sangat
    //   bergantung pada hysteresis; pada tes punggung tangan hanya 79% benar.
    //   Tetap tersedia sebagai pilihan di mode diagnostik.
    // Keduanya tidak memakai label kiri/kanan MediaPipe.
    thumbRule: 'spread',
    thumbUpAbove: 142,
    thumbDownBelow: 136,
    thumbSpreadUpAbove: 1.32,
    thumbSpreadDownBelow: 1.24,

    // Debounce (dipakai hand-tracker.js): angka baru berlaku setelah stabil
    // selama sekian milidetik DAN minimal sekian frame. Di 15 fps satu frame
    // sekitar 67 ms, jadi 100 ms berarti 2 sampai 3 frame.
    debounceMs: 100,
    debounceMinFrames: 2,

    // Posisi tangan di kamera (koordinat gambar 0 sampai 1).
    // - 'cut' : ada UJUNG JARI di luar gambar. Jari itu tidak terlihat, jadi
    //           tangan ini tidak dipakai menghitung pada frame tersebut.
    // - 'low' : pergelangan di bawah wristBottomMax atau di luar gambar. Pemain
    //           diberi peringatan dan garis bantu, tetapi frame tetap dihitung:
    //           pada rekaman tes akurasi, frame seperti ini tetap 100% benar
    //           (MediaPipe masih menebak pergelangan dengan baik), padahal
    //           jumlahnya sekitar 70% dari frame yang dinilai. Ubah
    //           excludeLowWrist menjadi true untuk ikut membuangnya.
    wristBottomMax: 0.95,
    excludeLowWrist: false,

    // Mode "Tangan kiri +5": tangan kiri dianggap terbuka bila minimal 4 jari
    // terangkat (satu jari boleh salah baca), dan tertutup bila paling banyak 1.
    leftOpenAtLeast: 4,
    leftClosedAtMost: 1,

    // Masa toleransi: tangan yang hilang sesaat (atau sesaat terpotong) masih
    // dihitung dengan nilai terakhirnya selama ini.
    handGraceMs: 150,
    // Tangan di frame baru dianggap tangan yang sama bila pergelangannya
    // bergeser kurang dari ini (bagian lebar gambar).
    handMatchDistance: 0.25,
};

// ---------------------------------------------------------------------
// Cara lama (Fase 3), tidak diubah, hanya untuk pembanding.
// Jari: ujung harus lebih jauh dari pergelangan dibanding sendi PIP (2D).
// Ibu jari: ujung lebih jauh dari pangkal telunjuk dibanding sendi IP (2D).
// Pada rekaman tes akurasi cara ini hanya 22% benar.
// ---------------------------------------------------------------------
const LEGACY_FINGERS = [
    [8, 6, 5],
    [12, 10, 9],
    [16, 14, 13],
    [20, 18, 17],
];

function distance2d(a, b) {
    return Math.hypot(a.x - b.x, a.y - b.y);
}

// Hasil: array 5 boolean [ibu jari, telunjuk, tengah, manis, kelingking].
export function legacyFingerStates(points) {
    const wrist = points[0];
    const states = LEGACY_FINGERS.map(([tip, pip, mcp]) => {
        const segment = distance2d(points[mcp], points[pip]);
        return distance2d(points[tip], wrist) > distance2d(points[pip], wrist) + segment * 0.3;
    });
    const thumb = distance2d(points[4], points[5]) > distance2d(points[3], points[5]) * 1.35;
    return [thumb, ...states];
}

export function countFingersLegacy(points) {
    return legacyFingerStates(points).filter(Boolean).length;
}

// ---------------------------------------------------------------------
// Cara sekarang: sudut sendi 3D.
// ---------------------------------------------------------------------
function sub(a, b) {
    return { x: a.x - b.x, y: a.y - b.y, z: a.z - b.z };
}

function length(v) {
    return Math.hypot(v.x, v.y, v.z);
}

// Sudut di titik b antara ruas b->a dan b->c (derajat). 180 = lurus.
export function jointAngle(points, a, b, c) {
    const u = sub(points[a], points[b]);
    const v = sub(points[c], points[b]);
    const n = length(u) * length(v);
    if (n === 0) {
        return 180;
    }
    const cos = (u.x * v.x + u.y * v.y + u.z * v.z) / n;
    return (Math.acos(Math.max(-1, Math.min(1, cos))) * 180) / Math.PI;
}

// Ukuran mentah satu tangan dari worldLandmarks, juga ditampilkan di mode diagnostik.
// angles: [ibu jari (IP), telunjuk, tengah, manis, kelingking (PIP)] dalam derajat.
export function measureHand(world) {
    const palmWidth = length(sub(world[5], world[17])) || 1;
    return {
        angles: [
            jointAngle(world, 2, 3, 4),
            jointAngle(world, 5, 6, 7),
            jointAngle(world, 9, 10, 11),
            jointAngle(world, 13, 14, 15),
            jointAngle(world, 17, 18, 19),
        ],
        thumbSpread: length(sub(world[4], world[17])) / palmWidth,
    };
}

// Batas naik dan turun untuk ukuran setiap jari: [nilai, naikDiAtas, turunDiBawah].
function thresholds(metrics, s) {
    const thumb = s.thumbRule === 'spread'
        ? [metrics.thumbSpread, s.thumbSpreadUpAbove, s.thumbSpreadDownBelow]
        : [metrics.angles[0], s.thumbUpAbove, s.thumbDownBelow];
    return [thumb, ...metrics.angles.slice(1).map((angle) => [angle, s.fingerUpAbove, s.fingerDownBelow])];
}

// Status jari satu tangan dengan hysteresis. Satu objek per tangan yang dilacak.
export class FingerState {
    constructor(settings = FINGER_SETTINGS) {
        this.settings = settings;
        this.states = null;   // [ibu jari, telunjuk, tengah, manis, kelingking]
    }

    // world = worldLandmarks satu tangan (21 titik). Hasil: { states, count, metrics }.
    update(world) {
        const metrics = measureHand(world);
        const limits = thresholds(metrics, this.settings);
        if (this.states === null) {
            // Frame pertama: belum ada status lama, pakai titik tengah kedua ambang.
            this.states = limits.map(([value, up, down]) => value > (up + down) / 2);
        } else {
            // Hysteresis: berubah hanya bila melewati ambang; di antaranya status lama dipertahankan.
            this.states = this.states.map((was, i) => {
                const [value, up, down] = limits[i];
                return value > up ? true : value < down ? false : was;
            });
        }
        return { states: this.states.slice(), count: this.states.filter(Boolean).length, metrics };
    }
}

// Hitung sekali tanpa riwayat (dipakai uji).
export function countFingersAngles(world, settings = FINGER_SETTINGS) {
    return new FingerState(settings).update(world).count;
}

// ---------------------------------------------------------------------
// Posisi tangan di kamera.
// ---------------------------------------------------------------------
const FINGERTIPS = [4, 8, 12, 16, 20];

function outsideFrame(p) {
    return p.x < 0 || p.x > 1 || p.y < 0 || p.y > 1;
}

// points = landmarks gambar (0 sampai 1). Hasil: null bila posisi baik,
// 'cut' bila ada ujung jari di luar gambar, 'low' bila pergelangan terlalu ke bawah.
export function positionProblem(points, settings = FINGER_SETTINGS) {
    if (FINGERTIPS.some((i) => outsideFrame(points[i]))) {
        return 'cut';
    }
    if (outsideFrame(points[0]) || points[0].y > settings.wristBottomMax) {
        return 'low';
    }
    return null;
}

// Apakah tangan dengan masalah posisi ini tidak boleh dipakai menghitung.
export function excludedForCounting(problem, settings = FINGER_SETTINGS) {
    return problem === 'cut' || (problem === 'low' && settings.excludeLowWrist);
}

// ---------------------------------------------------------------------
// Menggabungkan dua tangan.
// ---------------------------------------------------------------------

// hands: [{ screenX, count }] (screenX = posisi pergelangan di layar yang dicerminkan, 0 kiri .. 1 kanan)
export function combineSum(hands) {
    return hands.reduce((sum, hand) => sum + hand.count, 0);
}

// Mode "Tangan kiri +5".
// Tangan kiri dan kanan ditentukan dari POSISI DI LAYAR, bukan label
// Left/Right dari MediaPipe: label itu menebak dari bentuk tangan dan bisa
// tertukar, sedangkan posisi di layar (yang dicerminkan seperti cermin)
// selalu sesuai yang dilihat pemain.
// - Dua tangan: yang lebih kiri di layar = tangan kiri.
// - Satu tangan: dianggap tangan kanan (penghitung). Tangan kiri saja yang
//   terbuka juga bernilai 5, jadi hasilnya sama untuk telapak terbuka.
// leftSwitch: LeftHandSwitch yang menyimpan status terbuka tangan kiri (hysteresis).
export function combineLeftPlus5(hands, leftSwitch) {
    if (hands.length === 0) {
        leftSwitch.update(null);
        return 0;
    }
    if (hands.length === 1) {
        leftSwitch.update(null);
        return hands[0].count;
    }
    const sorted = [...hands].sort((a, b) => a.screenX - b.screenX);
    const left = sorted[0];
    const right = sorted[sorted.length - 1];
    return right.count + (leftSwitch.update(left.count) ? 5 : 0);
}

// Tangan kiri hanya dibedakan terbuka atau tidak, dengan hysteresis.
export class LeftHandSwitch {
    constructor(settings = FINGER_SETTINGS) {
        this.settings = settings;
        this.open = false;
    }

    // count = jumlah jari tangan kiri, atau null bila tangan kiri tidak terlihat.
    update(count) {
        if (count === null) {
            this.open = false;
        } else if (count >= this.settings.leftOpenAtLeast) {
            this.open = true;
        } else if (count <= this.settings.leftClosedAtMost) {
            this.open = false;
        }
        return this.open;
    }
}

// ---------------------------------------------------------------------
// Pelacak tangan antar-frame: menjaga identitas tiap tangan (untuk hysteresis
// per jari) dan masa toleransi bila tangan hilang sesaat.
// ---------------------------------------------------------------------
export class HandSlots {
    constructor(settings = FINGER_SETTINGS) {
        this.settings = settings;
        this.slots = [];   // { screenX, fingerState, last: { count, states, metrics }, lastSeen }
    }

    reset() {
        this.slots = [];
    }

    // detections: hasil detectionsFromResult(). Deteksi yang posisinya tidak
    // boleh dipakai (lihat excludedForCounting) atau tanpa worldLandmarks tidak
    // dihitung; tangan itu diperlakukan seperti hilang sesaat (masa toleransi berlaku).
    // method: 'angles' atau 'legacy'.
    // Hasil: [{ screenX, count, states, metrics, held }] termasuk tangan yang
    // sedang dalam masa toleransi (held = true).
    update(detections, nowMs, method = 'angles') {
        const unused = new Set(this.slots.map((_, i) => i));
        const results = [];

        for (const detection of detections) {
            const usable = method === 'legacy'
                || (!excludedForCounting(detection.problem, this.settings) && detection.world !== null);
            if (!usable) {
                continue;
            }
            // Pasangkan dengan slot terdekat yang belum dipakai.
            let best = -1;
            let bestDistance = this.settings.handMatchDistance;
            for (const i of unused) {
                const d = Math.abs(this.slots[i].screenX - detection.screenX);
                if (d < bestDistance) {
                    best = i;
                    bestDistance = d;
                }
            }
            let slot;
            if (best >= 0) {
                slot = this.slots[best];
                unused.delete(best);
            } else {
                slot = { fingerState: new FingerState(this.settings) };
                this.slots.push(slot);
            }
            slot.screenX = detection.screenX;
            slot.lastSeen = nowMs;
            if (method === 'legacy') {
                const states = legacyFingerStates(detection.points);
                slot.last = { states, count: states.filter(Boolean).length, metrics: null };
            } else {
                slot.last = slot.fingerState.update(detection.world);
            }
            results.push({ screenX: slot.screenX, ...slot.last, held: false });
        }

        // Slot yang tidak terlihat di frame ini: tahan sebentar, lalu buang.
        this.slots = this.slots.filter((slot, i) => {
            if (!unused.has(i)) {
                return true;
            }
            if (nowMs - slot.lastSeen <= this.settings.handGraceMs) {
                results.push({ screenX: slot.screenX, ...slot.last, held: true });
                return true;
            }
            return false;
        });

        // Maksimal dua tangan yang dihitung; tangan yang benar-benar terlihat didahulukan.
        const visible = results.filter((hand) => !hand.held);
        const held = results.filter((hand) => hand.held);
        return [...visible, ...held].slice(0, 2).sort((a, b) => a.screenX - b.screenX);
    }
}

// ---------------------------------------------------------------------
// Satu jalur lengkap: deteksi per frame -> jumlah jari mentah (sebelum debounce).
// Halaman uji kamera menjalankan beberapa jalur sekaligus pada frame yang
// SAMA, sehingga perbandingan cara hitung adil.
// ---------------------------------------------------------------------

// Pilihan yang dikenal. 'standard' dipakai aplikasi; yang lain pilihan di mode
// diagnostik dan pembanding di tes akurasi. 'legacy' sengaja tanpa masa
// toleransi dan tanpa pemeriksaan posisi, persis seperti versi awal (Fase 3).
// label = teks pilihan di halaman uji kamera; short = judul kolom tabel tes.
export const PIPELINES = {
    standard: { method: 'angles', combine: 'sum', label: 'Standar: sudut jari, ibu jari dari jaraknya', short: 'Standar' },
    thumbAngle: { method: 'angles', combine: 'sum', settings: { thumbRule: 'ipAngle' }, label: 'Ibu jari dari sudut sendinya', short: 'Ibu jari sudut' },
    leftPlus5: { method: 'angles', combine: 'leftPlus5', label: 'Tangan kiri +5', short: 'Kiri +5' },
    legacy: { method: 'legacy', combine: 'sum', label: 'Jarak 2D ke pergelangan (versi awal)', short: 'Jarak 2D' },
};

export class GesturePipeline {
    constructor(name = 'standard', settings = FINGER_SETTINGS) {
        const config = PIPELINES[name] || PIPELINES.standard;
        this.name = name;
        this.label = config.label;
        this.method = config.method;
        this.combine = config.combine;
        const effective = { ...settings, ...(config.settings || {}) };
        if (this.method === 'legacy') {
            effective.handGraceMs = 0;
        }
        this.slots = new HandSlots(effective);
        this.leftSwitch = new LeftHandSwitch(effective);
    }

    reset() {
        this.slots.reset();
        this.leftSwitch.update(null);
    }

    // detections: hasil detectionsFromResult(). Hasil: { total, hands }.
    process(detections, nowMs) {
        const hands = this.slots.update(detections, nowMs, this.method);
        const total = this.combine === 'leftPlus5'
            ? combineLeftPlus5(hands, this.leftSwitch)
            : combineSum(hands);
        return { total, hands };
    }
}

// Ubah hasil HandLandmarker (atau rekaman tes akurasi) menjadi daftar deteksi.
// screenX memakai pergelangan; tampilan dicerminkan, jadi posisi di layar = 1 - x.
export function detectionsFromResult(result, settings = FINGER_SETTINGS) {
    const landmarks = result.landmarks || [];
    const world = result.worldLandmarks || [];
    const handedness = result.handedness || result.handednesses || [];
    return landmarks.map((points, i) => ({
        screenX: 1 - points[0].x,
        points,
        world: world[i] && world[i].length === 21 ? world[i] : null,
        problem: positionProblem(points, settings),
        label: handedness[i] && handedness[i][0] ? handedness[i][0].categoryName : '',
        score: handedness[i] && handedness[i][0] ? handedness[i][0].score : 0,
    }));
}
