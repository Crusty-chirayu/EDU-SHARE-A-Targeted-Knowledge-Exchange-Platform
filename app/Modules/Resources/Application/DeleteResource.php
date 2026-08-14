<?php
declare(strict_types=1);

namespace EduShare\Modules\Resources\Application;

use EduShare\Modules\Resources\Domain\ResourceAccessPolicy;

final class DeleteResource
{
    public function __construct(
        private readonly ResourceRepository $repository,
        private readonly ResourceStorage $storage,
        private readonly ResourceAccessPolicy $accessPolicy
    ) {
    }

    /** @param array<string, mixed> $actor */
    public function execute(int $resourceId, array $actor): DeletedResource
    {
        /** @var list<array{id: int, storage_key: string, token: string}> $cleanup */
        $cleanup = [];
        /** @var list<array{id: int, storage_key: string, token: string}> $movedNow */
        $movedNow = [];

        $this->repository->begin();
        try {
            $resource = $this->repository->find($resourceId, true);
            if ($resource === null) {
                throw new ResourceNotFound('Resource not found.');
            }
            $deletionStatus = (string) ($resource['deletion_status'] ?? 'deleted');
            if ($deletionStatus === 'active') {
                if (!$this->accessPolicy->canDelete($resource, $actor)) {
                    throw new ResourceAccessDenied('You cannot delete this resource.');
                }
            } elseif ($deletionStatus === 'pending_cleanup') {
                if (!$this->accessPolicy->canRecoverDeletion($resource, $actor)) {
                    throw new ResourceAccessDenied('You cannot recover deletion cleanup for this resource.');
                }
            } else {
                throw new ResourceNotFound('Resource not found.');
            }

            foreach ($this->repository->lockStoredFiles($resourceId) as $file) {
                $storageStatus = $file['storage_status'];
                if (in_array($storageStatus, ['deleted', 'missing'], true)) {
                    continue;
                }

                $token = $file['quarantine_token'];
                if ($storageStatus === 'available' || $token === null) {
                    // The storage adapter is idempotent: when a prior process died after
                    // rename, the key deterministically resolves the existing quarantine.
                    $token = $this->storage->quarantine($file['storage_key']);
                    if ($token === null) {
                        $this->repository->setOneFileStorageStatus($file['id'], 'missing');
                        continue;
                    }
                    $this->repository->setFileQuarantine($file['id'], $token);
                    if ($storageStatus === 'available') {
                        $movedNow[] = [
                            'id' => $file['id'],
                            'storage_key' => $file['storage_key'],
                            'token' => $token,
                        ];
                    }
                }

                $cleanup[] = [
                    'id' => $file['id'],
                    'storage_key' => $file['storage_key'],
                    'token' => $token,
                ];
            }

            if ($deletionStatus === 'active') {
                $this->repository->markResourceDeleted($resourceId, 'pending_cleanup');
            }
            $this->repository->commit();
        } catch (\Throwable $exception) {
            $this->repository->rollback();
            foreach (array_reverse($movedNow) as $file) {
                if (!$this->storage->restore($file['token'], $file['storage_key'])) {
                    try {
                        // This write occurs after rollback so a failed restore leaves a
                        // durable token that a later deletion attempt can recover.
                        $this->repository->setFileQuarantine(
                            $file['id'],
                            $file['token'],
                            'cleanup_pending'
                        );
                    } catch (\Throwable) {
                        // Keep the original failure. The deterministic token remains
                        // derivable from the persisted opaque storage key.
                    }
                }
            }
            throw $exception;
        }

        $cleanupPending = false;
        foreach ($cleanup as $file) {
            if ($this->storage->purge($file['token'])) {
                // If this status write fails, the committed token and pending resource
                // make the already-purged operation idempotently retryable.
                $this->repository->setOneFileStorageStatus($file['id'], 'deleted');
            } else {
                $cleanupPending = true;
                $this->repository->setFileQuarantine(
                    $file['id'],
                    $file['token'],
                    'cleanup_pending'
                );
            }
        }

        $status = $cleanupPending ? 'pending_cleanup' : 'deleted';
        $this->repository->setResourceDeletionStatus($resourceId, $status);
        return new DeletedResource($resourceId, $status);
    }
}
