<?php
declare(strict_types=1);
require __DIR__ . '/includes/bootstrap.php';
require_method('GET');

$resourceId = positive_int($_GET['id'] ?? null);
$fileId = isset($_GET['file_id']) ? positive_int($_GET['file_id']) : null;
if ($resourceId === null || (isset($_GET['file_id']) && $fileId === null)) {
    abort_request(422, 'Valid resource and file identifiers are required.');
}

$file = find_resource_file($resourceId, $fileId);
if ($file === null) {
    abort_request(404, 'Resource file not found.');
}
$user = auth_user();
if (!can_view_resource($file, $user)) {
    abort_request($user === null ? 401 : 403, $user === null
        ? 'Log in to access this resource.'
        : 'You cannot access this resource.');
}

$stream = resource_storage()->openReadStream((string) $file['storage_key']);
if ($stream === null) {
    app_log('warning', 'Resource storage object missing or invalid', [
        'resource_id' => $resourceId,
        'file_id' => $file['id'],
    ]);
    abort_request(404, 'The file is unavailable.');
}
$statistics = fstat($stream);
$checksumContext = hash_init('sha256');
$hashedBytes = hash_update_stream($checksumContext, $stream);
$actualChecksum = hash_final($checksumContext);
$actualSize = $statistics === false ? null : ($statistics['size'] ?? null);
if (!is_int($actualSize)
    || $hashedBytes !== $actualSize
    || $actualSize !== (int) $file['file_size']
    || !hash_equals((string) $file['checksum_sha256'], $actualChecksum)
    || !rewind($stream)) {
    fclose($stream);
    app_log('error', 'Resource storage integrity check failed', [
        'resource_id' => $resourceId,
        'file_id' => $file['id'],
    ]);
    abort_request(409, 'The file is temporarily unavailable because its integrity could not be verified.');
}

$allowedMimes = [];
foreach (allowed_upload_types() as $type) {
    $allowedMimes = array_merge($allowedMimes, $type['mime']);
}
$mime = in_array($file['mime_type'], $allowedMimes, true)
    ? (string) $file['mime_type']
    : 'application/octet-stream';
$originalName = sanitize_original_filename((string) ($file['original_filename'] ?: 'resource-' . $resourceId));
$fallbackName = preg_replace('/[^A-Za-z0-9._-]/', '_', $originalName) ?: 'resource-' . $resourceId;
$inlineAllowed = in_array($mime, ['application/pdf', 'text/plain'], true);
$disposition = (isset($_GET['download']) && $_GET['download'] === '1') || !$inlineAllowed ? 'attachment' : 'inline';

header('Content-Type: ' . $mime);
header("Content-Disposition: {$disposition}; filename=\"{$fallbackName}\"; filename*=UTF-8''" . rawurlencode($originalName));
header('Content-Length: ' . (string) $actualSize);
header('Cache-Control: private, no-store, max-age=0');
header('Pragma: no-cache');
header('X-Content-Type-Options: nosniff');

fpassthru($stream);
fclose($stream);
exit;
