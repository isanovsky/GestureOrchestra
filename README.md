# GestureOrchestra

GestureOrchestra adalah orkestra mini di browser yang dimainkan dengan jari di depan kamera. Jumlah
jari yang terangkat menentukan bunyi. Bisa dimainkan sendiri (mode solo) atau bersama sampai 4
pemain di perangkat masing-masing (mode room).

Ada tiga instrumen: piano, strings, dan drum. Lagu, bunyi, dan pemetaan jari tersimpan di
database dan dikelola admin.

Proyek ini adalah tugas ETS: website PHP native + database, dengan halaman publik, login
superadmin dan admin, serta CRUD sesuai role.

Daftar uji manual sebelum presentasi ada di [UJI_MANUAL.md](UJI_MANUAL.md).

## Isi

1. [Pembagian peran PHP dan JavaScript](#pembagian-peran-php-dan-javascript)
2. [Struktur folder](#struktur-folder)
3. [Setup lokal dengan XAMPP](#setup-lokal-dengan-xampp)
4. [Database dan migrasi](#database-dan-migrasi)
5. [Deploy ke InfinityFree](#deploy-ke-infinityfree)
6. [Akun awal](#akun-awal)
7. [Alur data: dari jari sampai papan skor](#alur-data-dari-jari-sampai-papan-skor)
8. [Main bersama (room)](#main-bersama-room)
9. [Keamanan](#keamanan)
10. [Uji otomatis](#uji-otomatis)

## Pembagian peran PHP dan JavaScript

Aturan dasarnya: **PHP memegang data dan keputusan, JavaScript memegang kamera dan suara.**
Nilai permainan selalu dihitung server, tidak pernah dipercaya dari browser.

| Bagian | PHP (server) | JavaScript (browser) |
|---|---|---|
| Halaman publik | Merender HTML dari database: instrumen, lagu, papan skor | Tidak ada, kecuali halaman bermain |
| Login dan role | Session, `password_verify`, batas percobaan login, `require_role`, CSRF | Tidak ada |
| CRUD admin dan superadmin | Semua: form, validasi, prepared statement, pesan error | Tidak ada (form HTML biasa) |
| Pemetaan jari ke bunyi | Disimpan di tabel `gestures`, dikirim lewat `api/instrument.php` | Dibaca dari API, tidak di-hardcode |
| Deteksi tangan | Tidak ada | `hand-tracker.js`: MediaPipe dan debounce; `finger-count.js`: cara hitung jari |
| Bunyi | Tidak ada (data frekuensi dan kode bunyi saja) | `audio-engine.js`: Web Audio, sintesis tiga instrumen |
| Lagu dan lajur not | Partitur dari tabel `song_notes` lewat `api/song.php` | `player.js`: hitung mundur, metronom, lajur not, catat event |
| Penilaian | `includes/scoring.php` (fungsi murni) + `api/submit_performance.php` | Hanya mengirim daftar event `{t_ms, sound_id}` |
| Room multiplayer | Kode room, token, status, waktu mulai, hasil (`api/room_*.php`) | `lobby.js`: polling lobby, mulai pada jam server; `clock-sync.js` |

File JavaScript (semuanya ES module, tanpa build step):

| File | Isi |
|---|---|
| `hand-tracker.js` | Memuat MediaPipe (GPU, pindah ke CPU bila GPU lambat), `GestureDebouncer`, pilihan cara membaca jari |
| `finger-count.js` | Cara hitung jari (fungsi murni): sudut sendi 3D, ibu jari, hysteresis, masa toleransi, mode tangan kiri +5 |
| `keymap.js` | Peta tombol keyboard laptop (lihat tabel di bawah), satu-satunya sumber peta tombol |
| `keyboard-input.js` | Keyboard laptop: piano/strings memilih bunyi langsung, drum tombol `1` sampai `9`; lepas = diam |
| `audio-engine.js` | Piano (triangle, meluruh), strings (sawtooth + lowpass, legato), drum sintesis, gema, volume |
| `instrument-view.js` | Gambar tuts piano, deret nada strings, dan set drum; juga berfungsi sebagai pad sentuh |
| `player.js` | Mesin lagu (`GamePlayer`): jadwal klik, lajur not, catatan event untuk dinilai |
| `music.js` | Nama nada, transpose, nama akor (sama dengan `includes/music.php`) |
| `stage-setup.js` | Menyiapkan panggung bermain (dipakai mode solo dan room) |
| `solo.js` | Mode solo: mulai lagu, kirim hasil, simpan ke papan skor |
| `room-forms.js`, `lobby.js` | Form buat/gabung room, lobby, main bersama |
| `clock-sync.js` | Selisih jam perangkat dengan jam server |
| `camera-test.js` | Halaman uji kamera |
| `api.js` | Helper `fetch` JSON |

### Peta tombol keyboard (piano dan strings)

Dipakai `event.code` (posisi fisik tombol), jadi sama di semua layout keyboard. Nada dicari dari
kode bunyi (huruf + oktaf relatif terhadap Do instrumen), bukan dari urutan bunyi.

| Baris | Tombol | Nada |
|---|---|---|
| Oktaf rendah | `Q W E R T Y U` | Do rendah sampai Si rendah |
| Oktaf tengah | `1` sampai `8` | Do sampai Do tinggi |
| Oktaf tinggi | `9 0 - = [ ] \` | Re tinggi sampai Do sangat tinggi |

Tombol hanya aktif bila instrumen punya bunyinya (migrasi 005). Shortcut dengan Ctrl, Alt, atau Cmd
dan tombol yang ditahan lama tidak dihitung. Di mode akor, tombol memilih nada dasar akor. Drum
tetap memakai `1` sampai `9` sebagai jumlah jari. Gestur kamera 1 sampai 9 jari sama dengan tombol
`1` sampai `9` (setelah migrasi 006). Nada lain hanya bisa dimainkan lewat keyboard atau pad; part
yang memakainya ditandai "hanya keyboard".

## Struktur folder

```
/
├── index.php, instruments.php, songs.php, leaderboard.php   halaman publik
├── play.php          mode solo
├── room.php          buat room, gabung room, lobby, main bersama
├── camera-test.php   uji kamera, diagnostik, dan tes akurasi terpandu
├── arranger.php      mode Arranger (iringan otomatis mengikuti akor)
├── login.php, logout.php, 404.php
├── admin/            dashboard dan CRUD konten (role admin)
├── superadmin/       dashboard dan CRUD admin (role superadmin)
├── api/              endpoint JSON untuk JavaScript
├── includes/         bootstrap, auth, csrf, layout, fungsi musik dan penilaian
├── config/           config.example.php (template); config.php TIDAK di-commit
├── database/         schema.sql dan migrations/
├── assets/           css/app.css, js/, img/ (SVG buatan kelompok)
└── tests/            uji otomatis PHP dan JavaScript
```

Folder `config/`, `includes/`, `database/`, dan `tests/` diblokir dari browser oleh `.htaccess`.

## Setup lokal dengan XAMPP

Asumsi: XAMPP terpasang di `D:\xampp`, dengan PHP 8.2 atau lebih baru.

1. Jalankan **Apache** dan **MySQL** dari XAMPP Control Panel.
2. Salin seluruh isi repo ke `D:\xampp\htdocs\gestureorchestra\`, sehingga
   ada `D:\xampp\htdocs\gestureorchestra\index.php`.
3. Buka `http://localhost/phpmyadmin`, buat database baru bernama `gestureorchestra` dengan
   collation `utf8mb4_unicode_ci`.
4. Impor file SQL sesuai bagian [Database dan migrasi](#database-dan-migrasi).
5. Salin `config\config.example.php` menjadi `config\config.php`. Nilai bawaannya sudah cocok
   untuk XAMPP: host `localhost`, user `root`, password kosong, `BASE_URL = '/gestureorchestra'`.
   Untuk pengembangan boleh mengisi `APP_DEBUG = true` supaya detail error tampil.
6. Buka `http://localhost/gestureorchestra/`.

Catatan:

- **Kamera hanya berfungsi di `localhost` atau HTTPS.** Browser menolak akses kamera di alamat
  `http://` lain, misalnya `http://192.168.1.5/...` dari ponsel. Untuk mencoba room di jaringan
  lokal tanpa HTTPS, pakai mode keyboard atau pad sentuh.
- MediaPipe dan modelnya dimuat dari internet (jsdelivr dan storage.googleapis.com), jadi
  deteksi tangan butuh koneksi internet walaupun situsnya lokal.
- Halaman 404 rapi di `.htaccess` memakai path `/404.php`, yang benar untuk InfinityFree. Di
  XAMPP (subfolder `/gestureorchestra`) halaman yang tidak ada akan memakai halaman 404 bawaan
  Apache. Kalau ingin ikut rapi di lokal, ubah baris `ErrorDocument` di `.htaccess` lokal menjadi
  `ErrorDocument 404 /gestureorchestra/404.php`, tapi jangan di-commit.

## Database dan migrasi

Database baru disusun dari `schema.sql`, lalu semua file di `database/migrations/` **sesuai
urutan nomor, masing-masing satu kali**:

1. Buat database kosong (XAMPP: phpMyAdmin, InfinityFree: menu MySQL Databases).
2. Pilih database itu di phpMyAdmin, lalu buka tab **Import**.
3. Di bagian **Partial import**, **matikan** pilihan "Allow the interruption of an import...".
   Bila pilihan ini menyala, phpMyAdmin bisa berhenti di tengah file yang panjang tanpa pesan
   error yang jelas, sehingga sebagian data tidak masuk.
4. Impor berurutan, satu file per kali:
   1. `database/schema.sql`
   2. `database/migrations/002_revisi_musik.sql`
   3. `database/migrations/003_lagu_tambahan.sql`
   4. `database/migrations/004_perbaikan_lagu.sql`
   5. `database/migrations/005_rentang_nada_keyboard.sql`
   6. `database/migrations/006_gestur_sembilan_re_tinggi.sql`
   7. `database/migrations/007_arranger.sql` (tabel style arranger dan 3 style awal)
5. Setiap kali selesai, pastikan muncul pesan sukses hijau sebelum mengimpor file berikutnya.

Catatan:

- `schema.sql` menghapus semua tabel lalu membuat ulang. Jangan diimpor ke database yang
  datanya masih dipakai.
- File migrasi **tidak boleh dijalankan dua kali**. Misalnya migrasi 002 menambah kolom dan
  migrasi 003 dan 005 menambah data, jadi menjalankannya ulang akan gagal atau membuat data
  ganda.
- Database yang sudah berjalan cukup menjalankan migrasi yang belum pernah dijalankan, sesuai
  urutan nomor.
- Bila impor gagal di tengah jalan, cara paling aman adalah mengosongkan database (impor ulang
  `schema.sql`), lalu mengulang semua migrasi dari awal.

Aturan untuk SQL baru. Semua SQL harus bisa berjalan di InfinityFree, yang user database-nya
tidak punya hak istimewa:

- Dilarang memakai `CREATE TEMPORARY TABLE`, stored procedure atau function, trigger, event,
  `CREATE DATABASE`, `USE`, `LOAD DATA`, dan `SET GLOBAL`. XAMPP memakai user `root`, jadi
  pelanggaran aturan ini baru ketahuan di server.
- Tabel bantu di migrasi dibuat sebagai tabel biasa berawalan `mig_tmp_`
  (`DROP TABLE IF EXISTS mig_tmp_x; CREATE TABLE mig_tmp_x (...)`), lalu dihapus di akhir file.
- Migrasi baru diberi nomor berikutnya. File migrasi yang sudah ada tidak diubah.

Tabel utama:

| Tabel | Isi |
|---|---|
| `users` | Akun superadmin dan admin |
| `instruments`, `sounds`, `gestures` | Instrumen, bunyinya, dan jumlah jari untuk tiap bunyi |
| `songs`, `song_parts`, `song_notes` | Lagu, part per instrumen (melodi atau akor), dan partitur dalam satuan ketukan |
| `rooms`, `room_players` | Room main bersama (kedaluwarsa 2 jam) |
| `performances` | Nilai permainan solo dan room |

## Deploy ke InfinityFree

1. **Buat akun hosting.** Di client area InfinityFree buat akun hosting baru dan pilih domain
   (subdomain gratis atau domain sendiri). Pilih PHP 8.x bila ditawarkan.
2. **Buat database.** Buka Control Panel, lalu **MySQL Databases**. Buat database, misalnya
   `gestureorchestra`. Nama lengkapnya akan berawalan `if0_xxxxxxxx_`. Catat empat nilai dari
   halaman itu: **MySQL Host Name** (misalnya `sql123.infinityfree.com`, **bukan** `localhost`),
   **MySQL User Name**, **MySQL Password** (password akun hosting, bisa dilihat di client area),
   dan **nama database**.
3. **Impor SQL.** Dari halaman MySQL Databases klik **phpMyAdmin** di samping database, lalu
   impor file sesuai urutan di [Database dan migrasi](#database-dan-migrasi).
4. **Siapkan `config.php`** di komputer: salin `config/config.example.php` menjadi
   `config/config.php`, lalu isi:
   ```php
   const DB_HOST = 'sql123.infinityfree.com';   // dari panel, bukan localhost
   const DB_NAME = 'if0_xxxxxxxx_gestureorchestra';
   const DB_USER = 'if0_xxxxxxxx';
   const DB_PASS = 'password-akun-hosting';
   const BASE_URL = '';          // file langsung di htdocs
   const APP_DEBUG = false;      // wajib false di produksi
   ```
5. **Upload lewat FileZilla.** Data FTP ada di client area (**FTP Details**): host
   `ftpupload.net`, port `21`, username `if0_xxxxxxxx`, dan password akun hosting. Buka folder
   `htdocs/` di server, hapus file bawaan (`index2.html` dan sejenisnya), lalu upload seluruh isi
   proyek **termasuk** `config/config.php` dan file `.htaccess`. Folder `.git/`, `tests/`, dan
   `.gitignore` tidak perlu diupload. Tampilan file tersembunyi di FileZilla bisa diaktifkan lewat
   menu **Server**, lalu **Force showing hidden files**.
6. **Aktifkan SSL.** Di client area buka **SSL Certificates** dan minta sertifikat gratis untuk
   domain. Ikuti verifikasi DNS (record CNAME) sampai statusnya aktif, lalu instal sertifikat
   lewat Control Panel. Penerbitan sertifikat bisa memakan waktu, jadi jangan menunggu hari
   presentasi. Tanpa HTTPS, kamera tidak bisa dipakai.
7. **Buka situs dengan `https://`.**

Daftar periksa setelah deploy:

- [ ] `https://domainmu/` tampil, gambar dan font termuat.
- [ ] `https://domainmu/config/config.php` membalas 403 (bukan isi file).
- [ ] `https://domainmu/database/schema.sql` dan `https://domainmu/includes/bootstrap.php` membalas 403.
- [ ] `https://domainmu/halaman-tidak-ada` menampilkan halaman 404 dengan maskot.
- [ ] Login superadmin berhasil, lalu **password superadmin langsung diganti**.
- [ ] Halaman Instrumen menampilkan tabel jari dari database.
- [ ] Uji kamera: izin kamera muncul, kerangka tangan terlihat, angka jari berubah.
- [ ] Main solo satu lagu sampai selesai, nilai muncul, simpan ke papan skor berhasil.
- [ ] Buat room di laptop, gabung dari perangkat lain, mulai bersama sampai hasil tampil.
- [ ] Tidak ada pesan error PHP di halaman (bila ada, `APP_DEBUG` masih `true`).

Batasan InfinityFree yang perlu diketahui:

- Maksimal 10 MB per file. Karena itu file MediaPipe tidak disimpan di repo, tapi dimuat dari CDN.
- Ada batas hit harian. Polling lobby room (setiap 2 detik per pemain) termasuk hit, jadi
  jangan membiarkan tab lobby terbuka berjam-jam.
- InfinityFree memasang pemeriksaan browser (cookie `__test`). Browser biasa lolos otomatis,
  tetapi `curl` atau aplikasi lain ke `api/` akan mendapat halaman HTML, bukan JSON.

## Akun awal

| Role | Username | Password |
|---|---|---|
| Superadmin | `superadmin` | `Superadmin#2026` |

Akun admin dibuat oleh superadmin lewat menu **Kelola admin**.

**Peringatan:** password di atas tercantum di repo publik. Segera ganti lewat menu **Ganti
password** setelah impor database, terutama di InfinityFree. Setelah 5 kali gagal login dalam 10
menit, login ditolak sementara untuk session tersebut.

## Alur data: dari jari sampai papan skor

Alur mode solo, langkah demi langkah:

```
Kamera
  -> hand-tracker.js   MediaPipe HandLandmarker (2 tangan, mode VIDEO), 21 titik 3D per tangan
  -> finger-count.js   telunjuk..kelingking: sudut sendi PIP 3D, terangkat di atas 140 derajat,
                       ditekuk di bawah 128 (hysteresis); ibu jari: jarak ke pangkal kelingking
                       tangan dengan ujung jari keluar gambar: tidak dihitung frame itu
                       tangan hilang < 150 ms: nilai terakhirnya tetap dipakai
                       gabung dua tangan: jumlah (0..10) atau tangan kiri +5
  -> hand-tracker.js   GestureDebouncer: angka baru dipakai setelah stabil sekitar 100 ms
  -> onGestureChange(jumlahJari)            (keyboard drum memanggil callback yang sama;
                                              keyboard piano/strings dan pad memilih bunyi langsung)
  -> stage-setup.js    jumlah jari -> bunyi, memakai pemetaan dari api/instrument.php
  -> audio-engine.js   bunyikan (piano meluruh, strings ditahan, drum sekali pukul)
  -> player.js         catat event { t_ms: milidetik sejak lagu mulai, sound_id }
  -- lagu selesai --
  -> POST api/submit_performance.php        hanya event, bukan nilai
  -> includes/scoring.php
       melodi: nada kena bila ada event dengan sound_id sama dalam +-250 ms;
               setiap event hanya boleh dipakai sekali
       akor  : akor kena bila akor yang benar ditahan minimal 50% dari durasinya
       accuracy = kena / jumlah nada * 100
  -> tabel performances  (nickname disimpan bila pemain menekan "Simpan ke papan skor")
  -> leaderboard.php     10 nilai tertinggi per lagu, instrumen, gaya, dan tempo
```

Hal yang sering ditanyakan:

- **Kenapa nilai dihitung di server?** Kalau browser yang mengirim nilai, siapa pun bisa mengirim
  100% lewat konsol. Server hanya menerima event, lalu mencocokkannya dengan partitur dari
  database. Server juga memeriksa jumlah event, rentang `t_ms`, dan apakah `sound_id` milik
  instrumen yang benar.
- **Kenapa sudut sendi 3D?** Sudut di sendi tengah jari (PIP) dihitung dari titik 3D MediaPipe
  (worldLandmarks), jadi tetap sama walaupun tangan miring, condong, atau menoleh. Koordinat
  gambar 2D tidak dipakai: jari yang terlipat ke arah kamera terlihat lurus di 2D. Ibu jari diukur
  dari jarak ujungnya ke pangkal kelingking dibagi lebar telapak (terlipat mendekat, terbuka
  menjauh), tanpa label kiri/kanan MediaPipe yang bisa tertukar. Ambang diambil
  dari rekaman tes akurasi di laptop presentasi: cara hitung versi awal 17% benar, cara ini 99%
  (telapak ke kamera) dan 99% (punggung tangan ke kamera).
- **Kenapa ada mode "Tangan kiri +5"?** Hitungan 6 ke atas butuh dua tangan terlacak bersamaan.
  Di mode ini tangan kiri cukup dibedakan terbuka atau tidak, yang jauh lebih mudah dibaca kamera.
  Tangan kiri ditentukan dari sisi layar, bukan label MediaPipe.
- **Kenapa ada debounce?** Hitungan jari dari kamera bergetar antar-frame (misalnya 3, 4, 3, 3).
  Tanpa debounce nada akan berbunyi acak.
- **Kenapa pemetaan jari tidak ditulis di JavaScript?** Supaya admin bisa mengubahnya dari
  dashboard tanpa mengubah kode.

## Main bersama (room)

1. Host membuat room. Server membuat kode 6 karakter tanpa huruf atau angka yang mirip
   (O/0, I/1), `host_token`, dan `player_token`. Token disimpan di `sessionStorage` tab itu.
2. Pemain lain bergabung dengan kode, maksimal 4 pemain. Instrumen boleh sama.
3. Lobby memanggil `api/room_status.php` setiap 2 detik. Setiap panggilan memperbarui
   `last_seen_at`; pemain yang tidak terlihat lebih dari 15 detik ditandai terputus.
4. Setiap pemain menekan "Siapkan instrumen" (browser hanya mengizinkan suara setelah klik),
   lalu otomatis berstatus siap. Host menekan "Mulai bersama", dan server mengisi
   `start_at_ms = jam server + 5000`.
5. Setiap browser berhenti polling, lalu memulai lagu pada `start_at_ms` menurut jam server.
   `clock-sync.js` mengukur selisih jam perangkat dengan server dari 5 sampel `api/time.php`:
   `offset = server_ms - (t_kirim + t_terima) / 2`, memakai sampel dengan waktu bolak-balik
   tersingkat. Ini perlu karena jam tiap perangkat bisa berbeda beberapa detik.
6. Di akhir lagu setiap pemain mengirim event sekali. Lagu, instrumen, dan tempo diambil dari
   database room, bukan dari browser. Hasil semua pemain tampil setelah semua mengirim, atau
   setelah panjang lagu ditambah 20 detik.

Tidak ada audio yang dikirim lewat jaringan. Setiap perangkat membunyikan instrumennya sendiri,
jadi pemain sebaiknya duduk berdekatan. Room kedaluwarsa 2 jam setelah dibuat, dan room
kedaluwarsa dihapus setiap kali room baru dibuat (tidak ada cron).

## Keamanan

- Semua query memakai prepared statement (PDO, `EMULATE_PREPARES = false`).
- Password memakai `password_hash` dan `password_verify`. Setelah login, session ID diganti
  dengan `session_regenerate_id(true)`. Akun nonaktif tidak bisa login.
- Halaman admin memanggil `require_role('admin')`, halaman superadmin memanggil
  `require_role('superadmin')`. Role yang salah mendapat 403; yang belum login diarahkan ke
  halaman login.
- Semua form POST memakai token CSRF. Hapus data hanya lewat POST.
- Semua output dari database melewati `e()` (`htmlspecialchars`).
- Cookie session memakai `httponly`, `SameSite=Lax`, dan `secure` bila diakses lewat HTTPS.
- Saat `APP_DEBUG = false`, error tidak menampilkan detail teknis; detail dicatat dengan `error_log()`.
- Endpoint API memvalidasi tipe, panjang, dan rentang input, lalu membalas JSON dengan kode HTTP
  yang sesuai.

## Mode Arranger

Pemain memilih akor dengan tangan kiri, browser memainkan iringan (drum, bass, akor) yang
mengikuti akor itu, dan pemain memainkan melodi (piano atau strings) dengan tangan kanan.
Tanpa kamera, tanpa nilai, tanpa request ke server selama bermain (data style ikut dicetak di
halaman).

| Tombol | Fungsi |
|---|---|
| `Z X C V B N M` | Akor I ii iii IV V vi vii° (di Do = C: C Dm Em F G Am Bdim), ikut nada dasar |
| `Shift` + tombol akor | Balik mayor/minor (Dm jadi D, C jadi Cm, Bdim jadi Bm). Tombol "Mayor/minor" di layar mengunci pembalikan |
| `A S D F G` | Intro, Main A, Main B, Fill, Ending |
| `Spasi` | Mulai / berhenti (berhenti = masuk Ending; tekan lagi = berhenti seketika) |
| Panah atas/bawah | Tempo plus/minus 5 bpm |
| Panah kiri/kanan | Nada dasar turun/naik (berlaku di akor berikutnya) |
| `Q`-`U`, `1`-`8`, `9`-`\` | Melodi, sama dengan peta tombol di atas |

Aturan waktu: akor baru berlaku di seperdelapan ketukan berikutnya; nada panjang yang masih
berbunyi dipotong dan dibunyikan ulang dengan akor baru. Bagian baru berlaku di awal birama
berikutnya. Intro dan Fill lanjut ke Main setelah selesai, Main diulang, Ending menghentikan
iringan. Akor dikunci: cukup ditekan sekali, tidak perlu ditahan (keyboard laptop sering gagal
membaca banyak tombol yang ditekan bersamaan).

Penjadwalan memakai pola look-ahead scheduler Web Audio (`assets/js/arranger.js`): timer 25 ms
menjadwalkan bunyi 100 ms ke depan dengan `AudioContext.currentTime`. Logika bagian dan akor ada
di `assets/js/arranger-engine.js` (murni, diuji tanpa browser).

Style dikelola admin (menu "Style arranger"): nama, tempo awal, ketukan per birama, suara akor
(piano, pad, atau petik), lalu pola untuk setiap bagian dan track. Format pola, satu kejadian per
baris (`#` = komentar):

```
drum : <ketukan> <KODE> [velocity]           1 SNARE 0.8
bass : <ketukan> <nada> [durasi] [velocity]  2 5- 1 0.8
akor : <ketukan> <nada> [durasi] [velocity]  0 R35 1 0.6
```

Ketukan dari 0 di awal bagian (desimal `1.5` atau pecahan `4/3`). Nada mengikuti akor aktif:
`R` dasar, `3` terts, `5` kuint, `8` oktaf, akhiran `-` satu oktaf lebih rendah. Kode drum:
KICK, SNARE, HIHAT, OHAT, RIM, TOM, CRASH, CYMBAL, KDUT dan KTAK (kendang dut dan tak), TAMB
(tamborin). Pola divalidasi saat disimpan (`includes/arranger.php`) dengan aturan yang sama
dengan pembaca di browser (`assets/js/arranger-pattern.js`).

## Uji otomatis

Uji ini dijalankan dari terminal dan tidak butuh server web. Uji PHP butuh PHP CLI, uji
JavaScript butuh Node.js 18 atau lebih baru.

```
php tests/scoring_and_import_test.php
node --test tests/js/*.test.mjs
```

- `tests/scoring_and_import_test.php`: penilaian melodi dan akor, importer not angka
  (hasilnya dibandingkan dengan isi migrasi), pembaca pola Arranger, dan aturan SQL InfinityFree.
- `tests/js/`: hitung jari (semua cara hitung, dengan tangan sintetis), debounce, fungsi musik,
  mesin lagu `player.js`, serta pembaca pola dan penjadwal Arranger.
- `tests/fixtures/arranger-patterns.json`: kasus pola yang dijalankan di uji PHP dan JS, supaya
  validasi admin dan pemutar di browser memakai aturan yang sama. Pola awal di migrasi 007 juga
  diperiksa di kedua sisi.

Membandingkan cara hitung jari:

```
node tests/js/evaluate-finger-count.mjs                 data tangan sintetis
node tests/js/evaluate-finger-count.mjs rekaman.json    rekaman dari tes akurasi di Uji kamera
```

Rekaman diunduh dari halaman Uji kamera setelah tes akurasi terpandu. Isinya titik-titik tangan
per frame (bukan video). Ambang cara hitung ada di `FINGER_SETTINGS` di `assets/js/finger-count.js`.

Lisensi dan atribusi kode pihak ketiga ada di [THIRD_PARTY_NOTICES.md](THIRD_PARTY_NOTICES.md).
