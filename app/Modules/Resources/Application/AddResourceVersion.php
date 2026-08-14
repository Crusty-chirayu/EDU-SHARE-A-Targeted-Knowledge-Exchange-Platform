<?php
declare(strict_types=1);

namespace EduShare\Modules\Resources\Application;

use EduShare\Modules\Resources\Domain\ResourceAccessPolicy;

final class AddResourceVersion
{
    public function __construct(
        private readonly ResourceRepository $repository,
        private readonly ResourceStorage $storage,
        private readonly ResourceAccessPolicy $accessPolicy,
        private readonly int $maxFiles,
        private readonly int $maxTotalBytes
    ) {
    }

    /**
     * @param array<string, mixed> $actor
     * @param list<VerifiedResourceFile> $files
     */
    public function execute(int $resourceId, array $actor, array $files, ?string $changeDescription): CreatedResource
    {
        if ($resourceId < 1 || $files === [] || count($files) > $this->maxFiles) {
            throw new \InvalidArgumentException('The resource file count is outside the configured limit.');
        }
        $description = $changeDescription === null ? null : trim($changeDescription);
        if ($description !== null
            && (mb_strlen($description) > 500
                || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $description))) {
            throw new \InvalidArgumentException('The version description is invalid or too long.');
        }

        $storedKeys = [];
        $fileIds = [];
        $this->repository->begin();
        try {
            $resource = $this->repository->find($resourceId, true);
            if ($resource === null) {
                throw new ResourceNotFound('Resource not found.');
            }
            if (!$this->accessPolicy->canAddVersion($resource, $actor)) {
                throw new ResourceAccessDenied('Only the active contributor owner can add a version.');
            }
            $this->repository->lockOwner((int) $resource['owner_id']);
            $this->assertFileSet((int) $resource['owner_id'], $resourceId, $files);
            $versionNumber = (int) $resource['current_version_number'] + 1;
            $versionId = $this->repository->insertVersion(
                $resourceId,
                $versionNumber,
                (int) $actor['id'],
                $description === '' ? null : $description
            );
            foreach ($files as $file) {
                $storageKey = $this->storage->newKey($file->extension);
                // Track the identity before writing so an adapter that writes and then
                // throws can still be compensated.
                $storedKeys[] = $storageKey;
                $this->storage->putVerifiedUpload($file->temporaryPath, $storageKey);
                $fileIds[] = $this->repository->insertFile($versionId, $file, $storageKey);
            }
            $documentType = $this->documentType($files);
            $this->repository->advanceCurrentVersion($resourceId, $versionNumber, $documentType);
            $this->repository->commit();
            return new CreatedResource($resourceId, $versionId, $versionNumber, $fileIds);
        } catch (\Throwable $exception) {
            $this->compensateFailure($storedKeys, $exception);
        }
    }

    /** @param list<string> $storedKeys */
    private function compensateFailure(array $storedKeys, \Throwable $cause): never
    {
        $rollbackFailed = false;
        try {
            $this->repository->rollback();
        } catch (\Throwable) {
            $rollbackFailed = true;
        }

        $failedObjectCount = 0;
        foreach (array_reverse($storedKeys) as $storageKey) {
            try {
                if (!$this->storage->remove($storageKey)) {
                    $failedObjectCount++;
                }
            } catch (\Throwable) {
                $failedObjectCount++;
            }
        }

        if ($rollbackFailed || $failedObjectCount > 0) {
            throw new ResourceCleanupFailed($failedObjectCount, $rollbackFailed, $cause);
        }
        throw $cause;
    }

    /** @param list<VerifiedResourceFile> $files */
    private function assertFileSet(int $ownerId, int $sameResourceId, array $files): void
    {
        $checksums = [];
        $totalBytes = 0;
        foreach ($files as $file) {
            if (!$file instanceof VerifiedResourceFile) {
                throw new \InvalidArgumentException('Every resource file must be verified before persistence.');
            }
            if (isset($checksums[$file->checksumSha256])) {
                throw new DuplicateResourceFile('The same file content cannot appear twice in one resource version.');
            }
            $checksums[$file->checksumSha256] = true;
            $totalBytes += $file->size;
            $match = $this->repository->resourceIdForOwnerChecksum(
                $ownerId,
                $file->checksumSha256,
                $sameResourceId
            );
            if ($match !== null) {
                throw new DuplicateResourceFile('This account already owns the same file content in another resource.');
            }
        }
        if ($totalBytes > $this->maxTotalBytes) {
            throw new \InvalidArgumentException('The combined resource upload exceeds the configured limit.');
        }
    }

    /** @param list<VerifiedResourceFile> $files */
    private function documentType(array $files): string
    {
        $extensions = array_values(array_unique(array_map(
            static fn (VerifiedResourceFile $file): string => $file->extension,
            $files
        )));
        return count($extensions) === 1 ? $extensions[0] : 'mixed';
    }
}
