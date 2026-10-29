<?php
// Halaman "tidak ditemukan". Dipakai oleh ErrorDocument di .htaccess.

require __DIR__ . '/includes/bootstrap.php';

render_error_page(404, 'Halaman ini tidak ada', 'Mungkin tautannya salah ketik, atau halamannya sudah dipindah.');
