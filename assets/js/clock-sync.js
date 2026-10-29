// Sinkronisasi jam dengan server.
//
// Kenapa perlu: jam setiap perangkat bisa berbeda beberapa detik (bahkan
// lebih), karena tidak semua perangkat menyetel jamnya dari internet.
// Kalau setiap pemain memulai lagu menurut jam perangkatnya sendiri, lagu
// di laptop A dan B bisa meleset beberapa detik. Karena itu semua pemain
// memakai JAM SERVER: host meminta server mengisi start_at_ms (waktu server),
// lalu setiap perangkat menghitung kapan waktu server itu terjadi menurut
// jamnya sendiri.
//
// Cara menghitung selisih (offset):
//   t_kirim  = jam perangkat saat request dikirim
//   server_ms = jam server saat menjawab (kira-kira di tengah perjalanan)
//   t_terima = jam perangkat saat jawaban tiba
//   offset   = server_ms - (t_kirim + t_terima) / 2
// Perjalanan request bisa lambat sebelah, jadi diambil 5 sampel dan dipakai
// sampel dengan waktu bolak-balik (round-trip) terkecil: makin cepat
// bolak-baliknya, makin kecil kemungkinan salah taksir.

import { baseUrl } from './api.js';

export const SAMPLE_COUNT = 5;

// Ambil beberapa sampel lalu kembalikan { offset, rtt } dari sampel terbaik.
export async function syncClock(samples = SAMPLE_COUNT) {
    let best = null;
    for (let i = 0; i < samples; i++) {
        const sent = Date.now();
        const response = await fetch(baseUrl() + '/api/time.php', { cache: 'no-store' });
        const data = await response.json();
        const received = Date.now();
        const rtt = received - sent;
        const offset = data.server_ms - (sent + received) / 2;
        if (best === null || rtt < best.rtt) {
            best = { offset, rtt };
        }
    }
    return best;
}

// Jam server saat ini menurut perangkat ini.
export function serverNow(offset) {
    return Date.now() + offset;
}

// Ubah waktu server (ms) menjadi waktu performance.now() perangkat ini,
// karena audio dan animasi memakai jam performance.now().
export function serverToPerformance(serverMs, offset) {
    return performance.now() + (serverMs - serverNow(offset));
}
