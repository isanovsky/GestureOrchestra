-- =====================================================================
-- GestureOrchestra - Migrasi 007: mode Arranger
--
-- Isi:
--   Tiga tabel baru untuk style iringan (dikelola admin):
--     arranger_styles   : nama style, tempo default, ketukan per birama, suara akor
--     arranger_sections : bagian per style (Intro, Main A, Main B, Fill, Ending)
--                         beserta panjangnya dalam ketukan
--     arranger_patterns : pola per bagian per track (drum, bass, akor), berupa teks
--   Tiga style awal: Ballad, Pop, Dangdut (sudah tayang).
--
-- Format pola (satu kejadian per baris, # = komentar):
--   drum : <ketukan> <KODE> [velocity]           contoh: 1 SNARE 0.9
--   bass : <ketukan> <nada> [durasi] [velocity]  contoh: 2 5- 1 0.8
--   akor : <ketukan> <nada> [durasi] [velocity]  contoh: 0 R35 1 0.6
--   Ketukan dihitung dari 0 di awal bagian, boleh desimal (1.5) atau pecahan (4/3).
--   Nada diambil dari akor yang sedang aktif: R = dasar, 3 = terts, 5 = kuint,
--   8 = oktaf; akhiran - = satu oktaf lebih rendah. Penjelasan lengkap ada di README.
--
-- Tidak mengubah tabel lama. Tidak ada tabel bantu.
-- Aturan InfinityFree: tanpa tabel sementara, procedure, trigger, event.
-- Cara pakai: import SATU KALI di phpMyAdmin, setelah 006_gestur_sembilan_re_tinggi.sql.
-- =====================================================================

SET NAMES utf8mb4;

CREATE TABLE arranger_styles (
    id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    slug          VARCHAR(60)  NOT NULL,
    name          VARCHAR(80)  NOT NULL,
    description   VARCHAR(255) NULL,
    default_bpm   SMALLINT UNSIGNED NOT NULL DEFAULT 100,
    beats_per_bar TINYINT UNSIGNED  NOT NULL DEFAULT 4,
    chord_voice   ENUM('piano', 'pad', 'pluck') NOT NULL DEFAULT 'piano',
    is_published  TINYINT(1)   NOT NULL DEFAULT 0,
    sort_order    SMALLINT     NOT NULL DEFAULT 0,
    created_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_arranger_styles_slug (slug),
    CONSTRAINT chk_arranger_styles_bpm CHECK (default_bpm BETWEEN 40 AND 200),
    CONSTRAINT chk_arranger_styles_bar CHECK (beats_per_bar BETWEEN 2 AND 12)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE arranger_sections (
    id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
    style_id     INT UNSIGNED NOT NULL,
    section      ENUM('intro', 'main_a', 'main_b', 'fill', 'ending') NOT NULL,
    length_beats TINYINT UNSIGNED NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_arranger_sections (style_id, section),
    CONSTRAINT fk_arranger_sections_style FOREIGN KEY (style_id)
        REFERENCES arranger_styles (id) ON DELETE CASCADE,
    CONSTRAINT chk_arranger_sections_length CHECK (length_beats BETWEEN 1 AND 32)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE arranger_patterns (
    id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
    section_id INT UNSIGNED NOT NULL,
    track      ENUM('drum', 'bass', 'chord') NOT NULL,
    events     TEXT NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_arranger_patterns (section_id, track),
    CONSTRAINT fk_arranger_patterns_section FOREIGN KEY (section_id)
        REFERENCES arranger_sections (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Style awal
-- ---------------------------------------------------------------------
INSERT INTO arranger_styles (slug, name, description, default_bpm, beats_per_bar, chord_voice, is_published, sort_order) VALUES
('ballad', 'Ballad', 'Lambat dan lembut: piano berarpeggio, bass panjang, drum tipis.', 72, 4, 'piano', 1, 1),
('pop', 'Pop', 'Ketukan pop 4/4: hi-hat seperdelapan, bass mengikuti kick.', 110, 4, 'piano', 1, 2),
('dangdut', 'Dangdut', 'Kendang dut-tak, gitar petik di ketukan lemah, bass mengikuti kendang.', 130, 4, 'pluck', 1, 3);

-- Panjang tiap bagian (ketukan).
INSERT INTO arranger_sections (style_id, section, length_beats)
SELECT s.id, x.section, x.length_beats
FROM arranger_styles s
JOIN (
    SELECT 'ballad' AS slug, 'intro' AS section, 8 AS length_beats
    UNION ALL SELECT 'ballad', 'main_a', 4
    UNION ALL SELECT 'ballad', 'main_b', 4
    UNION ALL SELECT 'ballad', 'fill', 4
    UNION ALL SELECT 'ballad', 'ending', 8
    UNION ALL SELECT 'pop', 'intro', 8
    UNION ALL SELECT 'pop', 'main_a', 4
    UNION ALL SELECT 'pop', 'main_b', 4
    UNION ALL SELECT 'pop', 'fill', 4
    UNION ALL SELECT 'pop', 'ending', 4
    UNION ALL SELECT 'dangdut', 'intro', 8
    UNION ALL SELECT 'dangdut', 'main_a', 4
    UNION ALL SELECT 'dangdut', 'main_b', 4
    UNION ALL SELECT 'dangdut', 'fill', 4
    UNION ALL SELECT 'dangdut', 'ending', 8
) x ON x.slug = s.slug;

-- Pola. Baris dipisah dengan '\n' (literal SQL), satu kejadian per baris.
INSERT INTO arranger_patterns (section_id, track, events)
SELECT sec.id, x.track, x.events
FROM arranger_sections sec
JOIN arranger_styles s ON s.id = sec.style_id
JOIN (
    -- ============================ Ballad ============================
    SELECT 'ballad' AS slug, 'intro' AS section, 'drum' AS track,
        '0 CYMBAL 0.5\n4 RIM 0.4\n6 RIM 0.4\n7 RIM 0.5\n7.5 RIM 0.6' AS events
    UNION ALL SELECT 'ballad', 'intro', 'bass',
        '0 R 4 0.7\n4 5- 4 0.7'
    UNION ALL SELECT 'ballad', 'intro', 'chord',
        '0 R 0.5 0.5\n0.5 5 0.5 0.45\n1 8 0.5 0.5\n1.5 5 0.5 0.45\n2 3 0.5 0.5\n2.5 5 0.5 0.45\n3 8 0.5 0.5\n3.5 5 0.5 0.45\n4 R 0.5 0.5\n4.5 5 0.5 0.45\n5 8 0.5 0.5\n5.5 5 0.5 0.45\n6 R35 2 0.5'
    UNION ALL SELECT 'ballad', 'main_a', 'drum',
        '0 KICK 0.8\n0 HIHAT 0.3\n1 RIM 0.5\n1 HIHAT 0.25\n2 HIHAT 0.3\n2.5 KICK 0.55\n3 RIM 0.5\n3 HIHAT 0.25'
    UNION ALL SELECT 'ballad', 'main_a', 'bass',
        '0 R 2 0.85\n2 5- 1.5 0.7\n3.5 R 0.5 0.6'
    UNION ALL SELECT 'ballad', 'main_a', 'chord',
        '0 R 0.5 0.5\n0.5 5 0.5 0.4\n1 8 0.5 0.45\n1.5 5 0.5 0.4\n2 3 0.5 0.45\n2.5 5 0.5 0.4\n3 8 0.5 0.45\n3.5 5 0.5 0.4'
    UNION ALL SELECT 'ballad', 'main_b', 'drum',
        '0 KICK 0.85\n0 HIHAT 0.35\n0.5 HIHAT 0.2\n1 SNARE 0.55\n1 HIHAT 0.3\n1.5 HIHAT 0.2\n2 HIHAT 0.35\n2.5 KICK 0.6\n2.5 HIHAT 0.2\n3 SNARE 0.55\n3 HIHAT 0.3\n3.5 HIHAT 0.2'
    UNION ALL SELECT 'ballad', 'main_b', 'bass',
        '0 R 1.5 0.85\n1.5 R 0.5 0.6\n2 5 1 0.75\n3 3 1 0.7'
    UNION ALL SELECT 'ballad', 'main_b', 'chord',
        '0 R35 1.5 0.5\n1.5 R35 0.5 0.4\n2 358 1 0.45\n3 R35 1 0.4'
    UNION ALL SELECT 'ballad', 'fill', 'drum',
        '0 KICK 0.8\n1 TOM 0.5\n1.5 TOM 0.5\n2 SNARE 0.5\n2.5 SNARE 0.55\n3 TOM 0.6\n3.5 TOM 0.65'
    UNION ALL SELECT 'ballad', 'fill', 'bass',
        '0 R 2 0.8\n2 5- 2 0.7'
    UNION ALL SELECT 'ballad', 'fill', 'chord',
        '0 R35 2 0.5\n2 358 2 0.45'
    UNION ALL SELECT 'ballad', 'ending', 'drum',
        '0 KICK 0.8\n0 CYMBAL 0.5\n2 RIM 0.4\n4 KICK 0.8\n4 CRASH 0.5'
    UNION ALL SELECT 'ballad', 'ending', 'bass',
        '0 R 4 0.75\n4 R- 4 0.8'
    UNION ALL SELECT 'ballad', 'ending', 'chord',
        '0 R 0.5 0.5\n0.5 5 0.5 0.45\n1 8 0.5 0.5\n1.5 5 0.5 0.45\n2 3 0.5 0.45\n2.5 5 0.5 0.4\n3 8 0.5 0.45\n4 R358 4 0.55'

    -- ============================== Pop ==============================
    UNION ALL SELECT 'pop', 'intro', 'drum',
        '0 CRASH 0.6\n0 KICK 0.9\n1 SNARE 0.7\n2 KICK 0.85\n3 SNARE 0.7\n4 KICK 0.9\n5 SNARE 0.7\n6 KICK 0.8\n6 SNARE 0.5\n6.5 SNARE 0.55\n7 TOM 0.6\n7.5 TOM 0.7\n0.5 HIHAT 0.3\n1.5 HIHAT 0.3\n2.5 HIHAT 0.3\n3.5 HIHAT 0.3\n4.5 HIHAT 0.3\n5.5 HIHAT 0.3'
    UNION ALL SELECT 'pop', 'intro', 'bass',
        '0 R 1.5 0.85\n1.5 R 0.5 0.7\n2 R 1 0.8\n3 5- 1 0.7\n4 R 1.5 0.85\n5.5 R 0.5 0.7\n6 R 2 0.8'
    UNION ALL SELECT 'pop', 'intro', 'chord',
        '0 R35 1.5 0.55\n1.5 R35 0.5 0.45\n2.5 R35 0.5 0.45\n3 R35 1 0.5\n4 R35 1.5 0.55\n5.5 R35 0.5 0.45\n6 R358 2 0.55'
    UNION ALL SELECT 'pop', 'main_a', 'drum',
        '0 KICK 0.9\n1 SNARE 0.7\n2 KICK 0.85\n2.5 KICK 0.6\n3 SNARE 0.7\n0 HIHAT 0.4\n0.5 HIHAT 0.25\n1 HIHAT 0.4\n1.5 HIHAT 0.25\n2 HIHAT 0.4\n2.5 HIHAT 0.25\n3 HIHAT 0.4\n3.5 HIHAT 0.25'
    UNION ALL SELECT 'pop', 'main_a', 'bass',
        '0 R 1 0.9\n1.5 R 0.5 0.7\n2 R 0.5 0.85\n2.5 R 0.5 0.7\n3 5- 0.5 0.75\n3.5 R 0.5 0.7'
    UNION ALL SELECT 'pop', 'main_a', 'chord',
        '0 R35 1 0.5\n1.5 R35 0.5 0.4\n2.5 R35 0.5 0.4\n3.5 R35 0.5 0.35'
    UNION ALL SELECT 'pop', 'main_b', 'drum',
        '0 KICK 0.95\n0 CRASH 0.35\n1 SNARE 0.8\n1.5 KICK 0.6\n2 KICK 0.85\n3 SNARE 0.8\n3.5 OHAT 0.4\n0 HIHAT 0.45\n0.5 HIHAT 0.3\n1 HIHAT 0.45\n1.5 HIHAT 0.3\n2 HIHAT 0.45\n2.5 HIHAT 0.3\n3 HIHAT 0.45'
    UNION ALL SELECT 'pop', 'main_b', 'bass',
        '0 R 0.5 0.9\n0.5 8 0.5 0.7\n1 R 0.5 0.85\n1.5 8 0.5 0.7\n2 R 0.5 0.85\n2.5 8 0.5 0.7\n3 5 0.5 0.8\n3.5 8 0.5 0.7'
    UNION ALL SELECT 'pop', 'main_b', 'chord',
        '0 R358 1 0.55\n1 R35 1 0.45\n2 R358 1 0.5\n3 R35 0.5 0.45\n3.5 R35 0.5 0.4'
    UNION ALL SELECT 'pop', 'fill', 'drum',
        '0 KICK 0.9\n0 SNARE 0.6\n1 SNARE 0.6\n1.5 SNARE 0.65\n2 TOM 0.7\n2.5 TOM 0.75\n3 TOM 0.8\n3.25 SNARE 0.6\n3.5 SNARE 0.7\n3.75 SNARE 0.8'
    UNION ALL SELECT 'pop', 'fill', 'bass',
        '0 R 2 0.85\n2 5- 1 0.75\n3 R 1 0.8'
    UNION ALL SELECT 'pop', 'fill', 'chord',
        '0 R35 2 0.5\n2 R358 1 0.5'
    UNION ALL SELECT 'pop', 'ending', 'drum',
        '0 KICK 0.9\n0 CRASH 0.6\n1.5 KICK 0.7\n2 SNARE 0.7\n2.5 SNARE 0.6\n3 KICK 0.95\n3 CRASH 0.7'
    UNION ALL SELECT 'pop', 'ending', 'bass',
        '0 R 1 0.85\n1.5 R 0.5 0.75\n3 R- 1 0.9'
    UNION ALL SELECT 'pop', 'ending', 'chord',
        '0 R35 1 0.55\n1.5 R35 0.5 0.5\n3 R358 1 0.6'

    -- ============================ Dangdut ============================
    -- KDUT = kendang "dut" (rendah), KTAK = kendang "tak" (tinggi), TAMB = tamborin.
    UNION ALL SELECT 'dangdut', 'intro', 'drum',
        '0 KTAK 0.8\n0.5 KTAK 0.6\n1 KDUT 0.9\n2 KTAK 0.8\n2.5 KTAK 0.6\n3 KDUT 0.9\n4 KTAK 0.7\n4.5 KTAK 0.6\n5 KTAK 0.7\n5.5 KTAK 0.75\n6 KDUT 0.9\n6.5 KDUT 0.8\n7 KTAK 0.85\n7.5 KTAK 0.9'
    UNION ALL SELECT 'dangdut', 'intro', 'bass',
        '0 R 1 0.85\n1 5- 1 0.75\n2 R 1 0.85\n3 5- 1 0.75\n6 R 0.5 0.85\n6.5 R 0.5 0.75\n7 5- 0.5 0.8\n7.5 R 0.5 0.8'
    UNION ALL SELECT 'dangdut', 'intro', 'chord',
        '0.5 R35 0.25 0.45\n1.5 R35 0.25 0.45\n2.5 R35 0.25 0.45\n3.5 R35 0.25 0.45\n4.5 R35 0.25 0.45\n5.5 R35 0.25 0.45\n6 R358 2 0.5'
    UNION ALL SELECT 'dangdut', 'main_a', 'drum',
        '0 KDUT 0.9\n1 KTAK 0.75\n1.5 KDUT 0.7\n2 KDUT 0.85\n3 KTAK 0.8\n3.5 KTAK 0.55\n0.5 TAMB 0.35\n1.5 TAMB 0.35\n2.5 TAMB 0.35\n3.5 TAMB 0.35'
    UNION ALL SELECT 'dangdut', 'main_a', 'bass',
        '0 R 0.75 0.9\n1.5 R 0.5 0.75\n2 5- 0.75 0.85\n3 R 0.5 0.75\n3.5 3 0.5 0.7'
    UNION ALL SELECT 'dangdut', 'main_a', 'chord',
        '0.5 R35 0.25 0.45\n1.5 R35 0.25 0.45\n2.5 R35 0.25 0.45\n3.5 R35 0.25 0.45'
    UNION ALL SELECT 'dangdut', 'main_b', 'drum',
        '0 KDUT 0.95\n0.5 KTAK 0.5\n1 KTAK 0.8\n1.5 KDUT 0.75\n2 KDUT 0.9\n2.5 KTAK 0.5\n3 KTAK 0.85\n3.5 KTAK 0.6\n0 TAMB 0.3\n0.5 TAMB 0.4\n1 TAMB 0.3\n1.5 TAMB 0.4\n2 TAMB 0.3\n2.5 TAMB 0.4\n3 TAMB 0.3\n3.5 TAMB 0.4'
    UNION ALL SELECT 'dangdut', 'main_b', 'bass',
        '0 R 0.5 0.9\n0.5 R 0.5 0.7\n1.5 5- 0.5 0.8\n2 R 0.5 0.85\n2.5 3 0.5 0.7\n3 5 0.5 0.8\n3.5 3 0.5 0.7'
    UNION ALL SELECT 'dangdut', 'main_b', 'chord',
        '0.5 R35 0.25 0.5\n1 358 0.25 0.35\n1.5 R35 0.25 0.5\n2.5 R35 0.25 0.5\n3 358 0.25 0.35\n3.5 R35 0.25 0.5'
    UNION ALL SELECT 'dangdut', 'fill', 'drum',
        '0 KDUT 0.9\n0.5 KTAK 0.6\n1 KTAK 0.7\n1.5 KTAK 0.75\n2 KDUT 0.85\n2.5 KDUT 0.8\n3 KTAK 0.8\n3.25 KTAK 0.7\n3.5 KTAK 0.85\n3.75 KTAK 0.9'
    UNION ALL SELECT 'dangdut', 'fill', 'bass',
        '0 R 1 0.85\n2 5- 0.5 0.8\n2.5 R 0.5 0.8'
    UNION ALL SELECT 'dangdut', 'fill', 'chord',
        '0.5 R35 0.25 0.45\n1.5 R35 0.25 0.45'
    UNION ALL SELECT 'dangdut', 'ending', 'drum',
        '0 KDUT 0.9\n1 KTAK 0.75\n1.5 KDUT 0.7\n2 KDUT 0.85\n3 KTAK 0.8\n4 KTAK 0.8\n4.5 KTAK 0.8\n5 KDUT 0.9\n6 KDUT 0.95\n6 CRASH 0.5'
    UNION ALL SELECT 'dangdut', 'ending', 'bass',
        '0 R 0.75 0.9\n1.5 R 0.5 0.75\n2 5- 0.75 0.85\n3 R 0.5 0.75\n4 R 0.5 0.8\n4.5 R 0.5 0.8\n5 5- 1 0.85\n6 R 2 0.9'
    UNION ALL SELECT 'dangdut', 'ending', 'chord',
        '0.5 R35 0.25 0.45\n1.5 R35 0.25 0.45\n2.5 R35 0.25 0.45\n3.5 R35 0.25 0.45\n4.5 R35 0.25 0.45\n5 R35 0.25 0.45\n6 R358 2 0.55'
) x ON x.slug = s.slug AND x.section = sec.section;
