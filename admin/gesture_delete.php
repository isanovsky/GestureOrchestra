<?php
// Hapus pemetaan gestur. Hanya menerima POST dengan token CSRF.

require __DIR__ . '/../includes/bootstrap.php';
$user = require_role('admin');

if (!is_post()) {
    redirect('admin/instruments.php');
}
csrf_verify();

$id = (int) ($_POST['id'] ?? 0);

$stmt = db()->prepare('SELECT instrument_id, finger_count FROM gestures WHERE id = ?');
$stmt->execute([$id]);
$gesture = $stmt->fetch();

if ($gesture === false) {
    flash('error', 'Gestur tidak ditemukan. Mungkin sudah dihapus.');
    redirect('admin/instruments.php');
}

$stmt = db()->prepare('DELETE FROM gestures WHERE id = ?');
$stmt->execute([$id]);
flash('success', 'Gestur ' . $gesture['finger_count'] . ' jari sudah dihapus.');

redirect('admin/instrument.php?id=' . (int) $gesture['instrument_id']);
