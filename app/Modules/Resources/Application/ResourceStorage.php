<?php
declare(strict_types=1);

namespace EduShare\Modules\Resources\Application;

interface ResourceStorage
{
    public function newKey(string $extension): string;
    public function putVerifiedUpload(string $temporaryPath, string $storageKey): void;

    /** @return resource|null A read-only stream, or null when the object is unavailable. */
    public function openReadStream(string $storageKey);

    /** Returns true only when the object is absent after the call (including already missing). */
    public function remove(string $storageKey): bool;

    /** Returns an opaque quarantine token, or null when the object is already missing. */
    public function quarantine(string $storageKey): ?string;
    public function restore(string $quarantineToken, string $storageKey): bool;
    public function purge(string $quarantineToken): bool;
}
