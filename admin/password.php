<?php
// Admin mengganti password akunnya sendiri.
// Logika dan form ada di includes/change_password.php (dipakai bersama).

require __DIR__ . '/../includes/bootstrap.php';
$user = require_role('admin');

$passwordPath = 'admin/password.php';
require __DIR__ . '/../includes/change_password.php';
