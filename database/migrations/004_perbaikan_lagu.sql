-- =====================================================================
-- GestureOrchestra - Migrasi 004: perbaikan lagu
--
-- Isi:
--   1. Ode to Joy disederhanakan: birama 10 dan 11 tidak lagi memakai
--      nada setengah ketuk "[3 4]" (sekitar 333 ms di 90 bpm, terlalu cepat
--      untuk gestur). Nada di ketukan utama (3) dipertahankan sepanjang
--      satu ketuk, sehingga kontur melodi tetap sama.
--        sebelum : 2 2 3 1 | 2 [3 4] 3 1 | 2 [3 4] 3 2 | 1 2 5, . |
--        sesudah : 2 2 3 1 | 2 3 3 1     | 2 3 3 2     | 1 2 5, . |
--   2. Burung Kakaktua: akor birama 3 diganti G -> F, karena melodi
--      "3 4 6 5" (E F A G) bertabrakan dengan akor G.
--        Akor: C | G | F | C | C | G | G | C |
--   3. Nilai permainan dari partitur lama dihapus: setiap nilai yang
--      total_notes-nya tidak sama dengan jumlah nada part saat ini
--      (Twinkle versi 16 ketukan, dan Ode to Joy sebelum disederhanakan).
--
-- Cara pakai: import SATU KALI di phpMyAdmin, setelah 003_lagu_tambahan.sql.
-- Isi file ini menghapus lalu menyisipkan ulang, jadi bila tidak sengaja
-- dijalankan dua kali hasilnya tetap sama.
-- =====================================================================

-- Catatan: InfinityFree tidak mengizinkan CREATE TEMPORARY TABLE, jadi
-- file ini memakai tabel kerja biasa (mig_tmp_*) yang dihapus di akhir.

SET NAMES utf8mb4;

SET @piano   = (SELECT id FROM instruments WHERE slug = 'piano');
SET @strings = (SELECT id FROM instruments WHERE slug = 'synth');

-- ---------------------------------------------------------------------
-- 1. Ode to Joy: tulis ulang part melodi strings dan piano
--    Not angka (Do = C):
--      3 3 4 5 | 5 4 3 2 | 1 1 2 3 | 3 . 2 2 |
--      3 3 4 5 | 5 4 3 2 | 1 1 2 3 | 2 . 1 1 |
--      2 2 3 1 | 2 3 3 1 | 2 3 3 2 | 1 2 5, . |
--      3 3 4 5 | 5 4 3 2 | 1 1 2 3 | 2 . 1 1 |
-- ---------------------------------------------------------------------
SET @ode = (SELECT id FROM songs WHERE title = 'Ode to Joy' LIMIT 1);
SET @p_strings = (SELECT id FROM song_parts WHERE song_id = @ode AND instrument_id = @strings AND style = 'notes');
SET @p_pmelody = (SELECT id FROM song_parts WHERE song_id = @ode AND instrument_id = @piano AND style = 'notes');

DROP TABLE IF EXISTS mig_tmp_melody;
CREATE TABLE mig_tmp_melody (
    degree TINYINT UNSIGNED NOT NULL,
    octave_shift TINYINT NOT NULL,
    start_beat DECIMAL(8,3) NOT NULL,
    duration_beats DECIMAL(6,3) NOT NULL
);
-- 60 nada (sebelumnya 62)
INSERT INTO mig_tmp_melody (degree, octave_shift, start_beat, duration_beats) VALUES
(3, 0, 0, 1), (3, 0, 1, 1), (4, 0, 2, 1), (5, 0, 3, 1),
(5, 0, 4, 1), (4, 0, 5, 1), (3, 0, 6, 1), (2, 0, 7, 1),
(1, 0, 8, 1), (1, 0, 9, 1), (2, 0, 10, 1), (3, 0, 11, 1),
(3, 0, 12, 2), (2, 0, 14, 1), (2, 0, 15, 1), (3, 0, 16, 1),
(3, 0, 17, 1), (4, 0, 18, 1), (5, 0, 19, 1), (5, 0, 20, 1),
(4, 0, 21, 1), (3, 0, 22, 1), (2, 0, 23, 1), (1, 0, 24, 1),
(1, 0, 25, 1), (2, 0, 26, 1), (3, 0, 27, 1), (2, 0, 28, 2),
(1, 0, 30, 1), (1, 0, 31, 1), (2, 0, 32, 1), (2, 0, 33, 1),
(3, 0, 34, 1), (1, 0, 35, 1), (2, 0, 36, 1), (3, 0, 37, 1),
(3, 0, 38, 1), (1, 0, 39, 1), (2, 0, 40, 1), (3, 0, 41, 1),
(3, 0, 42, 1), (2, 0, 43, 1), (1, 0, 44, 1), (2, 0, 45, 1),
(5, -1, 46, 2), (3, 0, 48, 1), (3, 0, 49, 1), (4, 0, 50, 1),
(5, 0, 51, 1), (5, 0, 52, 1), (4, 0, 53, 1), (3, 0, 54, 1),
(2, 0, 55, 1), (1, 0, 56, 1), (1, 0, 57, 1), (2, 0, 58, 1),
(3, 0, 59, 1), (2, 0, 60, 2), (1, 0, 62, 1), (1, 0, 63, 1);

DELETE FROM song_notes WHERE song_part_id IN (@p_strings, @p_pmelody);

-- Kode bunyi = huruf nada + oktaf Do instrumen (strings C5, piano C4) + geseran oktaf.
INSERT INTO song_notes (song_part_id, sound_id, start_beat, duration_beats)
SELECT @p_strings, s.id, m.start_beat, m.duration_beats FROM mig_tmp_melody m
JOIN sounds s ON s.instrument_id = @strings AND s.code = CONCAT(ELT(m.degree,'C','D','E','F','G','A','B'), 5 + m.octave_shift)
ORDER BY m.start_beat;
INSERT INTO song_notes (song_part_id, sound_id, start_beat, duration_beats)
SELECT @p_pmelody, s.id, m.start_beat, m.duration_beats FROM mig_tmp_melody m
JOIN sounds s ON s.instrument_id = @piano AND s.code = CONCAT(ELT(m.degree,'C','D','E','F','G','A','B'), 4 + m.octave_shift)
ORDER BY m.start_beat;

-- ---------------------------------------------------------------------
-- 2. Burung Kakaktua: tulis ulang part akor piano
-- ---------------------------------------------------------------------
SET @kakaktua = (SELECT id FROM songs WHERE title = 'Burung Kakaktua' LIMIT 1);
SET @p_chords = (SELECT id FROM song_parts WHERE song_id = @kakaktua AND instrument_id = @piano AND style = 'chords');

DROP TABLE IF EXISTS mig_tmp_chords;
CREATE TABLE mig_tmp_chords (
    degree TINYINT UNSIGNED NOT NULL,
    start_beat DECIMAL(8,3) NOT NULL,
    duration_beats DECIMAL(6,3) NOT NULL
);
INSERT INTO mig_tmp_chords (degree, start_beat, duration_beats) VALUES
(1, 0, 4), (5, 4, 4), (4, 8, 4), (1, 12, 4), (1, 16, 4), (5, 20, 4), (5, 24, 4), (1, 28, 4);

DELETE FROM song_notes WHERE song_part_id = @p_chords;
INSERT INTO song_notes (song_part_id, sound_id, start_beat, duration_beats)
SELECT @p_chords, s.id, c.start_beat, c.duration_beats FROM mig_tmp_chords c
JOIN sounds s ON s.instrument_id = @piano AND s.code = CONCAT(ELT(c.degree,'C','D','E','F','G','A','B'), 4)
ORDER BY c.start_beat;

-- ---------------------------------------------------------------------
-- 3. Hapus nilai dari partitur lama (dijalankan SETELAH partitur diperbarui)
-- ---------------------------------------------------------------------
DELETE pf FROM performances pf
WHERE pf.total_notes <> (
    SELECT COUNT(*)
    FROM song_notes n
    JOIN song_parts p ON p.id = n.song_part_id
    WHERE p.song_id = pf.song_id
      AND p.instrument_id = pf.instrument_id
      AND p.style = pf.part_style
);

DROP TABLE IF EXISTS mig_tmp_melody;
DROP TABLE IF EXISTS mig_tmp_chords;
