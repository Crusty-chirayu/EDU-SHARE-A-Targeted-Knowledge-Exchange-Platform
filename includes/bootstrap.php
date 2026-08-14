<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/autoload.php';
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/http.php';
require_once __DIR__ . '/error_handler.php';
require_once __DIR__ . '/session.php';
require_once __DIR__ . '/csrf.php';
require_once __DIR__ . '/database.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/validation.php';
require_once __DIR__ . '/storage.php';
require_once __DIR__ . '/services.php';

$_SERVER['EDUSHARE_REQUEST_ID'] ??= bin2hex(random_bytes(8));
install_error_handlers();
start_secure_session();
send_security_headers();
