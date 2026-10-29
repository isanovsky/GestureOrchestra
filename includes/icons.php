<?php
// Ikon doodle instrumen (SVG inline, gambar orisinal kelompok).
// Inline, bukan file .svg, supaya warnanya bisa diambil dari kolom
// instruments.color dan ikonnya bisa dianimasikan dengan CSS saat nada berbunyi.
//
// Gaya: garis cokelat dengan ujung membulat, isi warna sedikit digeser dari garis.

function instrument_icon(string $type, string $color, string $class = 'inst-icon'): string
{
    $fill = e($color);
    $ink = '#3B2A1E';
    $open = '<svg class="' . e($class) . '" viewBox="0 0 64 64" width="64" height="64" aria-hidden="true" focusable="false">';
    $line = 'fill="none" stroke="' . $ink . '" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"';

    switch ($type) {
        case 'piano':
            // Tuts piano yang tersenyum.
            $body = 'M8 18 Q7 14 11 14 L53 13 Q57 13 57 17 L58 50 Q58 54 54 54 L11 55 Q7 55 7 51 Z';
            return $open
                . '<path d="' . $body . '" fill="' . $fill . '" transform="translate(2 2)"/>'
                . '<path d="M10 33 L55 32.5 L55.5 51 Q55 52.5 53 52.5 L12 53 Q10 53 10 51 Z" fill="#FFFAF1"/>'
                . '<path d="' . $body . '" ' . $line . '/>'
                . '<path d="M10 33 L55.5 32.5 M21.5 33 L21 52.5 M33 33 L33 52.5 M44.5 32.8 L45 52.5" ' . $line . ' stroke-width="2.4"/>'
                . '<path d="M18.5 33 L18.5 43 L24.5 43 L24.5 33 Z M30 33 L30 43 L36 43 L36 33 Z M41.5 33 L41.5 43 L47.5 43 L47.5 33 Z" fill="' . $ink . '"/>'
                . '<circle cx="25" cy="22" r="2.4" fill="' . $ink . '"/><circle cx="39" cy="21.5" r="2.4" fill="' . $ink . '"/>'
                . '<path d="M27 25.5 Q32 30 37 25" ' . $line . ' stroke-width="2.4"/>'
                . '</svg>';

        case 'synth':
            // Kotak synth dengan gelombang suara yang bergoyang.
            $body = 'M10 20 Q8 12 18 12 L48 11 Q56 11 56 20 L57 44 Q57 52 48 52 L17 53 Q8 53 9 44 Z';
            return $open
                . '<path d="' . $body . '" fill="' . $fill . '" transform="translate(2 2)"/>'
                . '<path d="' . $body . '" ' . $line . '/>'
                . '<path class="icon-wave" d="M15 31 Q19.5 20 24 31 T33 31 T42 31 T51 31" ' . $line . '/>'
                . '<circle cx="20" cy="44" r="3.2" fill="#FFFAF1" stroke="' . $ink . '" stroke-width="2.4"/>'
                . '<circle cx="31" cy="44" r="3.2" fill="#FFFAF1" stroke="' . $ink . '" stroke-width="2.4"/>'
                . '<path d="M40 44 L50 43.5" ' . $line . '/>'
                . '</svg>';

        case 'drum':
            // Drum dengan dua stik bersilang.
            $body = 'M10 30 L11 49 Q32 60 53 49 L54 30 Q32 39 10 30 Z';
            return $open
                . '<path d="' . $body . '" fill="' . $fill . '" transform="translate(2 1.5)"/>'
                . '<ellipse cx="32" cy="30" rx="22" ry="7" fill="#FFFAF1"/>'
                . '<path d="' . $body . '" ' . $line . '/>'
                . '<ellipse cx="32" cy="30" rx="22" ry="7" ' . $line . '/>'
                . '<path d="M14 36 L20 49 L26 38 L32 52 L38 38 L44 49 L50 36" ' . $line . ' stroke-width="2.4"/>'
                . '<path d="M12 5 L29 25 M52 5 L35 25" ' . $line . ' stroke-width="3.5"/>'
                . '<circle cx="12" cy="5" r="3" fill="' . $ink . '"/><circle cx="52" cy="5" r="3" fill="' . $ink . '"/>'
                . '</svg>';

        default:
            // Tipe lain: not balok sederhana.
            return $open
                . '<ellipse cx="24" cy="46" rx="10" ry="8" fill="' . $fill . '" transform="translate(2 2)"/>'
                . '<ellipse cx="24" cy="46" rx="10" ry="8" ' . $line . '/>'
                . '<path d="M33 45 L33 10 Q42 14 48 22" ' . $line . '/>'
                . '</svg>';
    }
}
