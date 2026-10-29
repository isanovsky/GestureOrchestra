<?php
// Keluar. Hanya lewat POST dengan token CSRF, supaya situs lain tidak bisa
// memaksa pengguna logout lewat tautan atau gambar.

require __DIR__ . '/includes/bootstrap.php';

if (!is_post()) {
    redirect('index.php');
}

csrf_verify();
logout_user();
flash('success', 'Kamu sudah keluar. Sampai jumpa!');
redirect('login.php');
