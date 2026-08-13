<?php
declare(strict_types=1);

function find_material(int $materialId): ?array
{
    $statement = db()->prepare(
        'SELECT id, user_id, title, description, file_path, original_filename, mime_type, file_size,
                checksum_sha256, university_id, department_id, course_id, subject_id, semester,
                visibility, status, upload_date
           FROM materials WHERE id = ? LIMIT 1'
    );
    $statement->bind_param('i', $materialId);
    $statement->execute();
    return $statement->get_result()->fetch_assoc() ?: null;
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

function toggle_material_favorite(int $userId, int $materialId): string
{
    $material = find_material($materialId);
    if ($material === null) {
        abort_request(404, 'Material not found.');
    }

    $user = auth_user();
    if ($user === null || (int) $user['id'] !== $userId || !can_view_material($material, $user)) {
        abort_request(403, 'You cannot favorite this material.');
    }

    $connection = db();
    $connection->begin_transaction();
    try {
        $check = $connection->prepare(
            'SELECT id FROM material_favorites WHERE user_id = ? AND material_id = ? FOR UPDATE'
        );
        $check->bind_param('ii', $userId, $materialId);
        $check->execute();
        $favorite = $check->get_result()->fetch_assoc();

        if ($favorite !== null) {
            $delete = $connection->prepare('DELETE FROM material_favorites WHERE user_id = ? AND material_id = ?');
            $delete->bind_param('ii', $userId, $materialId);
            $delete->execute();
            $action = 'removed';
        } else {
            $insert = $connection->prepare('INSERT INTO material_favorites (user_id, material_id) VALUES (?, ?)');
            $insert->bind_param('ii', $userId, $materialId);
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
