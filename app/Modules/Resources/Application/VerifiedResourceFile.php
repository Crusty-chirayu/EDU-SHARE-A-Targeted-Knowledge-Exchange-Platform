<?php
declare(strict_types=1);

namespace EduShare\Modules\Resources\Application;

final class VerifiedResourceFile
{
    public function __construct(
        public readonly string $temporaryPath,
        public readonly string $originalFilename,
        public readonly string $extension,
        public readonly string $mimeType,
        public readonly int $size,
        public readonly string $checksumSha256
    ) {
        if (!is_file($temporaryPath)) {
            throw new \InvalidArgumentException('Verified file content is unavailable.');
        }
        if ($originalFilename === ''
            || mb_strlen($originalFilename) > 180
            || str_contains($originalFilename, '/')
            || str_contains($originalFilename, '\\')
            || preg_match('/[\x00-\x1F\x7F]/', $originalFilename)) {
            throw new \InvalidArgumentException('Verified file name is invalid.');
        }
        if (preg_match('/^[a-z0-9]{1,10}$/D', $extension) !== 1
            || $mimeType === ''
            || strlen($mimeType) > 150
            || preg_match('/[\x00-\x20\x7F]/', $mimeType)) {
            throw new \InvalidArgumentException('Verified file type metadata is invalid.');
        }
        if ($size < 1 || preg_match('/^[a-f0-9]{64}$/D', $checksumSha256) !== 1) {
            throw new \InvalidArgumentException('Verified file integrity metadata is invalid.');
        }
    }
}
