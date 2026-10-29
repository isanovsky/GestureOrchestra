<?php
// Login, logout, dan pemeriksaan role.
//
// Session hanya menyimpan user_id dan role. Setiap request, data user
// dibaca ulang dari database, sehingga admin yang dinonaktifkan atau
// dihapus oleh superadmin langsung kehilangan akses tanpa menunggu logout.

// Data user yang sedang login, atau null bila belum login.
function current_user(): ?array
{
    static $loaded = false;
    static $user = null;

    if ($loaded) {
        return $user;
    }
    $loaded = true;

    if (empty($_SESSION['user_id'])) {
        return null;
    }

    $stmt = db()->prepare('SELECT id, name, username, email, role, is_active FROM users WHERE id = ?');
    $stmt->execute([(int) $_SESSION['user_id']]);
    $row = $stmt->fetch();

    if ($row === false || (int) $row['is_active'] !== 1) {
        logout_user();
        return null;
    }

    $_SESSION['role'] = $row['role'];
    $user = $row;
    return $user;
}

// Halaman yang butuh login. Belum login -> diarahkan ke login.php.
function require_login(): array
{
    $user = current_user();
    if ($user === null) {
        flash('error', 'Silakan masuk dulu.');
        redirect('login.php');
    }
    return $user;
}

// Halaman khusus role tertentu. Role salah -> HTTP 403.
// Contoh: require_role('admin');  atau  require_role('admin', 'superadmin');
function require_role(string ...$roles): array
{
    $user = require_login();
    if (!in_array($user['role'], $roles, true)) {
        render_error_page(403, 'Pintu ini bukan untukmu', 'Akunmu tidak punya izin membuka halaman ini.');
    }
    return $user;
}

// Dashboard tujuan setelah login, sesuai role.
function dashboard_path(string $role): string
{
    return $role === 'superadmin' ? 'superadmin/index.php' : 'admin/index.php';
}

function login_user(array $user): void
{
    // ID session baru setelah login mencegah session fixation.
    session_regenerate_id(true);
    $_SESSION['user_id'] = (int) $user['id'];
    $_SESSION['role'] = $user['role'];
    // Token CSRF baru untuk sesi login yang baru.
    unset($_SESSION['csrf_token']);

    $stmt = db()->prepare('UPDATE users SET last_login_at = NOW() WHERE id = ?');
    $stmt->execute([(int) $user['id']]);
}

function logout_user(): void
{
    $_SESSION = [];
    session_regenerate_id(true);
}
