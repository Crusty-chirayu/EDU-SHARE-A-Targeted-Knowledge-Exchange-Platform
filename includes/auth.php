<?php
declare(strict_types=1);

const APP_ROLES = ['student', 'teacher', 'moderator', 'admin'];

function reset_auth_cache(): void
{
    $GLOBALS['_auth_user_loaded'] = false;
    $GLOBALS['_auth_user'] = null;
}

function auth_user(): ?array
{
    if (($GLOBALS['_auth_user_loaded'] ?? false) === true) {
        return $GLOBALS['_auth_user'] ?? null;
    }

    $GLOBALS['_auth_user_loaded'] = true;
    $userId = positive_int($_SESSION['user_id'] ?? null);
    if ($userId === null) {
        $GLOBALS['_auth_user'] = null;
        return null;
    }

    $statement = db()->prepare(
        'SELECT id, full_name, gmail, user_type, university_id, department_id, branch, year, contact, address
         FROM users WHERE id = ? LIMIT 1'
    );
    $statement->bind_param('i', $userId);
    $statement->execute();
    $user = $statement->get_result()->fetch_assoc() ?: null;

    if ($user === null || !in_array($user['user_type'], APP_ROLES, true)) {
        unset($_SESSION['user_id'], $_SESSION['user_type'], $_SESSION['full_name']);
        $GLOBALS['_auth_user'] = null;
        return null;
    }

    $_SESSION['user_type'] = $user['user_type'];
    $_SESSION['full_name'] = $user['full_name'];
    $GLOBALS['_auth_user'] = $user;
    return $user;
}

function require_auth(bool $api = false): array
{
    $user = auth_user();
    if ($user !== null) {
        return $user;
    }

    if ($api || wants_json()) {
        abort_request(401, 'Authentication is required.');
    }

    flash('error', 'Please log in to continue.');
    redirect('login1.php', 302);
}

function role_can(?string $role, string $ability): bool
{
    $matrix = [
        'upload_material' => ['teacher', 'moderator', 'admin'],
        'manage_academics' => ['admin'],
        'moderate_materials' => ['moderator', 'admin'],
        'delete_any_material' => ['moderator', 'admin'],
    ];

    return $role !== null
        && isset($matrix[$ability])
        && in_array($role, $matrix[$ability], true);
}

function require_ability(string $ability, bool $api = false): array
{
    $user = require_auth($api);
    if (!role_can($user['user_type'], $ability)) {
        abort_request(403, 'You do not have permission to perform this action.');
    }
    return $user;
}

function can_view_material(array $material, ?array $user): bool
{
    $userId = $user === null ? null : (int) $user['id'];
    if ($userId !== null && $userId === (int) $material['user_id']) {
        return true;
    }

    $role = $user['user_type'] ?? null;
    if (role_can($role, 'moderate_materials')) {
        return true;
    }

    if (($material['status'] ?? 'published') !== 'published') {
        return false;
    }

    return match ($material['visibility'] ?? 'authenticated') {
        'public' => true,
        'authenticated' => $user !== null,
        default => false,
    };
}

function can_delete_material(array $material, array $user): bool
{
    return (int) $material['user_id'] === (int) $user['id']
        || role_can($user['user_type'] ?? null, 'delete_any_material');
}
