<?php
declare(strict_types=1);

namespace EduShare\Shared\Persistence;

final class MigrationPlanItem
{
    public function __construct(
        public readonly string $version,
        public readonly string $name,
        public readonly string $path,
        public readonly string $checksum,
        public readonly bool $applied
    ) {
    }
}
