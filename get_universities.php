<?php
declare(strict_types=1);
require __DIR__ . '/includes/bootstrap.php';
require_method('GET');

$rows = db()->query(
    'SELECT id, name FROM universities WHERE is_active = 1 ORDER BY name'
)->fetch_all(MYSQLI_ASSOC);
json_response($rows);
