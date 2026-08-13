<?php
declare(strict_types=1);
require __DIR__ . '/includes/bootstrap.php';
require_method('GET');

$materialId = positive_int($_GET['id'] ?? null);
if ($materialId === null) {
    abort_request(422, 'A valid material ID is required.');
}
$material = find_material($materialId);
if ($material === null) {
    abort_request(404, 'Material not found.');
}

$user = auth_user();
if (!can_view_material($material, $user)) {
    abort_request($user === null ? 401 : 403, $user === null ? 'Log in to access this material.' : 'You cannot access this material.');
}

$path = safe_storage_path((string) $material['file_path']);
if ($path === null) {
    app_log('warning', 'Material storage object missing or invalid', ['material_id' => $materialId]);
    abort_request(404, 'The file is unavailable.');
}

$allowedMimes = [];
foreach (allowed_upload_types() as $type) {
    $allowedMimes = array_merge($allowedMimes, $type['mime']);
}
$mime = in_array($material['mime_type'], $allowedMimes, true)
    ? (string) $material['mime_type']
    : 'application/octet-stream';
$originalName = sanitize_original_filename((string) ($material['original_filename'] ?: 'material-' . $materialId));
$fallbackName = preg_replace('/[^A-Za-z0-9._-]/', '_', $originalName) ?: 'material-' . $materialId;
$inlineAllowed = in_array($mime, ['application/pdf', 'text/plain'], true);
$disposition = (isset($_GET['download']) && $_GET['download'] === '1') || !$inlineAllowed ? 'attachment' : 'inline';

header('Content-Type: ' . $mime);
header("Content-Disposition: {$disposition}; filename=\"{$fallbackName}\"; filename*=UTF-8''" . rawurlencode($originalName));
header('Content-Length: ' . (string) filesize($path));
header('Cache-Control: private, no-store, max-age=0');
header('Pragma: no-cache');
header('X-Content-Type-Options: nosniff');

$stream = fopen($path, 'rb');
if ($stream === false) {
    abort_request(500, 'The file could not be read.');
}
fpassthru($stream);
fclose($stream);
exit;
