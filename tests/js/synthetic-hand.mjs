// Tangan sintetis untuk menguji cara hitung jari tanpa kamera.
// Hanya dipakai uji dan evaluasi di terminal (Node.js), tidak dipakai di situs.
//
// Model sederhana: kerangka 21 titik dengan panjang ruas tulang tangan dewasa,
// sudut tekuk tiap sendi, lalu tangan diputar (miring, condong, menoleh) dan
// diproyeksikan ke gambar 640x480 seperti kamera laptop. Hasilnya dua bentuk
// data yang sama dengan keluaran MediaPipe:
//   points : koordinat gambar ternormalisasi (x, y dari 0 sampai 1), seperti result.landmarks
//   world  : koordinat 3D dalam meter, seperti result.worldLandmarks
//
// Model ini tidak meniru kesalahan MediaPipe yang sebenarnya. Angka dari model
// ini hanya menunjukkan kelemahan geometris sebuah cara hitung (misalnya saat
// tangan miring); akurasi sebenarnya harus diukur dengan tes terpandu di
// camera-test.php.

const FRAME_W = 640;
const FRAME_H = 480;
const FOCAL_PX = 600;

// Generator acak yang bisa diulang (hasil evaluasi sama setiap dijalankan).
export function makeRandom(seed = 1) {
    let s = seed >>> 0;
    const next = () => {
        s = (s + 0x6D2B79F5) >>> 0;
        let t = s;
        t = Math.imul(t ^ (t >>> 15), t | 1);
        t ^= t + Math.imul(t ^ (t >>> 7), t | 61);
        return ((t ^ (t >>> 14)) >>> 0) / 4294967296;
    };
    return {
        next,
        range: (min, max) => min + (max - min) * next(),
        gauss: (sd) => {
            const u = Math.max(next(), 1e-9);
            return sd * Math.sqrt(-2 * Math.log(u)) * Math.cos(2 * Math.PI * next());
        },
    };
}

const deg = (d) => (d * Math.PI) / 180;

function rotX(v, a) {
    const c = Math.cos(a), s = Math.sin(a);
    return { x: v.x, y: v.y * c - v.z * s, z: v.y * s + v.z * c };
}
function rotY(v, a) {
    const c = Math.cos(a), s = Math.sin(a);
    return { x: v.x * c + v.z * s, y: v.y, z: -v.x * s + v.z * c };
}
function rotZ(v, a) {
    const c = Math.cos(a), s = Math.sin(a);
    return { x: v.x * c - v.y * s, y: v.x * s + v.y * c, z: v.z };
}
const add = (a, b) => ({ x: a.x + b.x, y: a.y + b.y, z: a.z + b.z });
const scale = (v, k) => ({ x: v.x * k, y: v.y * k, z: v.z * k });

// Tangan kanan, telapak menghadap kamera, jari ke atas (+y), ibu jari di sisi -x,
// +z mengarah ke kamera. Satuan meter, pergelangan di titik (0, 0, 0).
const FINGER_BASES = [
    { mcp: 5, base: { x: -0.022, y: 0.085, z: 0 }, len: [0.040, 0.024, 0.020], spread: -8 },
    { mcp: 9, base: { x: 0.000, y: 0.090, z: 0 }, len: [0.045, 0.028, 0.021], spread: 0 },
    { mcp: 13, base: { x: 0.018, y: 0.084, z: 0 }, len: [0.042, 0.026, 0.020], spread: 6 },
    { mcp: 17, base: { x: 0.034, y: 0.072, z: 0 }, len: [0.033, 0.019, 0.018], spread: 14 },
];
const THUMB_CMC = { x: -0.022, y: 0.022, z: 0.008 };
const THUMB_LEN = [0.040, 0.032, 0.027];

// Rantai ruas: mulai dari titik awal dengan arah awal, setiap sendi menekuk
// (berputar) ke arah kamera sebesar flex[i] derajat terhadap sumbu tekuk.
function chain(start, dir, axis, lengths, flex) {
    const out = [];
    let p = start;
    let d = dir;
    for (let i = 0; i < lengths.length; i++) {
        d = rotateAround(d, axis, deg(flex[i]));
        p = add(p, scale(d, lengths[i]));
        out.push(p);
    }
    return out;
}

// Rodrigues: putar vektor v di sekitar sumbu satuan k.
function rotateAround(v, k, a) {
    const c = Math.cos(a), s = Math.sin(a);
    const dot = v.x * k.x + v.y * k.y + v.z * k.z;
    const cross = { x: k.y * v.z - k.z * v.y, y: k.z * v.x - k.x * v.z, z: k.x * v.y - k.y * v.x };
    return {
        x: v.x * c + cross.x * s + k.x * dot * (1 - c),
        y: v.y * c + cross.y * s + k.y * dot * (1 - c),
        z: v.z * c + cross.z * s + k.z * dot * (1 - c),
    };
}

function normalize(v) {
    const n = Math.hypot(v.x, v.y, v.z);
    return { x: v.x / n, y: v.y / n, z: v.z / n };
}

// Sudut tekuk acak untuk jari yang lurus atau ditekuk.
// "loose" = jari lurus yang agak santai (lebih menekuk), seperti tangan sungguhan.
function fingerFlex(rand, extended, loose) {
    if (extended) {
        const k = loose ? 2 : 1;
        return [rand.range(-5, 12 * k), rand.range(0, 12 * k), rand.range(0, 10 * k)];
    }
    return [rand.range(65, 90), rand.range(85, 110), rand.range(40, 70)];
}

// Buat satu tangan.
// extended : { thumb, index, middle, ring, pinky } true/false
// pose     : { roll, pitch, yaw } derajat; roll = miring di bidang layar,
//            pitch = jari condong ke/menjauh kamera, yaw = telapak menoleh
// options  : { mirror (tangan kiri), offsetX (posisi di gambar), depth (m), loose }
export function makeHand(rand, extended, pose, options = {}) {
    const { mirror = false, offsetX = 0, depth = 0.5, loose = false } = options;
    const local = Array.from({ length: 21 }, () => ({ x: 0, y: 0, z: 0 }));

    FINGER_BASES.forEach((finger, i) => {
        const name = ['index', 'middle', 'ring', 'pinky'][i];
        local[finger.mcp] = finger.base;
        const dir = rotZ({ x: 0, y: 1, z: 0 }, deg(-finger.spread + rand.range(-3, 3)));
        const axis = normalize({ x: -dir.y, y: dir.x, z: 0 });   // tekuk ke arah +z (kamera)
        const joints = chain(finger.base, dir, { x: -axis.x, y: -axis.y, z: -axis.z }, finger.len, fingerFlex(rand, extended[name], loose));
        joints.forEach((point, j) => {
            local[finger.mcp + 1 + j] = point;
        });
    });

    // Ibu jari. Terbuka: menjauh dari telapak ke samping dan sedikit ke depan
    // (rantai sendi yang menekuk sedikit). Tertutup: arah tiap ruas ditulis
    // langsung, karena ibu jari yang menekuk juga berputar menyilang telapak
    // (oposisi), sesuatu yang tidak bisa ditiru rantai tekuk sederhana.
    local[1] = THUMB_CMC;
    if (extended.thumb) {
        const spread = loose ? rand.range(25, 50) : rand.range(40, 65);
        const dir = normalize(rotX(rotZ({ x: 0, y: 1, z: 0 }, deg(spread)), deg(rand.range(10, 30))));
        const flex = loose ? [rand.range(0, 25), rand.range(0, 25), rand.range(0, 20)] : [rand.range(0, 10), rand.range(0, 12), rand.range(0, 10)];
        const axis = normalize({ x: -dir.y, y: dir.x, z: 0.0001 });
        chain(THUMB_CMC, dir, { x: -axis.x, y: -axis.y, z: -axis.z }, THUMB_LEN, flex)
            .forEach((point, j) => {
                local[2 + j] = point;
            });
    } else {
        // Tiga gaya, peluangnya sama:
        // - menyilang telapak sampai dekat pangkal kelingking (kepalan),
        // - menahan jari manis dan kelingking yang ditekuk (1 sampai 3 jari),
        // - ditekan ke telapak dekat pangkal telunjuk (4 jari).
        // Arah ruas terakhir disetel supaya sudut sendi IP sekitar 115 sampai 140
        // derajat, sesuai rekaman kamera sungguhan (ibu jari terlipat 106 sampai 140).
        const styles = [
            [{ x: 0.35, y: 0.75, z: 0.55 }, { x: 0.85, y: 0.40, z: -0.20 }, { x: 0.70, y: -0.35, z: -0.65 }],
            [{ x: 0.30, y: 0.80, z: 0.55 }, { x: 0.70, y: 0.55, z: 0.20 }, { x: 0.65, y: 0.15, z: -0.55 }],
            [{ x: 0.10, y: 0.85, z: 0.50 }, { x: 0.35, y: 0.85, z: 0.10 }, { x: 0.55, y: 0.45, z: -0.65 }],
        ];
        const dirs = styles[Math.floor(rand.next() * styles.length)];
        let point = THUMB_CMC;
        dirs.forEach((d, j) => {
            const jittered = normalize({ x: d.x + rand.range(-0.08, 0.08), y: d.y + rand.range(-0.08, 0.08), z: d.z + rand.range(-0.08, 0.08) });
            point = add(point, scale(jittered, THUMB_LEN[j]));
            local[2 + j] = point;
        });
    }

    // Putar seluruh tangan.
    const rotated = local.map((v) => {
        let r = rotX(v, deg(pose.pitch));
        r = rotY(r, deg(pose.yaw));
        r = rotZ(r, deg(-pose.roll));
        return mirror ? { x: -r.x, y: r.y, z: r.z } : r;
    });

    // Koordinat dunia (seperti worldLandmarks): titik asal kira-kira di tengah tangan.
    const center = scale(rotated.reduce((a, b) => add(a, b)), 1 / 21);
    const world = rotated.map((v) => ({ x: v.x - center.x, y: -(v.y - center.y), z: -(v.z - center.z) }));

    // Proyeksi ke gambar. Pergelangan diletakkan di bawah tengah gambar.
    const anchor = { x: offsetX, y: -0.06, z: 0 };
    const points = rotated.map((v) => {
        const X = v.x + anchor.x;
        const Y = v.y + anchor.y;
        const Z = depth - v.z;
        return {
            x: 0.5 + (FOCAL_PX * X) / Z / FRAME_W,
            y: 0.5 - (FOCAL_PX * Y) / Z / FRAME_H,
            z: -v.z / depth,
        };
    });
    return { points, world };
}

// Tambahkan derau seperti hasil deteksi sungguhan.
// imagePx = simpangan baku di gambar (piksel), worldMm = di koordinat dunia (mm).
export function addNoise(rand, hand, imagePx = 2, worldMm = 2) {
    return {
        points: hand.points.map((p) => ({
            x: p.x + rand.gauss(imagePx) / FRAME_W,
            y: p.y + rand.gauss(imagePx) / FRAME_H,
            z: p.z,
        })),
        world: hand.world.map((p) => ({
            x: p.x + rand.gauss(worldMm / 1000),
            y: p.y + rand.gauss(worldMm / 1000),
            z: p.z + rand.gauss((worldMm * 2) / 1000),   // kedalaman paling tidak pasti
        })),
    };
}

// Jari yang terangkat untuk hitungan 0 sampai 5 pada satu tangan.
// Urutan baku yang diajarkan: telunjuk, tengah, manis, kelingking, lalu ibu jari.
export function extendedFor(count) {
    const order = ['index', 'middle', 'ring', 'pinky', 'thumb'];
    const result = { thumb: false, index: false, middle: false, ring: false, pinky: false };
    order.slice(0, count).forEach((name) => {
        result[name] = true;
    });
    return result;
}
