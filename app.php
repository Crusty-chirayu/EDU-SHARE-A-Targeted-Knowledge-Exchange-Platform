<?php
declare(strict_types=1);

require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/app/autoload.php';

use EduShare\Shared\Http\Request;
use EduShare\Shared\Http\ResponseEmitter;
use EduShare\Shared\Kernel\ApplicationFactory;

$routeValue = $_SERVER['PATH_INFO'] ?? parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
$basePath = (string) app_config('base_path');
if (is_string($routeValue) && $basePath !== '' && str_starts_with($routeValue, $basePath . '/')) {
    $routeValue = substr($routeValue, strlen($basePath));
}
if (!is_string($routeValue)
    || $routeValue === ''
    || $routeValue[0] !== '/'
    || strlen($routeValue) > 2048
    || str_contains($routeValue, "\0")) {
    $routeValue = '/__invalid_route__';
}

$response = ApplicationFactory::create()->handle(Request::fromGlobals($routeValue));
(new ResponseEmitter())->emit($response);
