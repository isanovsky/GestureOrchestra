<?php
// Perlindungan CSRF (Cross-Site Request Forgery).
// Setiap form POST menyisipkan token acak dari session. Saat form dikirim,
// token dicocokkan. Situs lain tidak bisa tahu token ini, jadi tidak bisa
// memalsukan form atas nama pengguna yang sedang login.

function csrf_token(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

// Cetak input tersembunyi berisi token. Pakai di dalam setiap <form method="post">.
function csrf_field(): string
{
    return '<input type="hidden" name="csrf_token" value="' . e(csrf_token()) . '">';
}

// Panggil di awal pemrosesan setiap POST. Token salah = permintaan ditolak.
function csrf_verify(): void
{
    $sent = $_POST['csrf_token'] ?? '';
    if (!is_string($sent) || empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $sent)) {
        render_error_page(400, 'Formulir kedaluwarsa', 'Muat ulang halaman, lalu kirim formulirnya sekali lagi.');
    }
}
