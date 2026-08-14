<?php
declare(strict_types=1);

namespace EduShare\Modules\Resources\Application;

final class ResourceMetadataUpdate
{
    public function __construct(
        public readonly string $title,
        public readonly ?string $description,
        public readonly string $visibility
    ) {
        if ($title === '' || mb_strlen($title) > 200 || preg_match('/[\x00-\x1F\x7F]/', $title)) {
            throw new \InvalidArgumentException('Resource title must contain 1 to 200 valid characters.');
        }
        if ($description !== null
            && (mb_strlen($description) > 5000
                || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $description))) {
            throw new \InvalidArgumentException('Resource description is invalid or too long.');
        }
        if (!in_array($visibility, ['public', 'authenticated', 'private'], true)) {
            throw new \InvalidArgumentException('Resource visibility is invalid.');
        }
    }
}
