-- =====================================================================
-- GestureOrchestra - Migrasi 005: rentang nada untuk keyboard
--
-- Isi:
--   Piano dan strings mendapat satu oktaf penuh di bawah Do dan satu oktaf
--   penuh di atas Do tinggi, untuk dimainkan lewat keyboard laptop:
--     Q W E R T Y U        = Do rendah .. Si rendah
--     1 2 3 4 5 6 7 8      = Do .. Do tinggi (seperti sekarang)
--     9 0 - = [ ] \        = Re tinggi .. Do sangat tinggi
--   Piano  : C3 .. C6   (sebelumnya G3 .. C5)
--   Strings: C4 .. C7   (sebelumnya G4 .. C6)
--   Gestur kamera tidak berubah (1-9 jari). Nada baru hanya lewat keyboard/pad.
--   sort_order diurutkan ulang menurut tinggi nada (hanya untuk tampilan).
--
-- Aturan InfinityFree: tanpa tabel sementara, procedure, trigger, event.
-- Cara pakai: import SATU KALI di phpMyAdmin, setelah 004_perbaikan_lagu.sql.
-- =====================================================================

SET NAMES utf8mb4;

SET @piano   = (SELECT id FROM instruments WHERE slug = 'piano');
SET @strings = (SELECT id FROM instruments WHERE slug = 'synth');

-- Piano
INSERT INTO sounds (instrument_id, name, code, frequency, sort_order) VALUES
(@piano, 'Do rendah', 'C3', 130.81, 0),
(@piano, 'Re rendah', 'D3', 146.83, 0),
(@piano, 'Mi rendah', 'E3', 164.81, 0),
(@piano, 'Fa rendah', 'F3', 174.61, 0),
(@piano, 'La rendah', 'A3', 220.00, 0),
(@piano, 'Si rendah', 'B3', 246.94, 0),
(@piano, 'Re tinggi', 'D5', 587.33, 0),
(@piano, 'Mi tinggi', 'E5', 659.26, 0),
(@piano, 'Fa tinggi', 'F5', 698.46, 0),
(@piano, 'Sol tinggi', 'G5', 783.99, 0),
(@piano, 'La tinggi', 'A5', 880.00, 0),
(@piano, 'Si tinggi', 'B5', 987.77, 0),
(@piano, 'Do sangat tinggi', 'C6', 1046.50, 0);

-- Strings
INSERT INTO sounds (instrument_id, name, code, frequency, sort_order) VALUES
(@strings, 'Do rendah', 'C4', 261.63, 0),
(@strings, 'Re rendah', 'D4', 293.66, 0),
(@strings, 'Mi rendah', 'E4', 329.63, 0),
(@strings, 'Fa rendah', 'F4', 349.23, 0),
(@strings, 'La rendah', 'A4', 440.00, 0),
(@strings, 'Si rendah', 'B4', 493.88, 0),
(@strings, 'Re tinggi', 'D6', 1174.66, 0),
(@strings, 'Mi tinggi', 'E6', 1318.51, 0),
(@strings, 'Fa tinggi', 'F6', 1396.91, 0),
(@strings, 'Sol tinggi', 'G6', 1567.98, 0),
(@strings, 'La tinggi', 'A6', 1760.00, 0),
(@strings, 'Si tinggi', 'B6', 1975.53, 0),
(@strings, 'Do sangat tinggi', 'C7', 2093.00, 0);

-- Urutkan ulang sort_order menurut frekuensi (1 = nada terendah).
UPDATE sounds s
JOIN (
    SELECT id, ROW_NUMBER() OVER (PARTITION BY instrument_id ORDER BY frequency) AS rn
    FROM sounds
    WHERE instrument_id IN (@piano, @strings)
) r ON r.id = s.id
SET s.sort_order = r.rn;
