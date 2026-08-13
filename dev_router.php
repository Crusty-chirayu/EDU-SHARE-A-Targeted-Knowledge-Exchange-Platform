<?php
declare(strict_types=1);

$uriPath = rawurldecode((string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH));
$relativePath = ltrim(str_replace('\\', '/', $uriPath), '/');

if ($relativePath !== '' && (
    str_contains($relativePath, "\0")
    || preg_match('#(^|/)\.#', $relativePath)
    || preg_match('#^(?:app|routes|views|includes|database|scripts|storage|tests|deploy)(?:/|$)#i', $relativePath)
    || preg_match('#\.(?:env|ini|log|sql|sh|bak)$#i', $relativePath)
)) {
    http_response_code(404);
    exit('Not found');
}

if ($relativePath === '') {
    require __DIR__ . '/index.php';
    return true;
}

if (str_starts_with($uriPath, '/api/v1/')) {
    $_SERVER['PATH_INFO'] = $uriPath;
    require __DIR__ . '/app.php';
    return true;
}

$file = __DIR__ . '/' . $relativePath;
if (is_file($file)) {
    return false;
}

http_response_code(404);
echo 'Not found';
