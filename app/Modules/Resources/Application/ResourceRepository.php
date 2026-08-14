<?php
declare(strict_types=1);

namespace EduShare\Modules\Resources\Application;

interface ResourceRepository
{
    public function begin(): void;

    /** Serializes owner-scoped duplicate checks inside the active transaction. */
    public function lockOwner(int $ownerId): void;

    /** Locks and verifies the complete active academic lineage for a new resource. */
    public function lockActiveAcademicPath(ResourceMetadata $metadata): bool;

    public function commit(): void;
    public function rollback(): void;

    /** @return array<string, mixed>|null */
    public function find(int $resourceId, bool $forUpdate = false): ?array;

    /** @return array<string, mixed>|null */
    public function findFileForDownload(int $resourceId, ?int $fileId): ?array;

    /** @return list<array<string, mixed>> */
    public function versionsWithFiles(int $resourceId): array;

    public function resourceIdForOwnerChecksum(
        int $ownerId,
        string $checksum,
        ?int $excludingResourceId = null
    ): ?int;
    public function insertResource(int $ownerId, ResourceMetadata $metadata, string $documentType): int;
    public function insertVersion(int $resourceId, int $versionNumber, int $creatorId, ?string $changeDescription): int;
    public function insertFile(int $versionId, VerifiedResourceFile $file, string $storageKey): int;
    public function advanceCurrentVersion(int $resourceId, int $versionNumber, string $documentType): void;
    public function updateMetadata(int $resourceId, ResourceMetadataUpdate $changes): void;

    /** @return list<array{id: int, storage_key: string, storage_status: string, quarantine_token: ?string}> */
    public function lockStoredFiles(int $resourceId): array;

    public function setFileQuarantine(int $fileId, string $quarantineToken, string $status = 'quarantined'): void;
    public function setOneFileStorageStatus(int $fileId, string $status): void;
    public function markResourceDeleted(int $resourceId, string $status): void;
    public function setResourceDeletionStatus(int $resourceId, string $status): void;
}
