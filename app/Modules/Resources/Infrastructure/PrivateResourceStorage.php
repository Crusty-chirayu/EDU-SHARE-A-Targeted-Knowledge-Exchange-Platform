<?php
declare(strict_types=1);

namespace EduShare\Modules\Resources\Infrastructure;

use EduShare\Modules\Resources\Application\ResourceStorage;

/** Adapter around the Phase 0 hardened private-storage functions. */
final class PrivateResourceStorage implements ResourceStorage
{
    public function newKey(string $extension): string
    {
        return \new_storage_key($extension);
    }

    public function putVerifiedUpload(string $temporaryPath, string $storageKey): void
    {
        \store_uploaded_file($temporaryPath, $storageKey);
    }

    public function openReadStream(string $storageKey)
    {
        $path = \safe_storage_path($storageKey);
        if ($path === null) {
            return null;
        }
        $stream = @fopen($path, 'rb');
        return $stream === false ? null : $stream;
    }

    public function remove(string $storageKey): bool
    {
        return \remove_storage_object($storageKey);
    }

    public function quarantine(string $storageKey): ?string
    {
        return \quarantine_storage_object($storageKey);
    }

    public function restore(string $quarantineToken, string $storageKey): bool
    {
        return \restore_quarantined_storage_object($quarantineToken, $storageKey);
    }

    public function purge(string $quarantineToken): bool
    {
        return \purge_quarantined_storage_object($quarantineToken);
    }
}
