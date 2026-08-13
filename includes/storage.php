<?php
declare(strict_types=1);

function allowed_upload_types(): array
{
    return [
        'pdf' => ['mime' => ['application/pdf'], 'family' => 'pdf'],
        'txt' => ['mime' => ['text/plain'], 'family' => 'text'],
        'doc' => ['mime' => ['application/msword', 'application/x-ole-storage', 'application/CDFV2'], 'family' => 'ole'],
        'xls' => ['mime' => ['application/vnd.ms-excel', 'application/x-ole-storage', 'application/CDFV2'], 'family' => 'ole'],
        'ppt' => ['mime' => ['application/vnd.ms-powerpoint', 'application/x-ole-storage', 'application/CDFV2'], 'family' => 'ole'],
        'docx' => ['mime' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'application/zip'], 'family' => 'word/'],
        'xlsx' => ['mime' => ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'application/zip'], 'family' => 'xl/'],
        'pptx' => ['mime' => ['application/vnd.openxmlformats-officedocument.presentationml.presentation', 'application/zip'], 'family' => 'ppt/'],
    ];
}

function sanitize_original_filename(string $name): string
{
    $name = str_replace(['\\', '/'], '_', $name);
    $name = preg_replace('/[\x00-\x1F\x7F]+/u', '', $name) ?? '';
    $name = preg_replace('/\s+/u', ' ', trim($name)) ?? '';
    if ($name === '') {
        return 'document';
    }
    if (strlen($name) > 180) {
        $extension = pathinfo($name, PATHINFO_EXTENSION);
        $suffix = $extension === '' ? '' : '.' . substr($extension, 0, 10);
        $name = substr($name, 0, 180 - strlen($suffix)) . $suffix;
    }
    return $name;
}

function upload_magic_matches(string $path, string $family): bool
{
    $head = file_get_contents($path, false, null, 0, 8192);
    if ($head === false) {
        return false;
    }

    return match ($family) {
        'pdf' => str_starts_with($head, '%PDF-'),
        'text' => !str_contains($head, "\0") && preg_match('//u', $head) === 1,
        'ole' => str_starts_with($head, "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1"),
        'word/', 'xl/', 'ppt/' => upload_ooxml_matches($path, $family),
        default => false,
    };
}

function upload_ooxml_matches(string $path, string $requiredDirectory): bool
{
    $head = file_get_contents($path, false, null, 0, 4);
    if ($head === false || !in_array($head, ["PK\x03\x04", "PK\x05\x06", "PK\x07\x08"], true)) {
        return false;
    }

    // ZIP entry names are also present in the central directory, even when file bodies are compressed.
    $archive = file_get_contents($path);
    return $archive !== false
        && str_contains($archive, '[Content_Types].xml')
        && str_contains($archive, $requiredDirectory);
}

function validate_upload_candidate(
    string $originalName,
    string $temporaryPath,
    int $size,
    int $uploadError,
    bool $requireUploadedFile = true
): array {
    if ($uploadError !== UPLOAD_ERR_OK) {
        return [null, 'The upload did not complete successfully.'];
    }
    if ($size < 1 || $size > (int) app_config('upload.max_bytes')) {
        return [null, 'The file must be non-empty and no larger than ' . format_bytes((int) app_config('upload.max_bytes')) . '.'];
    }
    if (!is_file($temporaryPath) || ($requireUploadedFile && !is_uploaded_file($temporaryPath))) {
        return [null, 'The uploaded file could not be verified.'];
    }

    $cleanName = sanitize_original_filename($originalName);
    $extension = strtolower(pathinfo($cleanName, PATHINFO_EXTENSION));
    $types = allowed_upload_types();
    if (!isset($types[$extension])) {
        return [null, 'This file type is not supported.'];
    }

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $detectedMime = (string) $finfo->file($temporaryPath);
    if (!in_array($detectedMime, $types[$extension]['mime'], true)
        || !upload_magic_matches($temporaryPath, $types[$extension]['family'])) {
        return [null, 'The file content does not match its extension.'];
    }

    return [[
        'original_name' => $cleanName,
        'extension' => $extension,
        'mime_type' => $detectedMime,
        'size' => $size,
        'checksum' => hash_file('sha256', $temporaryPath),
    ], null];
}

function normalize_uploaded_files(array $files): array
{
    if (!isset($files['name'], $files['tmp_name'], $files['error'], $files['size'])) {
        return [];
    }

    $names = is_array($files['name']) ? $files['name'] : [$files['name']];
    $temporary = is_array($files['tmp_name']) ? $files['tmp_name'] : [$files['tmp_name']];
    $errors = is_array($files['error']) ? $files['error'] : [$files['error']];
    $sizes = is_array($files['size']) ? $files['size'] : [$files['size']];
    if (count($names) !== count($temporary) || count($names) !== count($errors) || count($names) !== count($sizes)) {
        return [];
    }

    $result = [];
    foreach ($names as $index => $name) {
        if (!is_string($name) || $name === '') {
            continue;
        }
        $result[] = [
            'name' => $name,
            'tmp_name' => (string) ($temporary[$index] ?? ''),
            'error' => (int) ($errors[$index] ?? UPLOAD_ERR_NO_FILE),
            'size' => (int) ($sizes[$index] ?? 0),
        ];
    }
    return $result;
}

function upload_storage_directory(): string
{
    return (string) app_config('storage_path') . '/uploads';
}

function ensure_private_directory(string $directory): void
{
    if (!is_dir($directory) && !mkdir($directory, 0750, true) && !is_dir($directory)) {
        throw new RuntimeException('Unable to create private storage directory.');
    }
    @chmod($directory, 0750);
}

function new_storage_key(string $extension): string
{
    if (!array_key_exists($extension, allowed_upload_types())) {
        throw new InvalidArgumentException('Unsupported storage extension.');
    }
    return bin2hex(random_bytes(16)) . '.' . $extension;
}

function safe_storage_path(string $storageKey, bool $mustExist = true): ?string
{
    $extensions = implode('|', array_map('preg_quote', array_keys(allowed_upload_types())));
    if (!preg_match('/^[a-f0-9]{32}\.(' . $extensions . ')$/D', $storageKey)) {
        return null;
    }

    $directory = upload_storage_directory();
    if (!is_dir($directory)) {
        return null;
    }
    $root = realpath($directory);
    if ($root === false) {
        return null;
    }

    $candidate = $root . DIRECTORY_SEPARATOR . $storageKey;
    if (!$mustExist) {
        return $candidate;
    }

    $resolved = realpath($candidate);
    if ($resolved === false
        || !str_starts_with($resolved, $root . DIRECTORY_SEPARATOR)
        || !is_file($resolved)
        || is_link($candidate)) {
        return null;
    }
    return $resolved;
}

function store_uploaded_file(string $temporaryPath, string $storageKey): string
{
    ensure_private_directory(upload_storage_directory());
    $destination = safe_storage_path($storageKey, false);
    if ($destination === null || file_exists($destination) || !move_uploaded_file($temporaryPath, $destination)) {
        throw new RuntimeException('Unable to move verified upload into private storage.');
    }
    @chmod($destination, 0640);
    return $destination;
}

function format_bytes(int $bytes): string
{
    if ($bytes >= 1024 * 1024) {
        return number_format($bytes / (1024 * 1024), 1) . ' MB';
    }
    return number_format($bytes / 1024, 1) . ' KB';
}
