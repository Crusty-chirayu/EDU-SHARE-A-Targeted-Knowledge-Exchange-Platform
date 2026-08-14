<?php
declare(strict_types=1);

namespace EduShare\Modules\Resources\Domain;

final class ResourceAccessPolicy
{
    /** @param array<string, mixed>|null $actor */
    public function canView(array $resource, ?array $actor): bool
    {
        if (($resource['deletion_status'] ?? 'deleted') !== 'active') {
            return false;
        }
        $actorId = $actor === null ? null : (int) ($actor['id'] ?? 0);
        if ($actorId !== null && $actorId > 0 && $actorId === (int) ($resource['owner_id'] ?? 0)) {
            return true;
        }
        if ($this->canModerate($actor)) {
            return true;
        }
        if (($resource['publication_status'] ?? 'draft') !== 'published'
            || ($resource['moderation_status'] ?? 'pending') !== 'approved') {
            return false;
        }

        return match ($resource['visibility'] ?? 'private') {
            'public' => true,
            'authenticated' => $actor !== null,
            default => false,
        };
    }

    /** @param array<string, mixed> $actor */
    public function canAddVersion(array $resource, array $actor): bool
    {
        return ($resource['deletion_status'] ?? 'deleted') === 'active'
            && in_array($actor['user_type'] ?? null, ['teacher', 'moderator', 'admin'], true)
            && (int) ($resource['owner_id'] ?? 0) === (int) ($actor['id'] ?? 0);
    }

    /** @param array<string, mixed> $actor */
    public function canUpdate(array $resource, array $actor): bool
    {
        return ($resource['deletion_status'] ?? 'deleted') === 'active'
            && (int) ($resource['owner_id'] ?? 0) === (int) ($actor['id'] ?? 0);
    }

    /** @param array<string, mixed> $actor */
    public function canDelete(array $resource, array $actor): bool
    {
        return ($resource['deletion_status'] ?? 'deleted') === 'active'
            && $this->ownsOrModerates($resource, $actor);
    }

    /** @param array<string, mixed> $actor */
    public function canRecoverDeletion(array $resource, array $actor): bool
    {
        return ($resource['deletion_status'] ?? 'deleted') === 'pending_cleanup'
            && $this->ownsOrModerates($resource, $actor);
    }

    /** @param array<string, mixed> $actor */
    private function ownsOrModerates(array $resource, array $actor): bool
    {
        return (int) ($resource['owner_id'] ?? 0) === (int) ($actor['id'] ?? 0)
            || $this->canModerate($actor);
    }

    /** @param array<string, mixed>|null $actor */
    private function canModerate(?array $actor): bool
    {
        return $actor !== null
            && in_array($actor['user_type'] ?? null, ['moderator', 'admin'], true);
    }
}
