<?php
declare(strict_types=1);

// Compatibility entry point for legacy includes. New pages load includes/bootstrap.php directly.
require_once __DIR__ . '/includes/bootstrap.php';
if (realpath((string) ($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) {
    abort_request(404, 'Not found.');
}
$conn = db();
