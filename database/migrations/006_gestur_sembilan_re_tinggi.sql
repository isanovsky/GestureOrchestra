-- =====================================================================
-- GestureOrchestra - Migrasi 006: gestur 9 jari = Re tinggi
--
-- Isi:
--   Gestur kamera 9 jari diselaraskan dengan tombol keyboard 9 (keymap.js):
--     Piano  : 9 jari Sol rendah (G3) -> Re tinggi (D5)
--     Strings: 9 jari Sol rendah (G4) -> Re tinggi (D6)
--   Sol rendah tetap ada sebagai bunyi dan tetap bisa dimainkan lewat
--   tombol T atau pad di layar, tetapi tidak lagi lewat kamera.
--
-- Akibat pada lagu yang sudah ada: Ode to Joy (part melodi piano dan strings)
-- memakai Sol rendah satu kali. Part itu akan ditandai "hanya keyboard" di
-- halaman lagu dan di pilihan part. Nilai yang sudah tersimpan tidak berubah
-- (penilaian memakai sound_id, bukan jumlah jari).
--
-- Hanya mengubah gestur yang masih menunjuk ke Sol rendah, jadi pemetaan yang
-- sudah diubah admin tidak tertimpa, dan menjalankan ulang tidak mengubah apa pun.
--
-- Aturan InfinityFree: tanpa tabel sementara, procedure, trigger, event.
-- Cara pakai: import SATU KALI di phpMyAdmin, setelah 005_rentang_nada_keyboard.sql.
-- =====================================================================

SET NAMES utf8mb4;

SET @piano   = (SELECT id FROM instruments WHERE slug = 'piano');
SET @strings = (SELECT id FROM instruments WHERE slug = 'synth');

UPDATE gestures g
JOIN sounds current_sound ON current_sound.id = g.sound_id
JOIN sounds new_sound ON new_sound.instrument_id = g.instrument_id AND new_sound.code = 'D5'
SET g.sound_id = new_sound.id
WHERE g.instrument_id = @piano AND g.finger_count = 9 AND current_sound.code = 'G3';

UPDATE gestures g
JOIN sounds current_sound ON current_sound.id = g.sound_id
JOIN sounds new_sound ON new_sound.instrument_id = g.instrument_id AND new_sound.code = 'D6'
SET g.sound_id = new_sound.id
WHERE g.instrument_id = @strings AND g.finger_count = 9 AND current_sound.code = 'G4';
