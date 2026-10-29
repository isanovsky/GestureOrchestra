<?php
// Halaman login untuk superadmin dan admin.

require __DIR__ . '/includes/bootstrap.php';

// Batas percobaan login per session: 5 kali gagal dalam 10 menit.
const LOGIN_MAX_FAILS = 5;
const LOGIN_WINDOW_SECONDS = 600;

// Daftar waktu gagal login yang masih di dalam jendela 10 menit.
function recent_login_failures(): array
{
    $now = time();
    $fails = $_SESSION['login_failures'] ?? [];
    return array_values(array_filter($fails, fn(int $t) => $now - $t < LOGIN_WINDOW_SECONDS));
}

// Sudah login? Langsung ke dashboard masing-masing.
$me = current_user();
if ($me !== null) {
    redirect(dashboard_path($me['role']));
}

$error = '';
$username = '';

if (is_post()) {
    csrf_verify();

    $username = post_str('username');
    $password = $_POST['password'] ?? '';
    $password = is_string($password) ? $password : '';

    $failures = recent_login_failures();

    if (count($failures) >= LOGIN_MAX_FAILS) {
        $waitMinutes = (int) ceil((LOGIN_WINDOW_SECONDS - (time() - min($failures))) / 60);
        $error = "Terlalu banyak percobaan. Coba lagi dalam {$waitMinutes} menit.";
    } elseif ($username === '' || $password === '') {
        $error = 'Username dan password wajib diisi.';
    } else {
        $stmt = db()->prepare('SELECT id, username, password_hash, role, is_active FROM users WHERE username = ?');
        $stmt->execute([$username]);
        $user = $stmt->fetch();

        if ($user === false || !password_verify($password, $user['password_hash'])) {
            $failures[] = time();
            $_SESSION['login_failures'] = $failures;
            $error = 'Username atau password salah.';
        } elseif ((int) $user['is_active'] !== 1) {
            $error = 'Akun ini sedang dinonaktifkan. Hubungi superadmin.';
        } else {
            unset($_SESSION['login_failures']);
            login_user($user);
            redirect(dashboard_path($user['role']));
        }
    }
}

$pageTitle = 'Masuk';
$bodyClass = 'page-login';
require __DIR__ . '/includes/layout_header.php';
?>
<section class="login-wrap">
    <div class="login-card paper">
        <img class="login-mascot" src="<?= e(url('assets/img/mascot-wave.svg')) ?>" alt="" width="120" height="140">
        <h1 class="wavy">Masuk ke ruang kru</h1>
        <p class="muted">Khusus admin dan superadmin. Pemain tidak perlu akun, langsung main saja.</p>

        <?php if ($error !== ''): ?>
            <p class="alert alert-error" role="alert"><?= e($error) ?></p>
        <?php endif; ?>

        <form method="post" action="<?= e(url('login.php')) ?>" class="form">
            <?= csrf_field() ?>
            <label for="username">Username</label>
            <input id="username" name="username" type="text" maxlength="50" autocomplete="username" required autofocus value="<?= e($username) ?>">

            <label for="password">Password</label>
            <input id="password" name="password" type="password" maxlength="255" autocomplete="current-password" required>

            <button type="submit" class="btn btn-primary">Masuk</button>
        </form>
    </div>
</section>
<?php require __DIR__ . '/includes/layout_footer.php'; ?>
