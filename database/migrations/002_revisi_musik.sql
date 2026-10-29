-- =====================================================================
-- GestureOrchestra - Migrasi 002: revisi musik
--
-- Isi:
--   1. Part lagu punya gaya: 'notes' (nada per nada) atau 'chords'
--      (akor; sound_id menyimpan nada dasar akor).
--   2. Transpose dan tempo latihan untuk lagu dan room.
--   3. Strings dipindah ke C5-C6 agar melodi terdengar di atas piano.
--   4. Drum mendapat Rim click dan Cymbal lembut untuk pola ballad.
--   5. Twinkle Twinkle Little Star lengkap 12 birama (48 ketukan):
--      strings melodi, piano akor, piano melodi (latihan solo), drum ballad.
--
-- Cara pakai: phpMyAdmin -> pilih database -> Import -> file ini.
-- Jalankan SATU KALI, setelah schema.sql. Database baru cukup
-- import schema.sql lalu file ini.
-- =====================================================================

-- Catatan: InfinityFree tidak mengizinkan CREATE TEMPORARY TABLE, jadi
-- file ini memakai tabel kerja biasa (mig_tmp_*) yang dihapus di akhir.

SET NAMES utf8mb4;

-- ---------------------------------------------------------------------
-- 1. Struktur
-- ---------------------------------------------------------------------

-- Gaya part. Indeks unik baru ditambah dulu sebelum yang lama dihapus,
-- karena indeks lama juga dipakai oleh foreign key ke songs.
ALTER TABLE song_parts
    ADD COLUMN style ENUM('notes', 'chords') NOT NULL DEFAULT 'notes' AFTER instrument_id;
ALTER TABLE song_parts
    ADD UNIQUE KEY uq_song_parts_style (song_id, instrument_id, style);
ALTER TABLE song_parts
    DROP INDEX uq_song_parts;

-- Nada dasar lagu dalam not angka (Do = C, Do = G, dst) disimpan
-- sebagai jarak semitone dari C. Partitur tetap ditulis dalam Do.
ALTER TABLE songs
    ADD COLUMN default_transpose TINYINT NOT NULL DEFAULT 0 AFTER beats_per_bar,
    ADD CONSTRAINT chk_songs_transpose CHECK (default_transpose BETWEEN -6 AND 6);

-- Transpose dan tempo berlaku untuk SEMUA pemain di room,
-- agar semua perangkat bermain di nada dan tempo yang sama.
ALTER TABLE rooms
    ADD COLUMN transpose TINYINT NOT NULL DEFAULT 0 AFTER song_id,
    ADD COLUMN tempo_percent TINYINT UNSIGNED NOT NULL DEFAULT 100 AFTER transpose,
    ADD CONSTRAINT chk_rooms_transpose CHECK (transpose BETWEEN -6 AND 6),
    ADD CONSTRAINT chk_rooms_tempo CHECK (tempo_percent BETWEEN 50 AND 100);

ALTER TABLE room_players
    ADD COLUMN part_style ENUM('notes', 'chords') NOT NULL DEFAULT 'notes' AFTER instrument_id;

-- Nilai di tempo lambat tidak setara dengan tempo penuh,
-- jadi tempo dicatat agar leaderboard bisa membedakannya.
ALTER TABLE performances
    ADD COLUMN part_style ENUM('notes', 'chords') NOT NULL DEFAULT 'notes' AFTER instrument_id,
    ADD COLUMN tempo_percent TINYINT UNSIGNED NOT NULL DEFAULT 100 AFTER mode,
    DROP INDEX idx_perf_leaderboard,
    ADD KEY idx_perf_leaderboard (song_id, instrument_id, part_style, tempo_percent, accuracy);

-- ---------------------------------------------------------------------
-- 2. Referensi id berdasarkan slug dan kode (tidak bergantung angka id)
-- ---------------------------------------------------------------------
SET @piano  = (SELECT id FROM instruments WHERE slug = 'piano');
SET @strings = (SELECT id FROM instruments WHERE slug = 'synth');
SET @drum   = (SELECT id FROM instruments WHERE slug = 'drum');

-- ---------------------------------------------------------------------
-- 3. Strings: C3-C4 -> C5-C6, perilaku legato
-- ---------------------------------------------------------------------
UPDATE instruments
SET name = 'Strings',
    description = 'Melodi legato: nada mengalun dan bersambung selama gestur ditahan.'
WHERE id = @strings;

UPDATE sounds SET code = 'C6', frequency = 1046.50 WHERE instrument_id = @strings AND code = 'C4';
UPDATE sounds SET code = 'B5', frequency =  987.77 WHERE instrument_id = @strings AND code = 'B3';
UPDATE sounds SET code = 'A5', frequency =  880.00 WHERE instrument_id = @strings AND code = 'A3';
UPDATE sounds SET code = 'G5', frequency =  783.99 WHERE instrument_id = @strings AND code = 'G3';
UPDATE sounds SET code = 'F5', frequency =  698.46 WHERE instrument_id = @strings AND code = 'F3';
UPDATE sounds SET code = 'E5', frequency =  659.26 WHERE instrument_id = @strings AND code = 'E3';
UPDATE sounds SET code = 'D5', frequency =  587.33 WHERE instrument_id = @strings AND code = 'D3';
UPDATE sounds SET code = 'C5', frequency =  523.25 WHERE instrument_id = @strings AND code = 'C3';

-- ---------------------------------------------------------------------
-- 4. Drum: tambah Rim click dan Cymbal lembut, susun ulang gestur
--    1 Kick | 2 Rim | 3 Hi-hat | 4 Snare | 5 Cymbal lembut | 6 Tom | 7 Crash
-- ---------------------------------------------------------------------
INSERT INTO sounds (instrument_id, name, code, frequency, sort_order) VALUES
(@drum, 'Rim click',      'RIM',    NULL, 6),
(@drum, 'Cymbal lembut',  'CYMBAL', NULL, 7);

UPDATE sounds SET sort_order = 1 WHERE instrument_id = @drum AND code = 'KICK';
UPDATE sounds SET sort_order = 2 WHERE instrument_id = @drum AND code = 'RIM';
UPDATE sounds SET sort_order = 3 WHERE instrument_id = @drum AND code = 'HIHAT';
UPDATE sounds SET sort_order = 4 WHERE instrument_id = @drum AND code = 'SNARE';
UPDATE sounds SET sort_order = 5 WHERE instrument_id = @drum AND code = 'CYMBAL';
UPDATE sounds SET sort_order = 6 WHERE instrument_id = @drum AND code = 'TOM';
UPDATE sounds SET sort_order = 7 WHERE instrument_id = @drum AND code = 'CRASH';

DELETE FROM gestures WHERE instrument_id = @drum;
INSERT INTO gestures (instrument_id, finger_count, sound_id)
SELECT @drum, sort_order, id FROM sounds WHERE instrument_id = @drum;

-- ---------------------------------------------------------------------
-- 5. Twinkle Twinkle Little Star, lengkap A-B-A (12 birama)
--    Melodi (not angka, Do = C):
--      A : 1 1 5 5 | 6 6 5 - | 4 4 3 3 | 2 2 1 -
--      B : 5 5 4 4 | 3 3 2 - | 5 5 4 4 | 3 3 2 -
--      A : 1 1 5 5 | 6 6 5 - | 4 4 3 3 | 2 2 1 -
--    Akor:
--      A : C | F C | F C | G C
--      B : C G | C G | C G | C G
--      A : C | F C | F C | G C
-- ---------------------------------------------------------------------
SET @song = (SELECT id FROM songs WHERE title = 'Twinkle Twinkle Little Star' LIMIT 1);

UPDATE songs
SET default_transpose = 0,
    description = 'Lagu latihan tiga instrumen: strings memainkan melodi, piano akor dan bass, drum pola ballad.'
WHERE id = @song;

-- Part lama (16 ketukan) dihapus; catatannya ikut terhapus (CASCADE).
DELETE FROM song_parts WHERE song_id = @song;

INSERT INTO song_parts (song_id, instrument_id, style) VALUES
(@song, @strings, 'notes'),
(@song, @piano,   'chords'),
(@song, @piano,   'notes'),
(@song, @drum,    'notes');

SET @p_strings = (SELECT id FROM song_parts WHERE song_id = @song AND instrument_id = @strings AND style = 'notes');
SET @p_chords  = (SELECT id FROM song_parts WHERE song_id = @song AND instrument_id = @piano   AND style = 'chords');
SET @p_pmelody = (SELECT id FROM song_parts WHERE song_id = @song AND instrument_id = @piano   AND style = 'notes');
SET @p_drum    = (SELECT id FROM song_parts WHERE song_id = @song AND instrument_id = @drum    AND style = 'notes');

-- Melodi dalam derajat (1 = Do ... 6 = La), dipakai untuk strings dan piano.
DROP TABLE IF EXISTS mig_tmp_melody;
CREATE TABLE mig_tmp_melody (
    degree TINYINT UNSIGNED NOT NULL,
    start_beat DECIMAL(8,3) NOT NULL,
    duration_beats DECIMAL(6,3) NOT NULL
);
INSERT INTO mig_tmp_melody (degree, start_beat, duration_beats) VALUES
-- A
(1, 0, 1), (1, 1, 1), (5, 2, 1), (5, 3, 1),
(6, 4, 1), (6, 5, 1), (5, 6, 2),
(4, 8, 1), (4, 9, 1), (3, 10, 1), (3, 11, 1),
(2, 12, 1), (2, 13, 1), (1, 14, 2),
-- B
(5, 16, 1), (5, 17, 1), (4, 18, 1), (4, 19, 1),
(3, 20, 1), (3, 21, 1), (2, 22, 2),
(5, 24, 1), (5, 25, 1), (4, 26, 1), (4, 27, 1),
(3, 28, 1), (3, 29, 1), (2, 30, 2),
-- A
(1, 32, 1), (1, 33, 1), (5, 34, 1), (5, 35, 1),
(6, 36, 1), (6, 37, 1), (5, 38, 2),
(4, 40, 1), (4, 41, 1), (3, 42, 1), (3, 43, 1),
(2, 44, 1), (2, 45, 1), (1, 46, 2);

INSERT INTO song_notes (song_part_id, sound_id, start_beat, duration_beats)
SELECT @p_strings, s.id, m.start_beat, m.duration_beats
FROM mig_tmp_melody m
JOIN sounds s ON s.instrument_id = @strings AND s.sort_order = m.degree
ORDER BY m.start_beat;

INSERT INTO song_notes (song_part_id, sound_id, start_beat, duration_beats)
SELECT @p_pmelody, s.id, m.start_beat, m.duration_beats
FROM mig_tmp_melody m
JOIN sounds s ON s.instrument_id = @piano AND s.sort_order = m.degree
ORDER BY m.start_beat;

-- Akor: sound_id = nada dasar akor di piano (1 = C, 4 = F, 5 = G).
DROP TABLE IF EXISTS mig_tmp_chords;
CREATE TABLE mig_tmp_chords (
    degree TINYINT UNSIGNED NOT NULL,
    start_beat DECIMAL(8,3) NOT NULL,
    duration_beats DECIMAL(6,3) NOT NULL
);
INSERT INTO mig_tmp_chords (degree, start_beat, duration_beats) VALUES
-- A
(1, 0, 4),
(4, 4, 2), (1, 6, 2),
(4, 8, 2), (1, 10, 2),
(5, 12, 2), (1, 14, 2),
-- B
(1, 16, 2), (5, 18, 2),
(1, 20, 2), (5, 22, 2),
(1, 24, 2), (5, 26, 2),
(1, 28, 2), (5, 30, 2),
-- A
(1, 32, 4),
(4, 36, 2), (1, 38, 2),
(4, 40, 2), (1, 42, 2),
(5, 44, 2), (1, 46, 2);

INSERT INTO song_notes (song_part_id, sound_id, start_beat, duration_beats)
SELECT @p_chords, s.id, c.start_beat, c.duration_beats
FROM mig_tmp_chords c
JOIN sounds s ON s.instrument_id = @piano AND s.sort_order = c.degree
ORDER BY c.start_beat;

-- Drum ballad 4/4: ketukan 1 dan 3 Kick, ketukan 2 dan 4 Rim click.
-- Ketukan pertama tiap bagian (birama 1, 5, 9) diganti Cymbal lembut,
-- dan lagu ditutup Cymbal di ketukan ke-3 birama terakhir.
INSERT INTO song_notes (song_part_id, sound_id, start_beat, duration_beats)
SELECT @p_drum,
       s.id,
       b.beat,
       CASE WHEN b.beat = 46 THEN 2 ELSE 1 END
FROM (
    SELECT 0 AS beat UNION ALL SELECT 1 UNION ALL SELECT 2 UNION ALL SELECT 3
    UNION ALL SELECT 4 UNION ALL SELECT 5 UNION ALL SELECT 6 UNION ALL SELECT 7
    UNION ALL SELECT 8 UNION ALL SELECT 9 UNION ALL SELECT 10 UNION ALL SELECT 11
    UNION ALL SELECT 12 UNION ALL SELECT 13 UNION ALL SELECT 14 UNION ALL SELECT 15
    UNION ALL SELECT 16 UNION ALL SELECT 17 UNION ALL SELECT 18 UNION ALL SELECT 19
    UNION ALL SELECT 20 UNION ALL SELECT 21 UNION ALL SELECT 22 UNION ALL SELECT 23
    UNION ALL SELECT 24 UNION ALL SELECT 25 UNION ALL SELECT 26 UNION ALL SELECT 27
    UNION ALL SELECT 28 UNION ALL SELECT 29 UNION ALL SELECT 30 UNION ALL SELECT 31
    UNION ALL SELECT 32 UNION ALL SELECT 33 UNION ALL SELECT 34 UNION ALL SELECT 35
    UNION ALL SELECT 36 UNION ALL SELECT 37 UNION ALL SELECT 38 UNION ALL SELECT 39
    UNION ALL SELECT 40 UNION ALL SELECT 41 UNION ALL SELECT 42 UNION ALL SELECT 43
    UNION ALL SELECT 44 UNION ALL SELECT 45 UNION ALL SELECT 46
) b
JOIN sounds s ON s.instrument_id = @drum AND s.code =
    CASE
        WHEN b.beat IN (0, 16, 32, 46) THEN 'CYMBAL'
        WHEN MOD(b.beat, 2) = 0       THEN 'KICK'
        ELSE 'RIM'
    END
ORDER BY b.beat;

DROP TABLE IF EXISTS mig_tmp_melody;
DROP TABLE IF EXISTS mig_tmp_chords;
