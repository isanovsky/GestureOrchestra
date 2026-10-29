# Daftar Uji Manual

Daftar ini untuk dijalankan sebelum presentasi: sekali di XAMPP, lalu sekali lagi di InfinityFree
(HTTPS). Bagian A sampai D adalah syarat inti tugas, jadi kerjakan lebih dulu. Bagian E sampai H
adalah nilai tambah.

Persiapan:

- Database baru sudah diimpor sesuai README (schema, lalu semua migrasi 002 sampai 005).
- Pakai Chrome atau Edge terbaru di laptop dengan kamera.
- Siapkan satu jendela biasa dan satu jendela Incognito, supaya bisa login sebagai dua role
  sekaligus dan bermain sebagai dua pemain room.

Cara mencatat hasil: centang kotak bila lolos. Bila gagal, tulis URL, langkah, dan pesan yang
muncul di samping kotak.

## A. Halaman publik (tanpa login)

- [ ] Beranda tampil lengkap: penjelasan, tiga instrumen, cara main, tombol "Main Solo" dan "Main Bersama".
- [ ] Menu atas mengarah ke halaman yang benar, dan halaman aktif bergaris bawah.
- [ ] **Instrumen**: tiga instrumen aktif dengan tabel jari ke bunyi. Ubah satu gestur di admin, lalu muat ulang: tabel ikut berubah.
- [ ] **Lagu**: hanya lagu berstatus tayang yang muncul. Sembunyikan satu lagu di admin, lalu pastikan lagu itu hilang dari halaman ini.
- [ ] **Papan skor**: pilih lagu dan tempo. Setiap part menampilkan paling banyak 10 nilai, dan part kosong menampilkan "Belum ada yang main...".
- [ ] URL yang tidak ada (misalnya `/tidak-ada.php`) menampilkan halaman 404. Di InfinityFree halamannya memakai maskot; di XAMPP subfolder, halaman 404 bawaan Apache tidak apa-apa (lihat README).
- [ ] `config/config.php`, `includes/bootstrap.php`, dan `database/schema.sql` membalas 403.
- [ ] Ponsel atau mode responsif browser di lebar 360 px: tidak ada scroll ke samping, dan menu terlipat rapi.

## B. Login dan role

- [ ] Login `superadmin` dengan password benar diarahkan ke dashboard superadmin.
- [ ] Password salah memunculkan pesan kesalahan tanpa menyebut apakah username-nya ada.
- [ ] Salah password 5 kali berturut-turut membuat login ditolak sementara, dengan pesan berapa menit harus menunggu.
- [ ] Belum login lalu membuka `admin/index.php` diarahkan ke halaman login.
- [ ] Login sebagai admin lalu membuka `superadmin/index.php` mendapat halaman 403 yang rapi. Sebaliknya, superadmin yang membuka `admin/index.php` juga mendapat 403.
- [ ] Keluar (logout), tekan tombol Back, lalu muat ulang halaman: kembali diarahkan ke login, dan form admin tidak bisa disimpan.
- [ ] Akun admin yang dinonaktifkan superadmin tidak bisa login.

## C. Superadmin: kelola admin

- [ ] Dashboard menampilkan angka ringkasan (jumlah admin, lagu, permainan hari ini).
- [ ] Tambah admin: semua isian wajib divalidasi, password minimal 8 karakter, dan username atau email kembar ditolak dengan pesan yang jelas.
- [ ] Ubah admin tanpa mengisi password: password lama tetap berlaku.
- [ ] Ubah admin dengan password baru: admin bisa login memakai password baru.
- [ ] Nonaktifkan lalu aktifkan kembali seorang admin.
- [ ] Hapus admin: muncul konfirmasi, data terhapus, dan pesan sukses tampil.
- [ ] Superadmin tidak bisa menghapus atau menonaktifkan akunnya sendiri.
- [ ] Ganti password superadmin: password lama salah ditolak, ulangi password yang tidak sama ditolak.

## D. Admin: kelola konten

Instrumen, bunyi, dan gestur:

- [ ] Tambah instrumen baru, ubah warnanya, lalu pastikan warna baru dipakai di halaman publik.
- [ ] Tambah bunyi pada instrumen piano tanpa frekuensi: ditolak. Pada drum tanpa frekuensi: diterima.
- [ ] Tambah gestur dengan jumlah jari yang sudah dipakai: ditolak dengan pesan jelas.
- [ ] Hapus bunyi yang masih dipakai di partitur: ditolak dengan pesan yang bisa dipahami, bukan error SQL.

Lagu dan partitur:

- [ ] Tambah lagu baru (judul, tempo, ketukan per birama, nada dasar).
- [ ] Tambah part piano melodi, lalu isi lewat **Impor not angka** (pratinjau dulu, lalu simpan).
- [ ] **Edit per nada**: tambah, ubah, dan hapus satu baris nada. Nada yang bertumpuk ditolak.
- [ ] Tayangkan lagu: lagu muncul di halaman Lagu dan bisa dimainkan. Sembunyikan: lagu hilang lagi.
- [ ] Hapus lagu uji: lagu, partitur, dan nilai permainannya ikut terhapus (muncul konfirmasi dulu).

Nilai permainan:

- [ ] Halaman **Nilai permainan** bisa disaring per lagu dan instrumen.
- [ ] Hapus satu nilai yang tidak wajar: nilai itu hilang dari papan skor publik.

## E. Uji kamera

- [ ] Browser meminta izin kamera, video tampil dicerminkan, dan kerangka tangan tergambar.
- [ ] Angka jari mengikuti tangan: satu tangan 0 sampai 5, dua tangan sampai 10, kepalan berarti 0.
- [ ] Penggeser debounce mengubah seberapa cepat angka berganti.
- [ ] Kartu tangan menampilkan sisi layar, label MediaPipe, dan lima indikator jari yang menyala sesuai jari yang terangkat.
- [ ] Bagian Diagnostik menampilkan prosesor (GPU atau CPU) dan fps. Catat fps untuk "Otomatis" dan "CPU saja".
- [ ] Tes akurasi terpandu berjalan naik 0 sampai 10 lalu turun 9 sampai 0, lalu tabel muncul. "Salin tabel" dan "Unduh rekaman" berfungsi.
- [ ] Ulangi tes dengan pilihan "Punggung tangan ke kamera", lalu bandingkan.
- [ ] Ujung jari dikeluarkan dari tepi kamera: muncul peringatan, dan tangan itu tidak dihitung.
- [ ] Pergelangan di bawah garis oranye: muncul peringatan, jari tetap dihitung.
- [ ] Cara "Tangan kiri +5": tangan kiri terbuka + tangan kanan 2 jari terbaca 7.
- [ ] Kamera ditolak atau tidak ada: muncul pesan ramah, bukan halaman kosong.

## F. Mode solo

- [ ] Main bebas piano: angkat 3 jari, terdengar Mi; kepalkan tangan, bunyi berhenti.
- [ ] Strings berbunyi selama jari ditahan, dan berganti nada tanpa putus.
- [ ] Drum: setiap jumlah jari membunyikan bagian drum yang berbeda.
- [ ] Mode keyboard piano/strings: `Q` sampai `U` = Do rendah sampai Si rendah, `1` sampai `8` = Do sampai Do tinggi, `9 0 - = [ ] \` = Re tinggi sampai Do sangat tinggi. Lepas = diam.
- [ ] `T` = Sol rendah, `9` = Re tinggi. Ctrl+1 tidak membunyikan nada.
- [ ] Mode keyboard drum: `1` sampai `9` seperti jumlah jari.
- [ ] Mode akor: `Q` membunyikan akor C oktaf rendah; setelah nada dasar dinaikkan 2, menjadi D.
- [ ] Tuts piano C3 sampai C6 dan tiga baris strings tampil, dengan label tombol di mode keyboard. Klik atau sentuh tuts juga berbunyi.
- [ ] Strings di oktaf tertinggi (`[`, `]`, `\`) tidak terdengar menusuk.
- [ ] Ode to Joy (setelah migrasi 006): part melodi piano dan strings bertanda "hanya keyboard"; di lajur, nada Sol rendah menampilkan huruf `T`.
- [ ] Bisa berpindah antara Kamera dan Keyboard/pad kapan saja.
- [ ] Main satu lagu dengan lagu: ada klik hitung mundur, lajur not bergerak, dan tuts berikutnya ditandai.
- [ ] Di akhir lagu nilai tampil, lalu simpan dengan nickname. Nama itu muncul di papan skor.
- [ ] Tempo latihan di bawah 100%: nilai disimpan di papan skor tempo itu, bukan di 100%.
- [ ] Mode panggung (layar penuh) bisa dibuka dan ditutup. Di laptop 1366x768 panggung muat satu layar.

## G. Main bersama (room)

Gunakan dua jendela (biasa dan Incognito) atau dua perangkat.

- [ ] Buat room: kode 6 karakter tampil di lobby, dan pengaturan nada dasar dan tempo hanya terlihat oleh host.
- [ ] Gabung dengan kode huruf kecil: tetap diterima.
- [ ] Gabung dengan nickname yang sudah dipakai di room itu: ditolak.
- [ ] Pemain kelima: ditolak karena room penuh.
- [ ] Kode salah: muncul pesan bahwa room tidak ditemukan.
- [ ] Daftar pemain di lobby diperbarui sendiri, paling lambat sekitar 2 detik.
- [ ] Host mengubah tempo atau nada dasar: tampilan pemain lain ikut berubah.
- [ ] Tutup tab seorang pemain: dalam sekitar 15 detik pemain itu bertanda "Terputus".
- [ ] Tombol "Mulai bersama" baru aktif setelah semua pemain yang terhubung menekan "Siapkan instrumen".
- [ ] Setelah mulai, hitung mundur di semua perangkat berbunyi bersamaan, dan lagu dimulai serentak.
- [ ] Di akhir lagu, setiap perangkat menampilkan nilainya sendiri, lalu nilai semua pemain.
- [ ] Pemain yang tabnya ditutup bertanda "tidak mengirim" setelah batas waktu, sementara nilai pemain lain tetap tampil.
- [ ] Host keluar saat masih di lobby: pemain lain mendapat pesan room ditutup.
- [ ] Host keluar setelah lagu dimulai: pemain lain tetap bisa mengirim nilai dan melihat hasil.

- [ ] Tata letak saat lagu berjalan (solo dan room sama): lajur partitur paling atas, lalu kamera dan panel samping, lalu visual instrumen, lalu baris kontrol. Muat tanpa scroll di 1366x768 dan 1920x1080, juga di Mode panggung.
- [ ] Saat lagu mulai, Contekan jari, Tombol keyboard, dan Pengaturan tertutup; bisa dibuka lagi; setelah lagu selesai terbuka kembali.
- [ ] Di room, strip pemain tipis di baris kontrol menampilkan nama, warna instrumen, dan status.
- [ ] Mode keyboard menampilkan satu baris petunjuk; visual instrumen memakai tempat kamera.

## G2. Arranger (setelah migrasi 007)

- [ ] Menu "Arranger" tampil; pilihan style berisi Ballad, Pop, Dangdut.
- [ ] Klik "Nyalakan arranger", tekan `Spasi`: iringan berbunyi, lampu ketukan berjalan, tombol Main A menyala.
- [ ] Tekan `V` di tengah birama: akor berganti ke F dalam waktu kurang dari setengah ketukan; bass ikut pindah.
- [ ] `Shift` + `N`: akor A (mayor). Tombol "Mayor/minor" di layar: semua nama akor di tombol terbalik.
- [ ] `D` saat Main A: tombol Main B bertanda menunggu, lalu berpindah di awal birama berikutnya.
- [ ] `F`: Fill satu birama, lalu kembali ke Main yang dipilih.
- [ ] `A` saat berhenti lalu `Spasi`: mulai dari Intro, lalu Main.
- [ ] `Spasi` saat bermain: masuk Ending di birama berikutnya lalu berhenti. `Spasi` dua kali: berhenti seketika.
- [ ] Panah atas/bawah mengubah tempo tanpa iringan tersendat; panah kiri/kanan mengubah nada dasar.
- [ ] Melodi piano dan strings lewat `Q`-`U`, `1`-`8`, `9`-`\`, juga dengan klik tuts.
- [ ] Centang "Mulai bersama akor pertama": iringan mulai saat tombol akor pertama ditekan.
- [ ] Volume Drum/Bass/Akor bisa diatur terpisah.
- [ ] Di 1366x768 dan 1920x1080 halaman Arranger muat tanpa scroll.
- [ ] Admin, menu "Style arranger": tambah style, tulis pola yang salah (misalnya `9 KICK` di bagian 4 ketukan): muncul pesan per baris dan tidak ada yang tersimpan. Betulkan, simpan, tayangkan: style muncul di halaman Arranger.
- [ ] Style tanpa Main A tidak bisa ditayangkan.

## H. Tampilan dan aksesibilitas

- [ ] Cek setiap halaman di lebar 360, 768, dan 1366 px: tidak ada scroll ke samping, dan tabel admin di ponsel berubah menjadi daftar.
- [ ] Aktifkan "kurangi gerakan" di sistem operasi: animasi dekoratif berhenti.
- [ ] Semua tombol dan isian bisa dicapai dengan tombol Tab, dan fokus terlihat jelas.
- [ ] Tidak ada emoji di halaman mana pun.
