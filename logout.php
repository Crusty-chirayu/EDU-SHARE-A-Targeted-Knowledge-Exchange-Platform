<?php
declare(strict_types=1);
require __DIR__ . '/includes/bootstrap.php';

require_method('POST');
require_auth();
require_csrf();
destroy_current_session();
redirect('index.php');
