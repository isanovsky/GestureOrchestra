<?php
// Template konfigurasi GestureOrchestra.
// Salin file ini menjadi config/config.php lalu isi sesuai lingkungan.
// config/config.php TIDAK boleh di-commit (sudah ada di .gitignore).

// Koneksi database.
// XAMPP lokal: host 'localhost', user 'root', password kosong.
// InfinityFree: host dari Control Panel (misalnya sql123.infinityfree.com),
// nama database dan user berawalan if0_xxxxxxx.
const DB_HOST = 'localhost';
const DB_PORT = 3306;
const DB_NAME = 'gestureorchestra';
const DB_USER = 'root';
const DB_PASS = '';

// Folder aplikasi relatif terhadap domain, TANPA garis miring di akhir.
// XAMPP: 'http://localhost/gestureorchestra' berarti '/gestureorchestra'.
// InfinityFree (file langsung di htdocs): ''.
const BASE_URL = '/gestureorchestra';

// true  = tampilkan detail error (hanya untuk pengembangan lokal).
// false = tampilkan pesan umum, detail dicatat lewat error_log().
const APP_DEBUG = false;

// Zona waktu aplikasi dan database (WIB).
const APP_TIMEZONE = 'Asia/Jakarta';
