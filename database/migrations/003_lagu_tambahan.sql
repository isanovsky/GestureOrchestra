-- =====================================================================
-- GestureOrchestra - Migrasi 003: lagu tambahan
--
-- Isi:
--   1. Nada "Sol rendah" (5 bertitik bawah) untuk piano (G3) dan
--      strings (G4), dipetakan ke gestur 9 jari. Dibutuhkan Ode to Joy.
--   2. Ode to Joy (Beethoven, domain publik), 16 birama, 4 part.
--   3. Burung Kakaktua (lagu daerah Maluku), bagian bait 8 birama, 4 part.
--
-- Cara pakai: import SATU KALI di phpMyAdmin, setelah 002_revisi_musik.sql.
--
-- Catatan aransemen Ode to Joy: birama 4 dan 8 disederhanakan menjadi
-- "3 . 2 2" dan "2 . 1 1". Ritme aslinya "3 [. 2] 2 ." (nada pendek
-- setengah ketuk) terlalu cepat untuk dimainkan dengan gestur.
-- =====================================================================

-- Catatan: InfinityFree tidak mengizinkan CREATE TEMPORARY TABLE, jadi
-- file ini memakai tabel kerja biasa (mig_tmp_*) yang dihapus di akhir.

SET NAMES utf8mb4;

SET @piano   = (SELECT id FROM instruments WHERE slug = 'piano');
SET @strings = (SELECT id FROM instruments WHERE slug = 'synth');
SET @drum    = (SELECT id FROM instruments WHERE slug = 'drum');

-- ---------------------------------------------------------------------
-- 1. Sol rendah
-- ---------------------------------------------------------------------
INSERT INTO sounds (instrument_id, name, code, frequency, sort_order) VALUES
(@piano,   'Sol rendah', 'G3', 196.00, 0),
(@strings, 'Sol rendah', 'G4', 392.00, 0);

INSERT INTO gestures (instrument_id, finger_count, sound_id)
SELECT instrument_id, 9, id FROM sounds
WHERE (instrument_id = @piano AND code = 'G3')
   OR (instrument_id = @strings AND code = 'G4');

-- ---------------------------------------------------------------------
-- 2. Tabel bantu
--    degree 1-7 = Do-Si, octave_shift -1 = bertitik bawah, 1 = bertitik atas.
--    Kode bunyi dibentuk dari huruf nada + oktaf dasar instrumen
--    (piano Do = C4, strings Do = C5).
-- ---------------------------------------------------------------------
DROP TABLE IF EXISTS mig_tmp_melody;
CREATE TABLE mig_tmp_melody (
    degree TINYINT UNSIGNED NOT NULL,
    octave_shift TINYINT NOT NULL,
    start_beat DECIMAL(8,3) NOT NULL,
    duration_beats DECIMAL(6,3) NOT NULL
);
DROP TABLE IF EXISTS mig_tmp_chords;
CREATE TABLE mig_tmp_chords (
    degree TINYINT UNSIGNED NOT NULL,
    start_beat DECIMAL(8,3) NOT NULL,
    duration_beats DECIMAL(6,3) NOT NULL
);
DROP TABLE IF EXISTS mig_tmp_beats;
CREATE TABLE mig_tmp_beats (beat SMALLINT UNSIGNED NOT NULL);
INSERT INTO mig_tmp_beats (beat)
SELECT a.n + b.n * 8
FROM (SELECT 0 n UNION ALL SELECT 1 UNION ALL SELECT 2 UNION ALL SELECT 3
      UNION ALL SELECT 4 UNION ALL SELECT 5 UNION ALL SELECT 6 UNION ALL SELECT 7) a
CROSS JOIN
     (SELECT 0 n UNION ALL SELECT 1 UNION ALL SELECT 2 UNION ALL SELECT 3
      UNION ALL SELECT 4 UNION ALL SELECT 5 UNION ALL SELECT 6 UNION ALL SELECT 7) b;

-- ---------------------------------------------------------------------
-- 3. Lagu
--    Ode to Joy (Do = C)
--      3 3 4 5 | 5 4 3 2 | 1 1 2 3 | 3 . 2 2 |
--      3 3 4 5 | 5 4 3 2 | 1 1 2 3 | 2 . 1 1 |
--      2 2 3 1 | 2 [3 4] 3 1 | 2 [3 4] 3 2 | 1 2 5, . |
--      3 3 4 5 | 5 4 3 2 | 1 1 2 3 | 2 . 1 1 |
--    Akor: C | G | C | C G | C | G | C | G C |
--          G | G C | G Am | G | C | G | C | G C |
--
--    Burung Kakaktua (Do = C)
--      5 5 3 1' | 3 2 . . | 3 4 6 5 | 4 3 . . |
--      5 5 3 1' | 3 2 . . | 7 6 5 4 | 3 2 1 . |
--    Akor: C | G | G | C | C | G | G | C |
-- ---------------------------------------------------------------------

-- Ode to Joy: 62 nada melodi, 21 akor, 64 ketukan
INSERT INTO songs (title, composer, bpm, beats_per_bar, default_transpose, difficulty, description, is_published, created_by)
VALUES ('Ode to Joy', 'Ludwig van Beethoven', 90, 4, 0, 'easy', 'Tema Ode to Joy dalam aransemen ramah gestur.', 1, (SELECT id FROM users WHERE role = 'superadmin' ORDER BY id LIMIT 1));
SET @song = LAST_INSERT_ID();
INSERT INTO song_parts (song_id, instrument_id, style) VALUES
(@song, @strings, 'notes'), (@song, @piano, 'chords'), (@song, @piano, 'notes'), (@song, @drum, 'notes');
SET @p_strings = (SELECT id FROM song_parts WHERE song_id = @song AND instrument_id = @strings AND style = 'notes');
SET @p_chords  = (SELECT id FROM song_parts WHERE song_id = @song AND instrument_id = @piano AND style = 'chords');
SET @p_pmelody = (SELECT id FROM song_parts WHERE song_id = @song AND instrument_id = @piano AND style = 'notes');
SET @p_drum    = (SELECT id FROM song_parts WHERE song_id = @song AND instrument_id = @drum AND style = 'notes');
TRUNCATE TABLE mig_tmp_melody;
INSERT INTO mig_tmp_melody (degree, octave_shift, start_beat, duration_beats) VALUES
(3, 0, 0, 1), (3, 0, 1, 1), (4, 0, 2, 1), (5, 0, 3, 1),
(5, 0, 4, 1), (4, 0, 5, 1), (3, 0, 6, 1), (2, 0, 7, 1),
(1, 0, 8, 1), (1, 0, 9, 1), (2, 0, 10, 1), (3, 0, 11, 1),
(3, 0, 12, 2), (2, 0, 14, 1), (2, 0, 15, 1), (3, 0, 16, 1),
(3, 0, 17, 1), (4, 0, 18, 1), (5, 0, 19, 1), (5, 0, 20, 1),
(4, 0, 21, 1), (3, 0, 22, 1), (2, 0, 23, 1), (1, 0, 24, 1),
(1, 0, 25, 1), (2, 0, 26, 1), (3, 0, 27, 1), (2, 0, 28, 2),
(1, 0, 30, 1), (1, 0, 31, 1), (2, 0, 32, 1), (2, 0, 33, 1),
(3, 0, 34, 1), (1, 0, 35, 1), (2, 0, 36, 1), (3, 0, 37, 0.5),
(4, 0, 37.5, 0.5), (3, 0, 38, 1), (1, 0, 39, 1), (2, 0, 40, 1),
(3, 0, 41, 0.5), (4, 0, 41.5, 0.5), (3, 0, 42, 1), (2, 0, 43, 1),
(1, 0, 44, 1), (2, 0, 45, 1), (5, -1, 46, 2), (3, 0, 48, 1),
(3, 0, 49, 1), (4, 0, 50, 1), (5, 0, 51, 1), (5, 0, 52, 1),
(4, 0, 53, 1), (3, 0, 54, 1), (2, 0, 55, 1), (1, 0, 56, 1),
(1, 0, 57, 1), (2, 0, 58, 1), (3, 0, 59, 1), (2, 0, 60, 2),
(1, 0, 62, 1), (1, 0, 63, 1);
INSERT INTO song_notes (song_part_id, sound_id, start_beat, duration_beats)
SELECT @p_strings, s.id, m.start_beat, m.duration_beats FROM mig_tmp_melody m
JOIN sounds s ON s.instrument_id = @strings AND s.code = CONCAT(ELT(m.degree,'C','D','E','F','G','A','B'), 5 + m.octave_shift)
ORDER BY m.start_beat;
INSERT INTO song_notes (song_part_id, sound_id, start_beat, duration_beats)
SELECT @p_pmelody, s.id, m.start_beat, m.duration_beats FROM mig_tmp_melody m
JOIN sounds s ON s.instrument_id = @piano AND s.code = CONCAT(ELT(m.degree,'C','D','E','F','G','A','B'), 4 + m.octave_shift)
ORDER BY m.start_beat;
TRUNCATE TABLE mig_tmp_chords;
INSERT INTO mig_tmp_chords (degree, start_beat, duration_beats) VALUES
(1, 0, 4), (5, 4, 4), (1, 8, 4), (1, 12, 2),
(5, 14, 2), (1, 16, 4), (5, 20, 4), (1, 24, 4),
(5, 28, 2), (1, 30, 2), (5, 32, 4), (5, 36, 2),
(1, 38, 2), (5, 40, 2), (6, 42, 2), (5, 44, 4),
(1, 48, 4), (5, 52, 4), (1, 56, 4), (5, 60, 2),
(1, 62, 2);
INSERT INTO song_notes (song_part_id, sound_id, start_beat, duration_beats)
SELECT @p_chords, s.id, c.start_beat, c.duration_beats FROM mig_tmp_chords c
JOIN sounds s ON s.instrument_id = @piano AND s.code = CONCAT(ELT(c.degree,'C','D','E','F','G','A','B'), 4)
ORDER BY c.start_beat;
-- Drum ballad: Cymbal di awal bagian (ketukan 0,16,32,48) dan penutup (ketukan 62).
INSERT INTO song_notes (song_part_id, sound_id, start_beat, duration_beats)
SELECT @p_drum, s.id, n.beat, CASE WHEN n.beat = 62 THEN 2 ELSE 1 END
FROM mig_tmp_beats n
JOIN sounds s ON s.instrument_id = @drum AND s.code =
    CASE WHEN n.beat IN (0,16,32,48, 62) THEN 'CYMBAL' WHEN MOD(n.beat, 2) = 0 THEN 'KICK' ELSE 'RIM' END
WHERE n.beat <= 62
ORDER BY n.beat;

-- Burung Kakaktua: 25 nada melodi, 8 akor, 32 ketukan
INSERT INTO songs (title, composer, bpm, beats_per_bar, default_transpose, difficulty, description, is_published, created_by)
VALUES ('Burung Kakaktua', 'Lagu daerah Maluku', 90, 4, 0, 'easy', 'Lagu daerah Maluku, bagian bait.', 1, (SELECT id FROM users WHERE role = 'superadmin' ORDER BY id LIMIT 1));
SET @song = LAST_INSERT_ID();
INSERT INTO song_parts (song_id, instrument_id, style) VALUES
(@song, @strings, 'notes'), (@song, @piano, 'chords'), (@song, @piano, 'notes'), (@song, @drum, 'notes');
SET @p_strings = (SELECT id FROM song_parts WHERE song_id = @song AND instrument_id = @strings AND style = 'notes');
SET @p_chords  = (SELECT id FROM song_parts WHERE song_id = @song AND instrument_id = @piano AND style = 'chords');
SET @p_pmelody = (SELECT id FROM song_parts WHERE song_id = @song AND instrument_id = @piano AND style = 'notes');
SET @p_drum    = (SELECT id FROM song_parts WHERE song_id = @song AND instrument_id = @drum AND style = 'notes');
TRUNCATE TABLE mig_tmp_melody;
INSERT INTO mig_tmp_melody (degree, octave_shift, start_beat, duration_beats) VALUES
(5, 0, 0, 1), (5, 0, 1, 1), (3, 0, 2, 1), (1, 1, 3, 1),
(3, 0, 4, 1), (2, 0, 5, 3), (3, 0, 8, 1), (4, 0, 9, 1),
(6, 0, 10, 1), (5, 0, 11, 1), (4, 0, 12, 1), (3, 0, 13, 3),
(5, 0, 16, 1), (5, 0, 17, 1), (3, 0, 18, 1), (1, 1, 19, 1),
(3, 0, 20, 1), (2, 0, 21, 3), (7, 0, 24, 1), (6, 0, 25, 1),
(5, 0, 26, 1), (4, 0, 27, 1), (3, 0, 28, 1), (2, 0, 29, 1),
(1, 0, 30, 2);
INSERT INTO song_notes (song_part_id, sound_id, start_beat, duration_beats)
SELECT @p_strings, s.id, m.start_beat, m.duration_beats FROM mig_tmp_melody m
JOIN sounds s ON s.instrument_id = @strings AND s.code = CONCAT(ELT(m.degree,'C','D','E','F','G','A','B'), 5 + m.octave_shift)
ORDER BY m.start_beat;
INSERT INTO song_notes (song_part_id, sound_id, start_beat, duration_beats)
SELECT @p_pmelody, s.id, m.start_beat, m.duration_beats FROM mig_tmp_melody m
JOIN sounds s ON s.instrument_id = @piano AND s.code = CONCAT(ELT(m.degree,'C','D','E','F','G','A','B'), 4 + m.octave_shift)
ORDER BY m.start_beat;
TRUNCATE TABLE mig_tmp_chords;
INSERT INTO mig_tmp_chords (degree, start_beat, duration_beats) VALUES
(1, 0, 4), (5, 4, 4), (5, 8, 4), (1, 12, 4),
(1, 16, 4), (5, 20, 4), (5, 24, 4), (1, 28, 4);
INSERT INTO song_notes (song_part_id, sound_id, start_beat, duration_beats)
SELECT @p_chords, s.id, c.start_beat, c.duration_beats FROM mig_tmp_chords c
JOIN sounds s ON s.instrument_id = @piano AND s.code = CONCAT(ELT(c.degree,'C','D','E','F','G','A','B'), 4)
ORDER BY c.start_beat;
-- Drum ballad: Cymbal di awal bagian (ketukan 0,16) dan penutup (ketukan 30).
INSERT INTO song_notes (song_part_id, sound_id, start_beat, duration_beats)
SELECT @p_drum, s.id, n.beat, CASE WHEN n.beat = 30 THEN 2 ELSE 1 END
FROM mig_tmp_beats n
JOIN sounds s ON s.instrument_id = @drum AND s.code =
    CASE WHEN n.beat IN (0,16, 30) THEN 'CYMBAL' WHEN MOD(n.beat, 2) = 0 THEN 'KICK' ELSE 'RIM' END
WHERE n.beat <= 30
ORDER BY n.beat;

DROP TABLE IF EXISTS mig_tmp_melody;
DROP TABLE IF EXISTS mig_tmp_chords;
DROP TABLE IF EXISTS mig_tmp_beats;
