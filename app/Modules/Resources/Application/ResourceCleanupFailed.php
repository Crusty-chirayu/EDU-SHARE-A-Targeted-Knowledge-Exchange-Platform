<?php
declare(strict_types=1);

namespace EduShare\Modules\Resources\Application;

/**
 * Persistence failed and at least one compensating action could not be confirmed.
 *
 * Storage identities are deliberately omitted so this exception is safe to report by
 * type and counters without exposing opaque object keys.
 */
final class ResourceCleanupFailed extends \RuntimeException
{
    public function __construct(
        public readonly int $failedObjectCount,
        public readonly bool $rollbackFailed,
        \Throwable $previous
    ) {
        parent::__construct(
            'Resource persistence failed and compensating cleanup requires administrator attention.',
            0,
            $previous
        );
    }
}
