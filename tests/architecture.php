<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require dirname(__DIR__) . '/app/autoload.php';

use EduShare\Modules\IdentityAccess\Application\AuthorizationGate;
use EduShare\Modules\IdentityAccess\Application\CurrentUserProvider;
use EduShare\Modules\IdentityAccess\Domain\CurrentUser;
use EduShare\Modules\IdentityAccess\Http\RequireAbility;
use EduShare\Modules\IdentityAccess\Http\RequireAuthenticated;
use EduShare\Modules\Profiles\Application\ContributorDirectoryRepository;
use EduShare\Modules\Profiles\Application\ListUniversityContributors;
use EduShare\Modules\Profiles\Application\UniversityNotFound;
use EduShare\Modules\Profiles\Http\ContributorDirectoryInput;
use EduShare\Modules\Profiles\Http\ListUniversityContributorsController;
use EduShare\Modules\Resources\Application\AddResourceVersion;
use EduShare\Modules\Resources\Application\CreateResource;
use EduShare\Modules\Resources\Application\DeleteResource;
use EduShare\Modules\Resources\Application\DuplicateResourceFile;
use EduShare\Modules\Resources\Application\ResourceAccessDenied;
use EduShare\Modules\Resources\Application\ResourceCleanupFailed;
use EduShare\Modules\Resources\Application\ResourceMetadata;
use EduShare\Modules\Resources\Application\ResourceMetadataUpdate;
use EduShare\Modules\Resources\Application\ResourceRepository;
use EduShare\Modules\Resources\Application\ResourceStorage;
use EduShare\Modules\Resources\Application\UpdateResource;
use EduShare\Modules\Resources\Application\VerifiedResourceFile;
use EduShare\Modules\Resources\Domain\ResourceAccessPolicy;
use EduShare\Shared\Http\CallableRequestHandler;
use EduShare\Shared\Http\HttpException;
use EduShare\Shared\Http\Request;
use EduShare\Shared\Http\Response;
use EduShare\Shared\Kernel\Kernel;
use EduShare\Shared\Routing\Router;

$passed = 0;
$failed = 0;
function architecture_check(bool $condition, string $message): void
{
    global $passed, $failed;
    if ($condition) {
        $passed++;
        fwrite(STDOUT, "PASS {$message}\n");
    } else {
        $failed++;
        fwrite(STDERR, "FAIL {$message}\n");
    }
}

final class ArchitectureResourceRepository implements ResourceRepository
{
    /** @var array<string, mixed>|null */
    public ?array $resource = null;
    /** @var list<array<string, mixed>> */
    public array $versions = [];
    /** @var list<array{id: int, storage_key: string, storage_status: string, quarantine_token: ?string}> */
    public array $files = [];
    public int $failFileInsertAt = 0;
    /** @var array<string, int> Synthetic owner/checksum matches keyed by SHA-256. */
    public array $checksumResourceIds = [];
    public int $currentVersionAdvances = 0;
    public int $ownerLocks = 0;
    private int $fileInsertCalls = 0;
    private int $nextResourceId = 40;
    private int $nextVersionId = 80;
    private int $nextFileId = 120;
    /** @var array<string, mixed>|null */
    private ?array $snapshot = null;

    public function begin(): void
    {
        $this->snapshot = [
            'resource' => $this->resource,
            'versions' => $this->versions,
            'files' => $this->files,
            'fileInsertCalls' => $this->fileInsertCalls,
            'currentVersionAdvances' => $this->currentVersionAdvances,
            'nextResourceId' => $this->nextResourceId,
            'nextVersionId' => $this->nextVersionId,
            'nextFileId' => $this->nextFileId,
        ];
    }

    public function lockOwner(int $ownerId): void
    {
        if ($ownerId < 1) {
            throw new RuntimeException('Synthetic resource owner is invalid.');
        }
        $this->ownerLocks++;
    }

    public function commit(): void
    {
        $this->snapshot = null;
    }

    public function rollback(): void
    {
        if ($this->snapshot === null) {
            return;
        }
        $this->resource = $this->snapshot['resource'];
        $this->versions = $this->snapshot['versions'];
        $this->files = $this->snapshot['files'];
        $this->fileInsertCalls = $this->snapshot['fileInsertCalls'];
        $this->currentVersionAdvances = $this->snapshot['currentVersionAdvances'];
        $this->nextResourceId = $this->snapshot['nextResourceId'];
        $this->nextVersionId = $this->snapshot['nextVersionId'];
        $this->nextFileId = $this->snapshot['nextFileId'];
        $this->snapshot = null;
    }

    public function find(int $resourceId, bool $forUpdate = false): ?array
    {
        return $this->resource !== null && $this->resource['id'] === $resourceId
            ? $this->resource
            : null;
    }

    public function findFileForDownload(int $resourceId, ?int $fileId): ?array
    {
        return null;
    }

    public function versionsWithFiles(int $resourceId): array
    {
        return $this->versions;
    }

    public function resourceIdForOwnerChecksum(
        int $ownerId,
        string $checksum,
        ?int $excludingResourceId = null
    ): ?int {
        $resourceId = $this->checksumResourceIds[$checksum] ?? null;
        return $resourceId === $excludingResourceId ? null : $resourceId;
    }

    public function insertResource(int $ownerId, ResourceMetadata $metadata, string $documentType): int
    {
        $id = $this->nextResourceId++;
        $this->resource = [
            'id' => $id,
            'owner_id' => $ownerId,
            'title' => $metadata->title,
            'description' => $metadata->description,
            'visibility' => $metadata->visibility,
            'document_type' => $documentType,
            'publication_status' => 'published',
            'moderation_status' => 'approved',
            'deletion_status' => 'active',
            'current_version_number' => 1,
        ];
        return $id;
    }

    public function insertVersion(
        int $resourceId,
        int $versionNumber,
        int $creatorId,
        ?string $changeDescription
    ): int {
        $id = $this->nextVersionId++;
        $this->versions[] = [
            'id' => $id,
            'resource_id' => $resourceId,
            'version_number' => $versionNumber,
            'created_by' => $creatorId,
            'change_description' => $changeDescription,
            'lifecycle_status' => 'current',
        ];
        return $id;
    }

    public function insertFile(int $versionId, VerifiedResourceFile $file, string $storageKey): int
    {
        $this->fileInsertCalls++;
        if ($this->fileInsertCalls === $this->failFileInsertAt) {
            throw new RuntimeException('synthetic persistence failure');
        }
        $id = $this->nextFileId++;
        $this->files[] = [
            'id' => $id,
            'storage_key' => $storageKey,
            'storage_status' => 'available',
            'quarantine_token' => null,
        ];
        return $id;
    }

    public function advanceCurrentVersion(int $resourceId, int $versionNumber, string $documentType): void
    {
        if ((int) $this->resource['current_version_number'] >= $versionNumber) {
            throw new RuntimeException('Synthetic current version did not advance.');
        }
        $this->currentVersionAdvances++;
        foreach ($this->versions as &$version) {
            $version['lifecycle_status'] = $version['version_number'] === $versionNumber
                ? 'current'
                : 'superseded';
        }
        unset($version);
        $this->resource['current_version_number'] = $versionNumber;
        $this->resource['document_type'] = $documentType;
    }

    public function updateMetadata(int $resourceId, ResourceMetadataUpdate $changes): void
    {
        $this->resource['title'] = $changes->title;
        $this->resource['description'] = $changes->description;
        $this->resource['visibility'] = $changes->visibility;
    }

    public function lockStoredFiles(int $resourceId): array
    {
        return $this->files;
    }

    public function setFileQuarantine(
        int $fileId,
        string $quarantineToken,
        string $status = 'quarantined'
    ): void {
        foreach ($this->files as &$file) {
            if ($file['id'] === $fileId) {
                $file['storage_status'] = $status;
                $file['quarantine_token'] = $quarantineToken;
            }
        }
        unset($file);
    }

    public function setOneFileStorageStatus(int $fileId, string $status): void
    {
        foreach ($this->files as &$file) {
            if ($file['id'] === $fileId) {
                $file['storage_status'] = $status;
                $file['quarantine_token'] = null;
            }
        }
        unset($file);
    }

    public function markResourceDeleted(int $resourceId, string $status): void
    {
        $this->resource['deletion_status'] = $status;
        $this->resource['publication_status'] = 'archived';
    }

    public function setResourceDeletionStatus(int $resourceId, string $status): void
    {
        $this->resource['deletion_status'] = $status;
    }
}

final class ArchitectureResourceStorage implements ResourceStorage
{
    /** @var array<string, string> */
    public array $objects = [];
    /** @var array<string, string> */
    public array $quarantines = [];
    public bool $failPurge = false;
    public bool $failRemove = false;
    private int $nextKey = 1;

    public function newKey(string $extension): string
    {
        return str_pad(dechex($this->nextKey++), 32, '0', STR_PAD_LEFT) . '.' . $extension;
    }

    public function putVerifiedUpload(string $temporaryPath, string $storageKey): void
    {
        $contents = file_get_contents($temporaryPath);
        if ($contents === false) {
            throw new RuntimeException('synthetic storage read failure');
        }
        $this->objects[$storageKey] = $contents;
    }

    public function openReadStream(string $storageKey)
    {
        if (!isset($this->objects[$storageKey])) {
            return null;
        }
        $stream = fopen('php://temp', 'w+b');
        if ($stream === false) {
            throw new RuntimeException('synthetic stream failure');
        }
        fwrite($stream, $this->objects[$storageKey]);
        rewind($stream);
        return $stream;
    }

    public function remove(string $storageKey): bool
    {
        if ($this->failRemove) {
            return false;
        }
        unset($this->objects[$storageKey]);
        return true;
    }

    public function quarantine(string $storageKey): ?string
    {
        $token = hash('sha256', "edu-share-resource-quarantine-v1\0" . $storageKey) . '.quarantine';
        if (isset($this->objects[$storageKey])) {
            $this->quarantines[$token] = $this->objects[$storageKey];
            unset($this->objects[$storageKey]);
            return $token;
        }
        return isset($this->quarantines[$token]) ? $token : null;
    }

    public function restore(string $quarantineToken, string $storageKey): bool
    {
        if (!isset($this->quarantines[$quarantineToken])) {
            return false;
        }
        $this->objects[$storageKey] = $this->quarantines[$quarantineToken];
        unset($this->quarantines[$quarantineToken]);
        return true;
    }

    public function purge(string $quarantineToken): bool
    {
        if ($this->failPurge) {
            return false;
        }
        unset($this->quarantines[$quarantineToken]);
        return true;
    }
}

$repository = new class implements ContributorDirectoryRepository {
    public function universityExists(int $universityId): bool
    {
        return $universityId === 1;
    }

    public function contributorsAtUniversity(int $universityId): array
    {
        return [[
            'id' => 8,
            'full_name' => 'Synthetic Teacher',
            'user_type' => 'teacher',
            'department_name' => 'Computer Science',
        ]];
    }
};
$service = new ListUniversityContributors($repository);
$contributors = $service->handle(1);
architecture_check(count($contributors) === 1 && $contributors[0]['id'] === 8, 'contributor service returns its canonical read model');
try {
    $service->handle(2);
    architecture_check(false, 'contributor service reports an unknown university');
} catch (UniversityNotFound) {
    architecture_check(true, 'contributor service reports an unknown university');
}

foreach (['0', '-1', '1e2', '../1', '', null, ['1']] as $invalid) {
    try {
        ContributorDirectoryInput::universityId($invalid);
        architecture_check(false, 'route validation rejects malformed university IDs');
    } catch (HttpException $exception) {
        architecture_check($exception->status === 422, 'route validation rejects malformed university IDs');
    }
}
architecture_check(ContributorDirectoryInput::universityId('1') === 1, 'route validation accepts a canonical positive ID');

$anonymous = new class implements CurrentUserProvider {
    public function current(): ?CurrentUser
    {
        return null;
    }
};
$student = new class implements CurrentUserProvider {
    public function current(): ?CurrentUser
    {
        return new CurrentUser(3, 'student', 'Synthetic Student');
    }
};
$controller = new ListUniversityContributorsController($service);
$router = new Router();
$router->get('/api/v1/universities/{universityId}/contributors', $controller, [new RequireAuthenticated($anonymous)]);
$kernel = new Kernel($router);
$response = $kernel->handle(new Request('GET', '/api/v1/universities/1/contributors'));
architecture_check($response->status === 401, 'authentication middleware rejects an anonymous route request');

$router = new Router();
$router->get('/api/v1/universities/{universityId}/contributors', $controller, [new RequireAuthenticated($student)]);
$kernel = new Kernel($router);
$response = $kernel->handle(new Request('GET', '/api/v1/universities/1/contributors'));
$payload = json_decode($response->body, true, 512, JSON_THROW_ON_ERROR);
architecture_check($response->status === 200 && $payload['data'][0]['full_name'] === 'Synthetic Teacher', 'route-controller-service response succeeds for an authenticated user');
$response = $kernel->handle(new Request('POST', '/api/v1/universities/1/contributors'));
architecture_check($response->status === 405 && ($response->headers['Allow'] ?? '') === 'GET', 'router returns method-not-allowed with an Allow header');
$response = $kernel->handle(new Request('GET', '/api/v1/unknown'));
architecture_check($response->status === 404, 'router returns a safe not-found response');
$response = $kernel->handle(new Request('GET', '/api/v1/universities/not-a-number/contributors'));
architecture_check($response->status === 422, 'controller validation failures cross the kernel safely');

$denyGate = new class implements AuthorizationGate {
    public function allows(CurrentUser $user, string $ability): bool
    {
        return false;
    }
};
$destination = new CallableRequestHandler(static fn (Request $request): Response => Response::json(['ok' => true]));
try {
    (new RequireAbility($student, $denyGate, 'manage_academics'))->process(new Request('GET', '/'), $destination);
    architecture_check(false, 'authorization middleware fails closed');
} catch (HttpException $exception) {
    architecture_check($exception->status === 403, 'authorization middleware fails closed');
}
$allowGate = new class implements AuthorizationGate {
    public function allows(CurrentUser $user, string $ability): bool
    {
        return $ability === 'browse_materials';
    }
};
$response = (new RequireAbility($student, $allowGate, 'browse_materials'))
    ->process(new Request('GET', '/'), $destination);
architecture_check($response->status === 200, 'authorization middleware allows an explicit capability');

$resourcePolicy = new ResourceAccessPolicy();
$publicResource = [
    'id' => 40,
    'owner_id' => 7,
    'visibility' => 'public',
    'publication_status' => 'published',
    'moderation_status' => 'approved',
    'deletion_status' => 'active',
];
$owner = ['id' => 7, 'user_type' => 'teacher'];
$studentOwner = ['id' => 7, 'user_type' => 'student'];
$otherUser = ['id' => 9, 'user_type' => 'student'];
architecture_check($resourcePolicy->canView($publicResource, null), 'resource policy permits approved public retrieval');
$privateResource = array_merge($publicResource, ['visibility' => 'private']);
architecture_check(
    $resourcePolicy->canView($privateResource, $owner)
        && !$resourcePolicy->canView($privateResource, $otherUser),
    'resource policy keeps private retrieval owner-bound'
);
architecture_check(
    $resourcePolicy->canAddVersion($publicResource, $owner)
        && !$resourcePolicy->canAddVersion($publicResource, $studentOwner),
    'resource version policy requires both contributor ability and active ownership'
);
$pendingResource = array_merge($publicResource, ['deletion_status' => 'pending_cleanup']);
architecture_check(
    !$resourcePolicy->canView($pendingResource, $owner)
        && !$resourcePolicy->canDelete($pendingResource, $owner)
        && $resourcePolicy->canRecoverDeletion($pendingResource, $owner),
    'resource policy denies deleted content while authorizing owner cleanup recovery'
);

$firstPath = tempnam(sys_get_temp_dir(), 'edu-share-architecture-a-');
$secondPath = tempnam(sys_get_temp_dir(), 'edu-share-architecture-b-');
$thirdPath = tempnam(sys_get_temp_dir(), 'edu-share-architecture-c-');
if ($firstPath === false || $secondPath === false || $thirdPath === false) {
    throw new RuntimeException('Unable to create architecture-test fixtures.');
}
file_put_contents($firstPath, 'architecture resource file A');
file_put_contents($secondPath, 'architecture resource file B');
file_put_contents($thirdPath, 'architecture resource file C');
$firstFile = new VerifiedResourceFile(
    $firstPath,
    'first.txt',
    'txt',
    'text/plain',
    (int) filesize($firstPath),
    hash_file('sha256', $firstPath)
);
$secondFile = new VerifiedResourceFile(
    $secondPath,
    'second.txt',
    'txt',
    'text/plain',
    (int) filesize($secondPath),
    hash_file('sha256', $secondPath)
);
$thirdFile = new VerifiedResourceFile(
    $thirdPath,
    'third.txt',
    'txt',
    'text/plain',
    (int) filesize($thirdPath),
    hash_file('sha256', $thirdPath)
);
$metadata = new ResourceMetadata('Architecture notes', null, 1, 1, 1, 1, 1, 'private');

$compensationRepository = new ArchitectureResourceRepository();
$compensationRepository->failFileInsertAt = 2;
$compensationStorage = new ArchitectureResourceStorage();
try {
    (new CreateResource($compensationRepository, $compensationStorage, 5, 1024 * 1024))
        ->execute(7, $metadata, [$firstFile, $secondFile]);
    architecture_check(false, 'resource creation compensates stored objects after persistence failure');
} catch (RuntimeException) {
    architecture_check(
        $compensationRepository->resource === null && $compensationStorage->objects === [],
        'resource creation compensates stored objects after persistence failure'
    );
}

$failedCleanupRepository = new ArchitectureResourceRepository();
$failedCleanupRepository->failFileInsertAt = 2;
$failedCleanupStorage = new ArchitectureResourceStorage();
$failedCleanupStorage->failRemove = true;
try {
    (new CreateResource($failedCleanupRepository, $failedCleanupStorage, 5, 1024 * 1024))
        ->execute(7, $metadata, [$firstFile, $secondFile]);
    architecture_check(false, 'resource creation surfaces failed compensating object removal');
} catch (ResourceCleanupFailed $exception) {
    architecture_check(
        $failedCleanupRepository->resource === null
            && count($failedCleanupStorage->objects) === 2
            && $exception->failedObjectCount === 2
            && !$exception->rollbackFailed
            && $exception->getPrevious() instanceof RuntimeException,
        'resource creation surfaces failed compensating object removal'
    );
}

$versionCleanupRepository = new ArchitectureResourceRepository();
$versionCleanupStorage = new ArchitectureResourceStorage();
$versionCleanupCreated = (new CreateResource(
    $versionCleanupRepository,
    $versionCleanupStorage,
    5,
    1024 * 1024
))->execute(7, $metadata, [$firstFile]);
$versionCleanupRepository->failFileInsertAt = 2;
$versionCleanupStorage->failRemove = true;
try {
    (new AddResourceVersion(
        $versionCleanupRepository,
        $versionCleanupStorage,
        $resourcePolicy,
        5,
        1024 * 1024
    ))->execute($versionCleanupCreated->resourceId, $owner, [$secondFile], 'Failed edition');
    architecture_check(false, 'resource version creation surfaces failed compensating object removal');
} catch (ResourceCleanupFailed $exception) {
    architecture_check(
        count($versionCleanupRepository->versions) === 1
            && (int) $versionCleanupRepository->resource['current_version_number'] === 1
            && count($versionCleanupStorage->objects) === 2
            && $exception->failedObjectCount === 1
            && !$exception->rollbackFailed,
        'resource version creation surfaces failed compensating object removal'
    );
}

$resourceRepository = new ArchitectureResourceRepository();
$resourceStorage = new ArchitectureResourceStorage();
$createdResource = (new CreateResource($resourceRepository, $resourceStorage, 5, 1024 * 1024))
    ->execute(7, $metadata, [$firstFile, $secondFile]);
architecture_check(
    $createdResource->versionNumber === 1
        && count($createdResource->fileIds) === 2
        && $resourceRepository->resource['current_version_number'] === 1
        && $resourceRepository->currentVersionAdvances === 0
        && $resourceRepository->ownerLocks === 1,
    'resource service locks owner-scoped duplicate checks and creates one atomic initial version'
);
$addVersionService = new AddResourceVersion(
    $resourceRepository,
    $resourceStorage,
    $resourcePolicy,
    5,
    1024 * 1024
);
$resourceRepository->checksumResourceIds[$firstFile->checksumSha256] = $createdResource->resourceId;
$sameContentVersion = $addVersionService->execute(
    $createdResource->resourceId,
    $owner,
    [$firstFile],
    'Same content retained in this resource history'
);
architecture_check(
    $sameContentVersion->versionNumber === 2,
    'resource version checksum check permits content already owned by the same stable resource'
);
$resourceRepository->checksumResourceIds[$thirdFile->checksumSha256] = 999;
$versionCountBeforeDuplicate = count($resourceRepository->versions);
$objectCountBeforeDuplicate = count($resourceStorage->objects);
try {
    $addVersionService->execute(
        $createdResource->resourceId,
        $owner,
        [$thirdFile],
        'Content owned by another resource'
    );
    architecture_check(false, 'resource version checksum check rejects content owned by another resource');
} catch (DuplicateResourceFile) {
    architecture_check(
        count($resourceRepository->versions) === $versionCountBeforeDuplicate
            && count($resourceStorage->objects) === $objectCountBeforeDuplicate,
        'resource version checksum check rejects content owned by another resource'
    );
}
unset($resourceRepository->checksumResourceIds[$thirdFile->checksumSha256]);
$addedVersion = $addVersionService->execute(
    $createdResource->resourceId,
    $owner,
    [$thirdFile],
    'Complete replacement edition'
);
architecture_check(
    $addedVersion->resourceId === $createdResource->resourceId
        && $addedVersion->versionNumber === 3
        && count($resourceRepository->versions) === 3
        && $resourceRepository->currentVersionAdvances === 2
        && $resourceRepository->ownerLocks === 4,
    'resource version service serializes owner duplicate checks and preserves stable monotonic history'
);

$updateService = new UpdateResource($resourceRepository, $resourcePolicy);
$versionCountBeforeUpdate = count($resourceRepository->versions);
$updateService->execute(
    $createdResource->resourceId,
    $owner,
    new ResourceMetadataUpdate('Renamed architecture notes', 'Metadata only', 'authenticated')
);
architecture_check(
    $resourceRepository->resource['title'] === 'Renamed architecture notes'
        && $resourceRepository->resource['visibility'] === 'authenticated'
        && count($resourceRepository->versions) === $versionCountBeforeUpdate,
    'resource metadata update leaves identity and immutable version history unchanged'
);
try {
    $updateService->execute(
        $createdResource->resourceId,
        $otherUser,
        new ResourceMetadataUpdate('Unauthorized', null, 'public')
    );
    architecture_check(false, 'resource metadata update denies a non-owner');
} catch (ResourceAccessDenied) {
    architecture_check(
        $resourceRepository->resource['title'] === 'Renamed architecture notes',
        'resource metadata update denies a non-owner'
    );
}

$deleteService = new DeleteResource($resourceRepository, $resourceStorage, $resourcePolicy);
$resourceStorage->failPurge = true;
$pendingDeletion = $deleteService->execute($createdResource->resourceId, $owner);
$tokensPersisted = array_reduce(
    $resourceRepository->files,
    static fn (bool $persisted, array $file): bool => $persisted
        && $file['storage_status'] === 'cleanup_pending'
        && is_string($file['quarantine_token']),
    true
);
architecture_check(
    $pendingDeletion->cleanupStatus === 'pending_cleanup' && $tokensPersisted,
    'resource deletion persists quarantine recovery state when purge is deferred'
);
$resourceStorage->failPurge = false;
$completedDeletion = $deleteService->execute($createdResource->resourceId, $owner);
$allFilesDeleted = array_reduce(
    $resourceRepository->files,
    static fn (bool $deleted, array $file): bool => $deleted
        && $file['storage_status'] === 'deleted'
        && $file['quarantine_token'] === null,
    true
);
architecture_check(
    $completedDeletion->cleanupStatus === 'deleted'
        && $allFilesDeleted
        && $resourceStorage->quarantines === [],
    'resource deletion resumes persisted cleanup idempotently after interruption'
);

@unlink($firstPath);
@unlink($secondPath);
@unlink($thirdPath);

$exploding = new CallableRequestHandler(static function (Request $request): Response {
    throw new RuntimeException('database password should never be disclosed');
});
$response = (new Kernel($exploding))->handle(new Request('GET', '/'));
architecture_check($response->status === 500 && !str_contains($response->body, 'password'), 'kernel conceals unexpected exception details');

fwrite(STDOUT, "Architecture checks: {$passed} passed, {$failed} failed.\n");
exit($failed === 0 ? 0 : 1);
