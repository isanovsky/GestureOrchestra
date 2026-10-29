-- =====================================================================
-- GestureOrchestra - Skema Database
-- Target: MariaDB 10.4+ (XAMPP) dan MariaDB 11.x (InfinityFree)
--
-- Cara pakai:
-- 1. Buat database kosong lebih dulu (lokal: lewat phpMyAdmin,
--    InfinityFree: lewat menu MySQL Databases di Control Panel).
-- 2. Pilih database tersebut di phpMyAdmin, lalu tab Import -> file ini.
--
-- File ini sengaja TIDAK berisi CREATE DATABASE / USE, karena
-- InfinityFree tidak mengizinkan pembuatan database lewat SQL.
-- Menjalankan ulang file ini akan MENGHAPUS semua data lama.
-- =====================================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

DROP TABLE IF EXISTS performances;
DROP TABLE IF EXISTS room_players;
DROP TABLE IF EXISTS rooms;
DROP TABLE IF EXISTS song_notes;
DROP TABLE IF EXISTS song_parts;
DROP TABLE IF EXISTS songs;
DROP TABLE IF EXISTS gestures;
DROP TABLE IF EXISTS sounds;
DROP TABLE IF EXISTS instruments;
DROP TABLE IF EXISTS users;

SET FOREIGN_KEY_CHECKS = 1;

-- ---------------------------------------------------------------------
-- 1. users : akun yang bisa login (superadmin dan admin)
--    Pemain TIDAK perlu akun; cukup nickname saat masuk room.
-- ---------------------------------------------------------------------
CREATE TABLE users (
    id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    name          VARCHAR(100) NOT NULL,
    username      VARCHAR(50)  NOT NULL,
    email         VARCHAR(150) NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    role          ENUM('superadmin', 'admin') NOT NULL DEFAULT 'admin',
    is_active     TINYINT(1)   NOT NULL DEFAULT 1,
    last_login_at DATETIME     NULL,
    created_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_users_username (username),
    UNIQUE KEY uq_users_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 2. instruments : jenis instrumen
--    play_mode menentukan perilaku bunyi di JavaScript:
--      decay   = bunyi sekali lalu meluruh (piano)
--      sustain = bunyi selama gestur ditahan (synth / string)
--      hit     = pukulan pendek tanpa nada (drum)
-- ---------------------------------------------------------------------
CREATE TABLE instruments (
    id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    name        VARCHAR(50)  NOT NULL,
    slug        VARCHAR(50)  NOT NULL,
    type        ENUM('piano', 'synth', 'drum') NOT NULL,
    play_mode   ENUM('decay', 'sustain', 'hit') NOT NULL,
    color       CHAR(7)      NOT NULL DEFAULT '#E4572E',
    description VARCHAR(255) NULL,
    is_active   TINYINT(1)   NOT NULL DEFAULT 1,
    created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_instruments_slug (slug)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 3. sounds : bunyi yang dimiliki tiap instrumen
--    (pengganti tabel `notes` di repo awal)
--    frequency diisi untuk instrumen bernada, NULL untuk drum.
--    sample_path opsional, untuk memakai file audio alih-alih sintesis.
-- ---------------------------------------------------------------------
CREATE TABLE sounds (
    id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    instrument_id INT UNSIGNED NOT NULL,
    name          VARCHAR(50)  NOT NULL,
    code          VARCHAR(20)  NOT NULL,
    frequency     DECIMAL(8,2) NULL,
    sample_path   VARCHAR(255) NULL,
    sort_order    SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    created_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_sounds_instrument_code (instrument_id, code),
    CONSTRAINT fk_sounds_instrument FOREIGN KEY (instrument_id)
        REFERENCES instruments (id) ON DELETE CASCADE,
    CONSTRAINT chk_sounds_frequency CHECK (frequency IS NULL OR frequency > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 4. gestures : pemetaan jumlah jari -> bunyi, per instrumen
--    finger_count = total jari terangkat dari kedua tangan (1-10).
--    Satu jumlah jari hanya boleh dipetakan ke satu bunyi per instrumen.
-- ---------------------------------------------------------------------
CREATE TABLE gestures (
    id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    instrument_id INT UNSIGNED NOT NULL,
    finger_count  TINYINT UNSIGNED NOT NULL,
    sound_id      INT UNSIGNED NOT NULL,
    is_active     TINYINT(1)   NOT NULL DEFAULT 1,
    created_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_gestures_instrument_finger (instrument_id, finger_count),
    CONSTRAINT fk_gestures_instrument FOREIGN KEY (instrument_id)
        REFERENCES instruments (id) ON DELETE CASCADE,
    CONSTRAINT fk_gestures_sound FOREIGN KEY (sound_id)
        REFERENCES sounds (id) ON DELETE CASCADE,
    CONSTRAINT chk_gestures_finger CHECK (finger_count BETWEEN 1 AND 10)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 5. songs : lagu yang bisa dimainkan bersama
-- ---------------------------------------------------------------------
CREATE TABLE songs (
    id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    title         VARCHAR(150) NOT NULL,
    composer      VARCHAR(150) NULL,
    bpm           SMALLINT UNSIGNED NOT NULL DEFAULT 90,
    beats_per_bar TINYINT UNSIGNED  NOT NULL DEFAULT 4,
    difficulty    ENUM('easy', 'medium', 'hard') NOT NULL DEFAULT 'easy',
    description   TEXT NULL,
    is_published  TINYINT(1)   NOT NULL DEFAULT 0,
    created_by    INT UNSIGNED NULL,
    created_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    CONSTRAINT fk_songs_creator FOREIGN KEY (created_by)
        REFERENCES users (id) ON DELETE SET NULL,
    CONSTRAINT chk_songs_bpm CHECK (bpm BETWEEN 40 AND 200)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 6. song_parts : bagian lagu untuk tiap instrumen
--    (satu lagu punya maksimal satu part per instrumen)
-- ---------------------------------------------------------------------
CREATE TABLE song_parts (
    id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    song_id       INT UNSIGNED NOT NULL,
    instrument_id INT UNSIGNED NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_song_parts (song_id, instrument_id),
    CONSTRAINT fk_parts_song FOREIGN KEY (song_id)
        REFERENCES songs (id) ON DELETE CASCADE,
    CONSTRAINT fk_parts_instrument FOREIGN KEY (instrument_id)
        REFERENCES instruments (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 7. song_notes : "partitur" tiap part
--    start_beat dihitung dari 0. Contoh: ketukan pertama birama ke-2
--    pada lagu 4/4 adalah start_beat = 4.
--    Bunyi yang masih dipakai di partitur tidak bisa dihapus (RESTRICT).
-- ---------------------------------------------------------------------
CREATE TABLE song_notes (
    id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
    song_part_id   INT UNSIGNED NOT NULL,
    sound_id       INT UNSIGNED NOT NULL,
    start_beat     DECIMAL(8,3) NOT NULL,
    duration_beats DECIMAL(6,3) NOT NULL DEFAULT 1.000,
    PRIMARY KEY (id),
    KEY idx_song_notes_part_beat (song_part_id, start_beat),
    CONSTRAINT fk_notes_part FOREIGN KEY (song_part_id)
        REFERENCES song_parts (id) ON DELETE CASCADE,
    CONSTRAINT fk_notes_sound FOREIGN KEY (sound_id)
        REFERENCES sounds (id) ON DELETE RESTRICT,
    CONSTRAINT chk_notes_beat CHECK (start_beat >= 0 AND duration_beats > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 8. rooms : sesi multiplayer
--    host_token disimpan di browser host; hanya pemegangnya yang bisa
--    memulai permainan. start_at_ms = waktu server (milidetik) saat
--    semua pemain mulai bersamaan.
-- ---------------------------------------------------------------------
CREATE TABLE rooms (
    id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    code        CHAR(6)      NOT NULL,
    song_id     INT UNSIGNED NOT NULL,
    host_token  CHAR(64)     NOT NULL,
    status      ENUM('waiting', 'countdown', 'playing', 'finished') NOT NULL DEFAULT 'waiting',
    max_players TINYINT UNSIGNED NOT NULL DEFAULT 4,
    start_at_ms BIGINT UNSIGNED NULL,
    created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    expires_at  DATETIME     NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_rooms_code (code),
    KEY idx_rooms_status (status),
    CONSTRAINT fk_rooms_song FOREIGN KEY (song_id)
        REFERENCES songs (id) ON DELETE CASCADE,
    CONSTRAINT chk_rooms_players CHECK (max_players BETWEEN 2 AND 4)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 9. room_players : pemain di dalam room
--    player_token mengidentifikasi pemain tanpa login.
-- ---------------------------------------------------------------------
CREATE TABLE room_players (
    id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    room_id       INT UNSIGNED NOT NULL,
    nickname      VARCHAR(30)  NOT NULL,
    instrument_id INT UNSIGNED NOT NULL,
    player_token  CHAR(64)     NOT NULL,
    is_host       TINYINT(1)   NOT NULL DEFAULT 0,
    is_ready      TINYINT(1)   NOT NULL DEFAULT 0,
    joined_at     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_seen_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_room_players_token (player_token),
    UNIQUE KEY uq_room_players_nickname (room_id, nickname),
    CONSTRAINT fk_players_room FOREIGN KEY (room_id)
        REFERENCES rooms (id) ON DELETE CASCADE,
    CONSTRAINT fk_players_instrument FOREIGN KEY (instrument_id)
        REFERENCES instruments (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 10. performances : hasil permainan (dasar leaderboard)
--     Nilai dihitung di SERVER dari urutan bunyi yang dikirim klien,
--     bukan dikirim langsung oleh klien.
-- ---------------------------------------------------------------------
CREATE TABLE performances (
    id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    room_id       INT UNSIGNED NULL,
    song_id       INT UNSIGNED NOT NULL,
    instrument_id INT UNSIGNED NOT NULL,
    nickname      VARCHAR(30)  NOT NULL,
    mode          ENUM('solo', 'room') NOT NULL DEFAULT 'solo',
    total_notes   SMALLINT UNSIGNED NOT NULL,
    hits          SMALLINT UNSIGNED NOT NULL,
    misses        SMALLINT UNSIGNED NOT NULL,
    accuracy      DECIMAL(5,2) NOT NULL,
    played_at     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_perf_leaderboard (song_id, instrument_id, accuracy),
    CONSTRAINT fk_perf_room FOREIGN KEY (room_id)
        REFERENCES rooms (id) ON DELETE SET NULL,
    CONSTRAINT fk_perf_song FOREIGN KEY (song_id)
        REFERENCES songs (id) ON DELETE CASCADE,
    CONSTRAINT fk_perf_instrument FOREIGN KEY (instrument_id)
        REFERENCES instruments (id) ON DELETE CASCADE,
    CONSTRAINT chk_perf_accuracy CHECK (accuracy BETWEEN 0 AND 100)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- =====================================================================
-- DATA AWAL (SEED)
-- =====================================================================

-- Superadmin awal. Password: Superadmin#2026
-- WAJIB diganti setelah login pertama, terutama sebelum deploy.
INSERT INTO users (id, name, username, email, password_hash, role) VALUES
(1, 'Super Admin', 'superadmin', 'superadmin@gestureorchestra.local',
 '$2y$10$ekHj/Le1PSlmgvDHF/1L4eaVCkZi1RfbEssxAww.17bwoZAGAUlgm', 'superadmin');

INSERT INTO instruments (id, name, slug, type, play_mode, color, description) VALUES
(1, 'Piano',         'piano', 'piano', 'decay',   '#E4572E', 'Nada berbunyi sekali lalu meluruh.'),
(2, 'Synth Strings', 'synth', 'synth', 'sustain', '#2A9D8F', 'Nada berbunyi selama gestur ditahan.'),
(3, 'Drum',          'drum',  'drum',  'hit',     '#E9A23B', 'Pukulan perkusi tanpa nada.');

-- Piano: C4 - C5
INSERT INTO sounds (id, instrument_id, name, code, frequency, sort_order) VALUES
(1, 1, 'Do',  'C4', 261.63, 1),
(2, 1, 'Re',  'D4', 293.66, 2),
(3, 1, 'Mi',  'E4', 329.63, 3),
(4, 1, 'Fa',  'F4', 349.23, 4),
(5, 1, 'Sol', 'G4', 392.00, 5),
(6, 1, 'La',  'A4', 440.00, 6),
(7, 1, 'Si',  'B4', 493.88, 7),
(8, 1, 'Do tinggi', 'C5', 523.25, 8);

-- Synth Strings: C3 - C4 (satu oktaf di bawah piano)
INSERT INTO sounds (id, instrument_id, name, code, frequency, sort_order) VALUES
(9,  2, 'Do',  'C3', 130.81, 1),
(10, 2, 'Re',  'D3', 146.83, 2),
(11, 2, 'Mi',  'E3', 164.81, 3),
(12, 2, 'Fa',  'F3', 174.61, 4),
(13, 2, 'Sol', 'G3', 196.00, 5),
(14, 2, 'La',  'A3', 220.00, 6),
(15, 2, 'Si',  'B3', 246.94, 7),
(16, 2, 'Do tinggi', 'C4', 261.63, 8);

-- Drum: tanpa frekuensi
INSERT INTO sounds (id, instrument_id, name, code, frequency, sort_order) VALUES
(17, 3, 'Kick',    'KICK',  NULL, 1),
(18, 3, 'Snare',   'SNARE', NULL, 2),
(19, 3, 'Hi-hat',  'HIHAT', NULL, 3),
(20, 3, 'Tom',     'TOM',   NULL, 4),
(21, 3, 'Crash',   'CRASH', NULL, 5);

-- Pemetaan gestur: 1-8 jari untuk piano dan synth, 1-5 jari untuk drum
INSERT INTO gestures (instrument_id, finger_count, sound_id) VALUES
(1, 1, 1), (1, 2, 2), (1, 3, 3), (1, 4, 4), (1, 5, 5), (1, 6, 6), (1, 7, 7), (1, 8, 8),
(2, 1, 9), (2, 2, 10), (2, 3, 11), (2, 4, 12), (2, 5, 13), (2, 6, 14), (2, 7, 15), (2, 8, 16),
(3, 1, 17), (3, 2, 18), (3, 3, 19), (3, 4, 20), (3, 5, 21);

-- Lagu contoh: Twinkle Twinkle Little Star (domain publik), 16 ketukan
INSERT INTO songs (id, title, composer, bpm, beats_per_bar, difficulty, description, is_published, created_by) VALUES
(1, 'Twinkle Twinkle Little Star', 'Tradisional', 80, 4, 'easy',
 'Lagu latihan untuk tiga instrumen.', 1, 1);

INSERT INTO song_parts (id, song_id, instrument_id) VALUES
(1, 1, 1),
(2, 1, 2),
(3, 1, 3);

-- Part piano: melodi  C C G G A A G- F F E E D D C-
INSERT INTO song_notes (song_part_id, sound_id, start_beat, duration_beats) VALUES
(1, 1, 0, 1), (1, 1, 1, 1), (1, 5, 2, 1), (1, 5, 3, 1),
(1, 6, 4, 1), (1, 6, 5, 1), (1, 5, 6, 2),
(1, 4, 8, 1), (1, 4, 9, 1), (1, 3, 10, 1), (1, 3, 11, 1),
(1, 2, 12, 1), (1, 2, 13, 1), (1, 1, 14, 2);

-- Part synth strings: nada dasar akor  C | F C | F C | G C
INSERT INTO song_notes (song_part_id, sound_id, start_beat, duration_beats) VALUES
(2, 9, 0, 4),
(2, 12, 4, 2), (2, 9, 6, 2),
(2, 12, 8, 2), (2, 9, 10, 2),
(2, 13, 12, 2), (2, 9, 14, 2);

-- Part drum: kick di ketukan genap, snare di ketukan ganjil, crash di akhir
INSERT INTO song_notes (song_part_id, sound_id, start_beat, duration_beats) VALUES
(3, 17, 0, 1), (3, 18, 1, 1), (3, 17, 2, 1), (3, 18, 3, 1),
(3, 17, 4, 1), (3, 18, 5, 1), (3, 17, 6, 1), (3, 18, 7, 1),
(3, 17, 8, 1), (3, 18, 9, 1), (3, 17, 10, 1), (3, 18, 11, 1),
(3, 17, 12, 1), (3, 18, 13, 1), (3, 21, 14, 2);