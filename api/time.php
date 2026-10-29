<?php
// GET api/time.php
// Waktu server dalam milidetik. Dipakai assets/js/clock-sync.js untuk
// menghitung selisih jam perangkat pemain dengan jam server.

require __DIR__ . '/../includes/bootstrap.php';
require __DIR__ . '/../includes/api.php';

require_method('GET');
json_response(['ok' => true, 'server_ms' => (int) floor(microtime(true) * 1000)]);
