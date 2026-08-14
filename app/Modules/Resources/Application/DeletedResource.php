<?php
declare(strict_types=1);

namespace EduShare\Modules\Resources\Application;

final class DeletedResource
{
    public function __construct(
        public readonly int $resourceId,
        public readonly string $cleanupStatus
    ) {
    }
}
