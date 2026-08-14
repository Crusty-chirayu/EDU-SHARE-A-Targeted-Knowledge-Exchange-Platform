<?php
declare(strict_types=1);

namespace EduShare\Modules\Resources\Application;

use EduShare\Modules\Resources\Domain\ResourceAccessPolicy;

final class UpdateResource
{
    public function __construct(
        private readonly ResourceRepository $repository,
        private readonly ResourceAccessPolicy $accessPolicy
    ) {
    }

    /** @param array<string, mixed> $actor */
    public function execute(int $resourceId, array $actor, ResourceMetadataUpdate $changes): void
    {
        if ($resourceId < 1) {
            throw new \InvalidArgumentException('A valid resource ID is required.');
        }

        $this->repository->begin();
        try {
            $resource = $this->repository->find($resourceId, true);
            if ($resource === null || ($resource['deletion_status'] ?? 'deleted') !== 'active') {
                throw new ResourceNotFound('Resource not found.');
            }
            if (!$this->accessPolicy->canUpdate($resource, $actor)) {
                throw new ResourceAccessDenied('Only the active resource owner can update metadata.');
            }
            $this->repository->updateMetadata($resourceId, $changes);
            $this->repository->commit();
        } catch (\Throwable $exception) {
            $this->repository->rollback();
            throw $exception;
        }
    }
}
