<?php
// Tayangkan atau sembunyikan style arranger. POST: id, publish (1 atau 0).

require __DIR__ . '/../includes/bootstrap.php';
$user = require_role('admin');

if (!is_post()) {
    redirect('admin/arranger_styles.php');
}
csrf_verify();

$id = (int) ($_POST['id'] ?? 0);
$publish = ($_POST['publish'] ?? '') === '1' ? 1 : 0;

$stmt = db()->prepare('SELECT name FROM arranger_styles WHERE id = ?');
$stmt->execute([$id]);
$name = $stmt->fetchColumn();

if ($name === false) {
    flash('error', 'Style tidak ditemukan.');
    redirect('admin/arranger_styles.php');
}

// Tanpa Main A, iringan tidak punya pola untuk diulang, jadi style belum boleh tayang.
if ($publish === 1) {
    $stmt = db()->prepare("SELECT COUNT(*) FROM arranger_sections WHERE style_id = ? AND section = 'main_a'");
    $stmt->execute([$id]);
    if ((int) $stmt->fetchColumn() === 0) {
        flash('error', 'Style ' . $name . ' belum punya Main A. Tulis polanya dulu sebelum ditayangkan.');
        redirect('admin/arranger_styles.php');
    }
}

$stmt = db()->prepare('UPDATE arranger_styles SET is_published = ? WHERE id = ?');
$stmt->execute([$publish, $id]);

flash('success', $publish === 1
    ? 'Style ' . $name . ' sekarang tayang di halaman Arranger.'
    : 'Style ' . $name . ' disembunyikan dari halaman Arranger.');
redirect('admin/arranger_styles.php');
