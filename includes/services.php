<?php
declare(strict_types=1);

function resource_repository(): EduShare\Modules\Resources\Infrastructure\MysqliResourceRepository
{
    static $repository = null;
    if (!$repository instanceof EduShare\Modules\Resources\Infrastructure\MysqliResourceRepository) {
        $repository = new EduShare\Modules\Resources\Infrastructure\MysqliResourceRepository(db());
    }
    return $repository;
}

function resource_storage(): EduShare\Modules\Resources\Infrastructure\PrivateResourceStorage
{
    static $storage = null;
    if (!$storage instanceof EduShare\Modules\Resources\Infrastructure\PrivateResourceStorage) {
        $storage = new EduShare\Modules\Resources\Infrastructure\PrivateResourceStorage();
    }
    return $storage;
}

function resource_access_policy(): EduShare\Modules\Resources\Domain\ResourceAccessPolicy
{
    static $policy = null;
    if (!$policy instanceof EduShare\Modules\Resources\Domain\ResourceAccessPolicy) {
        $policy = new EduShare\Modules\Resources\Domain\ResourceAccessPolicy();
    }
    return $policy;
}

function create_resource_service(): EduShare\Modules\Resources\Application\CreateResource
{
    $maxFiles = (int) app_config('upload.max_files');
    return new EduShare\Modules\Resources\Application\CreateResource(
        resource_repository(),
        resource_storage(),
        $maxFiles,
        (int) app_config('upload.max_bytes') * $maxFiles
    );
}

function add_resource_version_service(): EduShare\Modules\Resources\Application\AddResourceVersion
{
    $maxFiles = (int) app_config('upload.max_files');
    return new EduShare\Modules\Resources\Application\AddResourceVersion(
        resource_repository(),
        resource_storage(),
        resource_access_policy(),
        $maxFiles,
        (int) app_config('upload.max_bytes') * $maxFiles
    );
}

function update_resource_service(): EduShare\Modules\Resources\Application\UpdateResource
{
    return new EduShare\Modules\Resources\Application\UpdateResource(
        resource_repository(),
        resource_access_policy()
    );
}

function delete_resource_service(): EduShare\Modules\Resources\Application\DeleteResource
{
    return new EduShare\Modules\Resources\Application\DeleteResource(
        resource_repository(),
        resource_storage(),
        resource_access_policy()
    );
}

function find_resource(int $resourceId): ?array
{
    return resource_repository()->find($resourceId);
}

function find_resource_file(int $resourceId, ?int $fileId = null): ?array
{
    return resource_repository()->findFileForDownload($resourceId, $fileId);
}

function resource_versions_with_files(int $resourceId): array
{
    return resource_repository()->versionsWithFiles($resourceId);
}

/** @deprecated P1.2 callers should use find_resource(). */
function find_material(int $resourceId): ?array
{
    $resource = find_resource($resourceId);
    if ($resource !== null) {
        $resource['user_id'] = $resource['owner_id'];
        $resource['status'] = $resource['publication_status'] === 'published'
            && $resource['moderation_status'] === 'approved' ? 'published' : 'pending';
    }
    return $resource;
}

function register_user(array $values): int
{
    if (!in_array($values['role'] ?? null, ['student', 'teacher'], true)) {
        abort_request(422, 'Public registration can only create student or teacher/contributor accounts.');
    }
    if (!validate_department_relationship((int) $values['university_id'], (int) $values['department_id'])) {
        abort_request(422, 'The selected department does not belong to the selected university.');
    }

    $passwordHash = password_hash((string) $values['password'], PASSWORD_DEFAULT);
    if ($passwordHash === false) {
        throw new RuntimeException('Password hashing failed.');
    }

    $branch = $values['branch'] ?: null;
    $year = $values['year'] ?: null;
    $contact = $values['contact'] ?: null;
    $address = $values['address'] ?: null;
    $statement = db()->prepare(
        'INSERT INTO users
         (full_name, gmail, password, user_type, university_id, department_id, branch, year, contact, address)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    $statement->bind_param(
        'ssssiisiss',
        $values['name'],
        $values['email'],
        $passwordHash,
        $values['role'],
        $values['university_id'],
        $values['department_id'],
        $branch,
        $year,
        $contact,
        $address
    );
    $statement->execute();
    return (int) db()->insert_id;
}

function login_rate_key(string $email): string
{
    return hash('sha256', strtolower(trim($email)) . '|' . client_identifier_hash());
}

function login_is_rate_limited(string $email): bool
{
    $key = login_rate_key($email);
    $statement = db()->prepare(
        'SELECT COUNT(*) AS failures FROM login_attempts
          WHERE rate_key = ? AND succeeded = 0 AND attempted_at >= (UTC_TIMESTAMP() - INTERVAL 15 MINUTE)'
    );
    $statement->bind_param('s', $key);
    $statement->execute();
    $failures = (int) ($statement->get_result()->fetch_assoc()['failures'] ?? 0);
    return $failures >= 5;
}

function record_login_attempt(string $email, bool $succeeded): void
{
    $key = login_rate_key($email);
    $success = $succeeded ? 1 : 0;
    $statement = db()->prepare('INSERT INTO login_attempts (rate_key, succeeded) VALUES (?, ?)');
    $statement->bind_param('si', $key, $success);
    $statement->execute();

    if ($succeeded) {
        $cleanup = db()->prepare('DELETE FROM login_attempts WHERE rate_key = ?');
        $cleanup->bind_param('s', $key);
        $cleanup->execute();
    } elseif (random_int(1, 100) === 1) {
        db()->query('DELETE FROM login_attempts WHERE attempted_at < (UTC_TIMESTAMP() - INTERVAL 1 DAY)');
    }
}

function authenticate_credentials(string $email, string $password): ?array
{
    $statement = db()->prepare('SELECT id, full_name, gmail, user_type, password FROM users WHERE gmail = ? LIMIT 1');
    $statement->bind_param('s', $email);
    $statement->execute();
    $user = $statement->get_result()->fetch_assoc() ?: null;

    $dummyHash = '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2uheWG/igi.';
    $verified = password_verify($password, $user['password'] ?? $dummyHash);
    if (!$verified || $user === null || !in_array($user['user_type'], APP_ROLES, true)) {
        return null;
    }

    unset($user['password']);
    return $user;
}

function toggle_resource_favorite(int $userId, int $resourceId): string
{
    $user = auth_user();
    if ($user === null || (int) $user['id'] !== $userId) {
        abort_request(403, 'You cannot change this favorite.');
    }

    $connection = db();
    $connection->begin_transaction();
    try {
        // Serialize against visibility/deletion changes so a new favorite cannot be
        // attached using a stale pre-transaction view of the resource.
        $resource = resource_repository()->find($resourceId, true);
        if ($resource === null) {
            abort_request(404, 'Resource not found.');
        }

        $check = $connection->prepare(
            'SELECT id FROM resource_favorites WHERE user_id = ? AND resource_id = ? FOR UPDATE'
        );
        $check->bind_param('ii', $userId, $resourceId);
        $check->execute();
        $favorite = $check->get_result()->fetch_assoc();

        if ($favorite !== null) {
            // A user may always remove their own retained favorite, even after the
            // resource becomes hidden or enters logical deletion.
            $delete = $connection->prepare('DELETE FROM resource_favorites WHERE user_id = ? AND resource_id = ?');
            $delete->bind_param('ii', $userId, $resourceId);
            $delete->execute();
            $action = 'removed';
        } else {
            if (($resource['deletion_status'] ?? 'deleted') !== 'active') {
                abort_request(404, 'Resource not found.');
            }
            if (!can_view_resource($resource, $user)) {
                abort_request(403, 'You cannot favorite this resource.');
            }
            $insert = $connection->prepare('INSERT INTO resource_favorites (user_id, resource_id) VALUES (?, ?)');
            $insert->bind_param('ii', $userId, $resourceId);
            $insert->execute();
            $action = 'added';
        }
        $connection->commit();
        return $action;
    } catch (Throwable $exception) {
        $connection->rollback();
        throw $exception;
    }
}

/** @deprecated The accepted identifier is now a stable resource ID. */
function toggle_material_favorite(int $userId, int $resourceId): string
{
    return toggle_resource_favorite($userId, $resourceId);
}

function toggle_university_favorite(int $userId, int $universityId): string
{
    $user = auth_user();
    if ($user === null || (int) $user['id'] !== $userId) {
        abort_request(403, 'You cannot change favorites for this account.');
    }
    if (!db_row_exists('universities', $universityId)) {
        abort_request(404, 'University not found.');
    }

    $connection = db();
    $connection->begin_transaction();
    try {
        $check = $connection->prepare(
            'SELECT id FROM university_favorites WHERE user_id = ? AND university_id = ? FOR UPDATE'
        );
        $check->bind_param('ii', $userId, $universityId);
        $check->execute();
        $favorite = $check->get_result()->fetch_assoc();

        if ($favorite !== null) {
            $delete = $connection->prepare('DELETE FROM university_favorites WHERE user_id = ? AND university_id = ?');
            $delete->bind_param('ii', $userId, $universityId);
            $delete->execute();
            $action = 'removed';
        } else {
            $insert = $connection->prepare('INSERT INTO university_favorites (user_id, university_id) VALUES (?, ?)');
            $insert->bind_param('ii', $userId, $universityId);
            $insert->execute();
            $action = 'added';
        }
        $connection->commit();
        return $action;
    } catch (Throwable $exception) {
        $connection->rollback();
        throw $exception;
    }
}
