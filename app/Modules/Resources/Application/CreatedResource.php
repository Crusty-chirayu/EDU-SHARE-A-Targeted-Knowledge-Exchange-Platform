<?php
declare(strict_types=1);

namespace EduShare\Modules\Resources\Application;

final class CreatedResource
{
    /** @param list<int> $fileIds */
    public function __construct(
        public readonly int $resourceId,
        public readonly int $versionId,
        public readonly int $versionNumber,
        public readonly array $fileIds
    ) {
    }
}
