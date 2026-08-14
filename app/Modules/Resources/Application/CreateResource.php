<?php
declare(strict_types=1);

namespace EduShare\Modules\Resources\Application;

final class CreateResource
{
    public function __construct(
        private readonly ResourceRepository $repository,
        private readonly ResourceStorage $storage,
        private readonly int $maxFiles,
        private readonly int $maxTotalBytes
    ) {
    }

    /** @param list<VerifiedResourceFile> $files */
    public function execute(int $ownerId, ResourceMetadata $metadata, array $files): CreatedResource
    {
        if ($ownerId < 1) {
            throw new \InvalidArgumentException('The resource owner is invalid.');
        }
        $storedKeys = [];
        $fileIds = [];

        $this->repository->begin();
        try {
            $this->repository->lockOwner($ownerId);
            if (!$this->repository->lockActiveAcademicPath($metadata)) {
                throw new \InvalidArgumentException(
                    'The selected academic context is unavailable or crosses parent boundaries.'
                );
            }
            $this->assertFileSet($ownerId, null, $files);
            $documentType = $this->documentType($files);
            $resourceId = $this->repository->insertResource($ownerId, $metadata, $documentType);
            $versionId = $this->repository->insertVersion($resourceId, 1, $ownerId, 'Initial version');
            foreach ($files as $file) {
                $storageKey = $this->storage->newKey($file->extension);
                // Track the identity before writing so an adapter that writes and then
                // throws can still be compensated.
                $storedKeys[] = $storageKey;
                $this->storage->putVerifiedUpload($file->temporaryPath, $storageKey);
                $fileIds[] = $this->repository->insertFile($versionId, $file, $storageKey);
            }
            // insertResource() and insertVersion() establish version 1/current directly;
            // avoid relying on a no-op UPDATE reporting one affected row.
            $this->repository->commit();
            return new CreatedResource($resourceId, $versionId, 1, $fileIds);
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
    private function assertFileSet(int $ownerId, ?int $sameResourceId, array $files): void
    {
        if ($ownerId < 1 || $files === [] || count($files) > $this->maxFiles) {
            throw new \InvalidArgumentException('The resource file count is outside the configured limit.');
        }
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
