<?php
// Titik awal setiap halaman PHP.
// Urutan: muat config -> atur error -> mulai session -> muat helper.
// Halaman cukup menulis: require __DIR__ . '/includes/bootstrap.php';

declare(strict_types=1);

$configFile = __DIR__ . '/../config/config.php';
if (!is_file($configFile)) {
    http_response_code(500);
    exit('config/config.php belum ada. Salin config/config.example.php menjadi config/config.php lalu isi.');
}
require $configFile;

date_default_timezone_set(APP_TIMEZONE);

// ---------------------------------------------------------------------
// Error: di produksi pesan teknis disembunyikan, tetapi tetap dicatat.
// ---------------------------------------------------------------------
error_reporting(E_ALL);
ini_set('display_errors', APP_DEBUG ? '1' : '0');
ini_set('log_errors', '1');

// ---------------------------------------------------------------------
// Session
// ---------------------------------------------------------------------
$isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
    || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');

// strict_mode menolak ID session buatan penyerang (session fixation).
ini_set('session.use_strict_mode', '1');
session_name('go_session');
session_set_cookie_params([
    'lifetime' => 0,          // hilang saat browser ditutup
    'path'     => '/',
    'secure'   => $isHttps,   // cookie hanya dikirim lewat HTTPS bila situs memakai HTTPS
    'httponly' => true,       // tidak bisa dibaca JavaScript
    'samesite' => 'Lax',
]);
session_start();

// Header keamanan dasar untuk semua halaman.
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: SAMEORIGIN');
header('Referrer-Policy: same-origin');

// Tampung output dulu, supaya halaman error bisa menggantikan halaman
// yang gagal di tengah jalan (lihat render_error_page()).
ob_start();

require __DIR__ . '/errors.php';
require __DIR__ . '/csrf.php';
require __DIR__ . '/auth.php';

// Semua exception yang tidak tertangkap berakhir di sini:
// detail dicatat ke log, pengguna melihat halaman error yang rapi.
set_exception_handler(function (Throwable $e): void {
    error_log('[GestureOrchestra] ' . get_class($e) . ': ' . $e->getMessage()
        . ' di ' . $e->getFile() . ':' . $e->getLine());

    $detail = APP_DEBUG ? $e->getMessage() . ' (' . basename($e->getFile()) . ':' . $e->getLine() . ')' : null;
    render_error_page(500, 'Ada yang macet di server', 'Coba muat ulang halaman sebentar lagi.', $detail);
});

// ---------------------------------------------------------------------
// Koneksi database (PDO)
// Koneksi baru dibuat saat db() pertama kali dipanggil, jadi halaman
// yang tidak butuh database tidak membuka koneksi sama sekali.
// ---------------------------------------------------------------------
function db(): PDO
{
    static $pdo = null;

    if ($pdo === null) {
        $dsn = 'mysql:host=' . DB_HOST . ';port=' . DB_PORT . ';dbname=' . DB_NAME . ';charset=utf8mb4';
        $pdo = new PDO($dsn, DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
        // Samakan zona waktu MySQL dengan PHP, supaya NOW() dan
        // CURRENT_TIMESTAMP sama dengan date() di PHP.
        $pdo->exec("SET time_zone = '" . date('P') . "'");
    }

    return $pdo;
}

// ---------------------------------------------------------------------
// Helper umum
// ---------------------------------------------------------------------

// Escape semua teks sebelum dicetak ke HTML.
function e(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

// Buat URL absolut dari path relatif terhadap folder aplikasi.
// Contoh: url('admin/index.php') -> '/gestureorchestra/admin/index.php'
function url(string $path = ''): string
{
    return BASE_URL . '/' . ltrim($path, '/');
}

// URL file CSS atau JS lokal dengan nomor versi = waktu terakhir file diubah.
// Contoh: asset('assets/css/app.css') -> '/gestureorchestra/assets/css/app.css?v=1761200000'
// Setiap kali file diubah, URL-nya ikut berubah, jadi browser tidak memakai
// salinan lama dari cache (tanpa perlu Ctrl+Shift+R saat demo).
function asset(string $path): string
{
    $file = dirname(__DIR__) . '/' . ltrim($path, '/');
    $version = is_file($file) ? filemtime($file) : 0;
    return url($path) . '?v=' . $version;
}

// Import map untuk semua modul di assets/js/.
// asset() hanya memberi versi pada <script src>. Modul yang di-import dari dalam
// JavaScript (import ... from './api.js') tidak ikut berversi, sehingga bisa
// tetap memakai cache lama. Import map memetakan URL setiap modul ke URL
// berversinya, dan berlaku juga untuk import di dalam modul.
// Hasil: JSON untuk dicetak di <script type="importmap"> sebelum script modul apa pun.
function js_import_map(): string
{
    $imports = [];
    foreach (glob(dirname(__DIR__) . '/assets/js/*.js') ?: [] as $file) {
        $path = 'assets/js/' . basename($file);
        $imports[url($path)] = asset($path);
    }
    // JSON_HEX_TAG: karakter < dan > di-escape agar aman di dalam tag <script>.
    return json_encode(['imports' => $imports], JSON_UNESCAPED_SLASHES | JSON_HEX_TAG);
}

function redirect(string $path): never
{
    header('Location: ' . url($path));
    exit;
}

function is_post(): bool
{
    return $_SERVER['REQUEST_METHOD'] === 'POST';
}

// Ambil teks dari $_POST yang sudah di-trim. Nilai bukan string dianggap kosong.
function post_str(string $key): string
{
    $value = $_POST[$key] ?? '';
    return is_string($value) ? trim($value) : '';
}

// Pesan sekali tampil (flash) yang disimpan di session sampai halaman berikutnya.
// $type: 'success' atau 'error'.
function flash(string $type, string $message): void
{
    $_SESSION['flash'][] = ['type' => $type, 'message' => $message];
}

function take_flashes(): array
{
    $messages = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);
    return $messages;
}

// Format tanggal dari database untuk ditampilkan, misalnya "22 Okt 2026, 19.47".
function format_datetime(?string $value): string
{
    if ($value === null || $value === '') {
        return '-';
    }
    $months = ['Jan', 'Feb', 'Mar', 'Apr', 'Mei', 'Jun', 'Jul', 'Agu', 'Sep', 'Okt', 'Nov', 'Des'];
    $time = strtotime($value);
    return date('j', $time) . ' ' . $months[(int) date('n', $time) - 1] . ' ' . date('Y, H.i', $time);
}

// Nomor error MySQL dari PDOException, misalnya:
//   1062 = data kembar (melanggar UNIQUE)
//   1451 = data masih dipakai tabel lain (foreign key menolak hapus)
function db_error_number(PDOException $e): int
{
    return (int) ($e->errorInfo[1] ?? 0);
}

// Ambil satu baris; bila tidak ada, tampilkan halaman 404.
function fetch_row_or_404(string $sql, array $params, string $notFoundTitle): array
{
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    $row = $stmt->fetch();
    if ($row === false) {
        render_error_page(404, $notFoundTitle, 'Mungkin datanya sudah dihapus. Kembali ke daftar lalu coba lagi.');
    }
    return $row;
}
